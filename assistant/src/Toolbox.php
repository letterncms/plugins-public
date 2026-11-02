<?php

declare(strict_types=1);

namespace Cms\Assistant;

use Cms\Abilities\Ability;
use Cms\Abilities\AbilityContext;
use Cms\Abilities\AbilityException;
use Cms\Admin\Surface;
use Cms\Cms;
use Cms\Support\Schema\SchemaException;

/**
 * Turns the abilities a user may run into Claude tools, and runs tool calls
 * back through the ability executor (validation, permissions, audit log).
 */
final class Toolbox
{
    public const string CONTEXT_TOOL = 'context_read';
    public const string UI_CATALOG = 'ui_catalog';
    public const string UI_PRESENT = 'ui_present';

    /** Longest tool result handed back to the model, in characters. */
    private const int LIMIT = 60_000;

    /** @var array<string, string> tool name => ability name */
    private array $map = [];

    public function __construct(private readonly Cms $cms, private readonly Surface $surfaces) {}

    /** @return list<array> Tool definitions, sorted so the cached prefix stays byte-identical. */
    public function definitions(AbilityContext $context): array
    {
        $tools = [];

        foreach ($this->cms->abilities()->available($context) as $ability) {
            $tools[] = $this->definition($ability);
        }

        if ($context->user) {
            $tools[] = [
                'name' => self::UI_CATALOG,
                'description' => 'Discover trusted UI components and their prop schemas. Existing plugin widgets are included automatically. Read before composing a UI. Names: '.implode(', ', array_column($this->surfaces->catalog($context->user), 'id')),
                'input_schema' => ['type' => 'object', 'properties' => (object) [], 'additionalProperties' => false],
                'eager_input_streaming' => true,
            ];
            $tools[] = [
                'name' => self::UI_PRESENT,
                'description' => 'Present a contextual interface using a json-render spec from ui_catalog. Use ui/stack and ui/grid to compose widgets, metrics, tables and actions. Each element uses type, props, children and optional on: {submit: {action: "namespace/ability", params: {...}}}. Actions run only when clicked, with normal permissions and approvals. Use real tool data; never invent metrics. Reuse an id to replace a surface in this reply.',
                'input_schema' => $this->surfaces->schema(),
                'eager_input_streaming' => true,
            ];
        }

        $resources = array_column($this->cms->context()->list($context->user), 'uri');

        if ($resources !== []) {
            sort($resources);
            $tools[] = [
                'name' => self::CONTEXT_TOOL,
                'description' => 'Read site context for planning: '.implode(', ', $resources).'. cms://collections has content types and field schemas, cms://site/design the design system (tokens, fonts, element styles), cms://blocks the block catalog.',
                'input_schema' => ['type' => 'object', 'properties' => ['uri' => ['type' => 'string', 'enum' => $resources]], 'required' => ['uri']],
                'eager_input_streaming' => true,
            ];
        }

        usort($tools, static fn ($a, $b) => strcmp($a['name'], $b['name']));

        return $tools;
    }

    public static function toolName(string $ability): string
    {
        return (string) preg_replace('/[^a-zA-Z0-9_-]/', '_', str_replace('/', '__', $ability));
    }

    public function ability(string $tool): ?Ability
    {
        $name = $this->map[$tool] ?? null;

        if ($name === null) {
            foreach ($this->cms->abilities()->all('assistant') as $ability) {
                $this->map[self::toolName($ability->name)] = $ability->name;
            }
            $name = $this->map[$tool] ?? null;
        }

        return $name ? $this->cms->abilities()->get($name) : null;
    }

    public function label(string $tool): string
    {
        return match ($tool) {
            self::CONTEXT_TOOL => __('Read site context'),
            self::UI_CATALOG => __('Discover components'),
            self::UI_PRESENT => __('Present interface'),
            default => __($this->ability($tool)?->label ?? $tool),
        };
    }

    /**
     * Whether a call waits for the user's go-ahead: always for destructive
     * actions and for admin-level ones kept away from external agents, and for
     * every change when the site asks for that. Once the chat has read content
     * that could carry instructions from someone else, for anything that reaches
     * visitors or can't be taken back. A draft does neither: nobody sees it until
     * it's published, which asks, and its history keeps what was there.
     */
    public function needsApproval(string $tool, string $mode, bool $untrusted = false, array $input = []): bool
    {
        $ability = $this->ability($tool);

        if (! $ability) {
            return false;
        }

        if ($ability->isReadOnly()) {
            // A read that reaches outside the site could carry data off it.
            return $untrusted && (bool) ($ability->annotations['openWorld'] ?? false);
        }

        if ($mode === 'writes' || $ability->isDestructive() || ! $ability->exposedTo('mcp')) {
            return true;
        }

        return $untrusted && ! $this->staysDraft($ability, $input);
    }

