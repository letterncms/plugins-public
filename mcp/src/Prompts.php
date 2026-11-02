<?php

declare(strict_types=1);

namespace Cms\Mcp;

use Cms\Cms;

/** The CMS's skills, as MCP prompts. */
final class Prompts
{
    public function __construct(private readonly Cms $cms) {}

    /** @return list<array> */
    public function list(): array
    {
        return array_values(array_map(static fn ($p) => array_diff_key($p, ['template' => 1]), $this->all()));
    }

    public function get(string $name, array $arguments): array
    {
        $prompt = $this->all()[$name] ?? throw new RpcError(-32602, "Unknown prompt {$name}.");

        foreach ($prompt['arguments'] as $arg) {
            if (($arg['required'] ?? false) && trim((string) ($arguments[$arg['name']] ?? '')) === '') {
                throw new RpcError(-32602, "Missing argument {$arg['name']}.");
            }
        }

        $text = preg_replace_callback('/\{(\w+)\}/', static fn ($m) => (string) ($arguments[$m[1]] ?? ''), $prompt['template']) ?? '';

        return ['description' => $prompt['description'], 'messages' => [['role' => 'user', 'content' => ['type' => 'text', 'text' => trim($text)]]]];
    }

    /** Every skill registered with `cms()->skills()` is a prompt. @return array<string, array{name: string, title: string, description: string, arguments: list<array>, template: string}> */
    private function all(): array
    {
        $prompts = array_map(static fn (array $skill) => [
            'name' => $skill['name'],
            'title' => $skill['label'],
            'description' => $skill['description'],
            'arguments' => $skill['arguments'],
            'template' => $skill['prompt'],
        ], $this->cms->skills()->all());

        return $this->cms->hooks()->filter('mcp.prompts', $prompts);
    }
}
