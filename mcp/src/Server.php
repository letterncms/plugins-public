<?php

declare(strict_types=1);

namespace Cms\Mcp;

use Cms\Support\Brand;
use Cms\Abilities\Ability;
use Cms\Abilities\AbilityContext;
use Cms\Abilities\AbilityException;
use Cms\Abilities\AgentGuide;
use Cms\Cms;
use Throwable;

/**
 * JSON-RPC handler for MCP. Stateless: every request is authorized and served
 * on its own. Speaks the modern revision (per-request _meta, server/discover)
 * and answers legacy `initialize` clients.
 */
final class Server
{
    public const string MODERN = '2026-07-28';

    public const array LEGACY = ['2025-11-25', '2025-06-18', '2025-03-26'];

    private const string META_VERSION = 'io.modelcontextprotocol/protocolVersion';

    public function __construct(private readonly Cms $cms, private readonly Prompts $prompts) {}

    /** @return array<int, string> */
    public function versions(): array
    {
        return [self::MODERN, ...self::LEGACY];
    }

    /**
     * Handle one JSON-RPC message. Returns null for notifications.
     *
     * @param  array<string, mixed>  $message
     */
    public function handle(array $message, AbilityContext $context, ?string $headerVersion): ?array
    {
        $id = $message['id'] ?? null;
        $method = (string) ($message['method'] ?? '');
        $params = (array) ($message['params'] ?? []);
        $isNotification = ! array_key_exists('id', $message);

        if (($message['jsonrpc'] ?? null) !== '2.0' || $method === '') {
            return $this->error($id, -32600, 'Invalid request.');
        }

        if ($isNotification) {
            return null;
        }

        $version = (string) ($params['_meta'][self::META_VERSION] ?? $headerVersion ?? '');
        $modern = $version !== '' && ! in_array($method, ['initialize', 'ping'], true);

        if ($modern && ! in_array($version, $this->versions(), true)) {
            return $this->error($id, -32022, 'Unsupported protocol version', ['supported' => $this->versions(), 'requested' => $version]);
        }

        try {
            $result = match ($method) {
                'initialize' => $this->initialize($params),
                'ping' => [],
                'server/discover' => $this->discover(),
                'tools/list' => $this->cacheable(['tools' => $this->tools($context)]),
                'tools/call' => $this->call($params, $context),
                'resources/list' => $this->cacheable(['resources' => $this->cms->context()->list($context->user)]),
                'resources/templates/list' => $this->cacheable(['resourceTemplates' => []]),
                'resources/read' => $this->read($params, $context),
                'prompts/list' => $this->cacheable(['prompts' => $this->prompts->list()]),
                'prompts/get' => $this->prompts->get((string) ($params['name'] ?? ''), (array) ($params['arguments'] ?? [])),
                default => throw new RpcError(-32601, "Method {$method} not found."),
            };
        } catch (RpcError $e) {
            return $this->error($id, $e->getCode(), $e->getMessage(), $e->data);
        } catch (Throwable $e) {
            report($e);

            return $this->error($id, -32603, 'Internal error.');
        }

        if ($modern || $method === 'server/discover') {
            $result['resultType'] ??= 'complete';
            $result['_meta'] = [...(array) ($result['_meta'] ?? []), 'io.modelcontextprotocol/serverInfo' => $this->serverInfo()];
        }

        return ['jsonrpc' => '2.0', 'id' => $id, 'result' => $result === [] ? (object) [] : $result];
    }

    public function instructions(): string
    {
        return $this->cms->app()->make(AgentGuide::class)->text()."\n\nTool names use a dot instead of the slash (entries/create is entries.create).";
    }

    private function initialize(array $params): array
    {
        $requested = (string) ($params['protocolVersion'] ?? '');

        return [
            'protocolVersion' => in_array($requested, self::LEGACY, true) ? $requested : self::LEGACY[0],
            'capabilities' => $this->capabilities(),
            'serverInfo' => $this->serverInfo(),
            'instructions' => $this->instructions(),
        ];
    }