    /** A change an ability marks as `draft` stays one unless this call publishes or schedules it. */
    private function staysDraft(Ability $ability, array $input): bool
    {
        return ($ability->annotations['draft'] ?? false) === true
            && empty($input['publish'])
            && empty($input['at'])
            && ($input['status'] ?? 'draft') === 'draft';
    }

    /**
     * Whether a tool's output is free of text that people other than the
     * user could have written (entries, submissions, comments, logs, settings).
     * Only the assistant's own interface tools and abilities annotated
     * `trustedOutput` qualify; everything else counts as untrusted.
     */
    public function trusted(string $tool): bool
    {
        if (in_array($tool, [self::UI_CATALOG, self::UI_PRESENT], true)) {
            return true;
        }

        return (bool) ($this->ability($tool)?->annotations['trustedOutput'] ?? false);
    }

    /**
     * @return array{content: string, isError: bool, mutated: bool}
     */
    public function run(string $tool, mixed $input, AbilityContext $context): array
    {
        $input = is_array($input) ? $input : [];

        if (in_array($tool, [self::UI_CATALOG, self::UI_PRESENT], true) && $context->user) {
            try {
                $result = $tool === self::UI_CATALOG
                    ? $this->surfaces->catalog($context->user)
                    : ['surface' => $this->surfaces->validate($input, $context->user)];

                return ['content' => (string) json_encode($result, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE), 'isError' => false, 'mutated' => false];
            } catch (AbilityException|SchemaException $e) {
                return ['content' => $e->getMessage(), 'isError' => true, 'mutated' => false];
            }
        }

        if ($tool === self::CONTEXT_TOOL) {
            $resource = $this->cms->context()->read((string) ($input['uri'] ?? ''), $context->user);

            return $resource
                ? ['content' => $this->limit((string) ($resource['text'] ?? '')), 'isError' => false, 'mutated' => false]
                : ['content' => 'Unknown context URI.', 'isError' => true, 'mutated' => false];
        }

        $ability = $this->ability($tool);

        if (! $ability) {
            return ['content' => "There is no tool named {$tool}.", 'isError' => true, 'mutated' => false];
        }

        try {
            $result = $this->cms->abilities()->execute($ability->name, $input, $context);
        } catch (AbilityException $e) {
            $details = $e->details ? "\n".implode("\n", array_map(static fn ($k, $v) => "- {$k}: {$v}", array_keys($e->details), $e->details)) : '';

            return ['content' => $e->getMessage().$details, 'isError' => true, 'mutated' => false];
        }

        $text = is_string($result) ? $result : (string) json_encode($result, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE);

        return ['content' => $this->limit($text), 'isError' => false, 'mutated' => ! $ability->isReadOnly()];
    }

    /** Shared by live events, transcript replay and action resolution. Never parse the truncated display output. */
    public static function surface(string $tool, array $result): ?array
    {
        return $tool === self::UI_PRESENT && empty($result['isError'])
            ? (json_decode($result['content'], true)['surface'] ?? null)
            : null;
    }

    public static function actionPrefix(string $call): string
    {
        return 'toolu_ui_'.substr(hash('sha256', $call), 0, 24).'_';
    }

    private function definition(Ability $ability): array
    {
        $schema = $ability->input === [] ? ['type' => 'object'] : $ability->input;
        $schema['type'] = 'object';

        if (empty($schema['properties'])) {
            $schema['properties'] = (object) [];
        }

        $name = self::toolName($ability->name);
        $this->map[$name] = $ability->name;

        return [
            'name' => $name,
            'description' => trim($ability->label.'. '.$ability->description),
            'input_schema' => $schema,
            // Large inputs (page bodies, Markdown) stream as they are written; the executor validates them.
            'eager_input_streaming' => true,
        ];
    }

    private function limit(string $text): string
    {
        if (mb_strlen($text) <= self::LIMIT) {
            return $text;
        }

        $rest = mb_strlen($text) - self::LIMIT;

        return mb_substr($text, 0, self::LIMIT)."\n…[{$rest} more characters cut. Narrow the query, filter or paginate to see the rest.]";
    }
}