    private function discover(): array
    {
        return [
            'supportedVersions' => $this->versions(),
            'capabilities' => $this->capabilities(),
            'serverInfo' => $this->serverInfo(),
            'instructions' => $this->instructions(),
        ];
    }

    private function capabilities(): array
    {
        return ['tools' => ['listChanged' => false], 'resources' => ['listChanged' => false], 'prompts' => ['listChanged' => false]];
    }

    private function serverInfo(): array
    {
        return ['name' => 'cms', 'title' => config('app.name').' ('.Brand::get().')', 'version' => Cms::VERSION, 'websiteUrl' => (string) config('app.url')];
    }

    private function cacheable(array $result): array
    {
        return [...$result, 'ttlMs' => 60_000, 'cacheScope' => 'private'];
    }

    /** @return list<array> */
    private function tools(AbilityContext $context): array
    {
        $tools = [];

        foreach ($this->cms->abilities()->available($context) as $ability) {
            $tools[] = $this->tool($ability);
        }

        usort($tools, static fn ($a, $b) => strcmp($a['name'], $b['name']));

        return $tools;
    }

    public static function toolName(string $ability): string
    {
        return str_replace('/', '.', $ability);
    }

    public static function abilityName(string $tool): string
    {
        return preg_replace('/\./', '/', $tool, 1) ?? $tool;
    }

    private function tool(Ability $ability): array
    {
        $schema = $ability->input === [] ? ['type' => 'object'] : $ability->input;
        $schema['type'] = 'object';

        if (empty($schema['properties'])) {
            $schema['properties'] = (object) [];
        }

        return array_filter([
            'name' => self::toolName($ability->name),
            'title' => $ability->label,
            'description' => $ability->description ?: $ability->label,
            'inputSchema' => $schema,
            'annotations' => array_filter([
                'title' => $ability->label,
                'readOnlyHint' => $ability->isReadOnly(),
                'destructiveHint' => $ability->asksFirst() ?: ($ability->isReadOnly() ? null : false),
                'idempotentHint' => ($ability->annotations['idempotent'] ?? null) ?: null,
                'openWorldHint' => false,
            ], static fn ($v) => $v !== null),
        ]);
    }

    private function call(array $params, AbilityContext $context): array
    {
        $name = self::abilityName((string) ($params['name'] ?? ''));
        $arguments = (array) ($params['arguments'] ?? []);

        try {
            $result = $this->cms->abilities()->execute($name, $arguments, $context);
        } catch (AbilityException $e) {
            if ($e->type === 'not_found' && ! $this->cms->abilities()->has($name)) {
                throw new RpcError(-32602, "Unknown tool {$params['name']}.");
            }

            $details = $e->details ? "\n".implode("\n", array_map(static fn ($k, $v) => "- {$k}: {$v}", array_keys($e->details), $e->details)) : '';

            return ['content' => [['type' => 'text', 'text' => $e->getMessage().$details]], 'isError' => true];
        }

        $text = is_string($result) ? $result : (string) json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);

        return array_filter([
            'content' => [['type' => 'text', 'text' => $text]],
            'structuredContent' => is_array($result) ? (array_is_list($result) ? ['items' => $result] : $result) : null,
            'isError' => false,
        ], static fn ($v) => $v !== null);
    }

    private function read(array $params, AbilityContext $context): array
    {
        $resource = $this->cms->context()->read((string) ($params['uri'] ?? ''), $context->system ? null : $context->user);

        if (! $resource) {
            throw new RpcError(-32602, 'Resource not found.', ['uri' => $params['uri'] ?? null]);
        }

        return $this->cacheable(['contents' => [$resource]]);
    }

    private function error(mixed $id, int $code, string $message, mixed $data = null): array
    {
        return ['jsonrpc' => '2.0', 'id' => $id, 'error' => array_filter(['code' => $code, 'message' => $message, 'data' => $data], static fn ($v) => $v !== null)];
    }
}
