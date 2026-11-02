<?php

declare(strict_types=1);

namespace Cms\Blocks;

use Cms\Content\Fields\Field;
use Cms\Content\Fields\FieldType;

/** The `blocks` field: one tree of blocks edited in the shared block workspace. */
final class BlocksField extends FieldType
{
    public function key(): string { return 'blocks'; }

    public function label(): string { return 'Blocks'; }

    public function icon(): string { return 'blocks'; }

    public function description(): string { return 'Rich page content built from blocks — text, media, layout and more.'; }

    public function group(): string { return 'Text'; }

    public function schema(Field $field): array
    {
        return [
            'type' => 'array',
            'description' => ($field->instructions ?: $field->label).'. A list of blocks: {type, props, style?, children?}. See blocks/types for the catalog and cms://blocks for examples.',
            'items' => [
                'type' => 'object',
                'properties' => [
                    'id' => ['type' => 'string'],
                    'type' => ['type' => 'string'],
                    'props' => ['type' => 'object'],
                    'style' => ['type' => 'object'],
                    'children' => ['type' => 'array'],
                ],
                'required' => ['type'],
            ],
        ];
    }

    public function normalize(mixed $value, Field $field): mixed
    {
        if (is_string($value)) {
            // Accept Block Markdown — handy for agents and imports.
            $value = app(MarkdownCodec::class)->decode($value);
        }

        // Blocks without an id get the one reading would give them, which writing leaves out again.
        return app(Document::class)->normalize(is_array($value) ? MarkdownCodec::stableIds($value) : $value);
    }

    public function defaultValue(Field $field): mixed
    {
        return is_array($field->default) ? $field->default : [];
    }

    public function augment(mixed $value, Field $field): mixed
    {
        return new BlocksDocument(is_array($value) ? $value : []);
    }

    /**
     * The blocks up to a Teaser Ends Here block or the word budget, in document order. A container
     * keeps what fits; a leaf that doesn't fit is dropped, never cut, and the rest with it.
     */
    public function teaser(mixed $value, Field $field, int $words): mixed
    {
        $budget = $words;
        $done = false;
        $count = static function (mixed $props) use (&$count): int {
            $n = 0;

            foreach ((array) $props as $prop) {
                $n += is_array($prop) ? $count($prop) : (is_string($prop) ? (int) preg_match_all('/[\p{L}\p{N}\']+/u', strip_tags($prop)) : 0);
            }

            return $n;
        };
        $walk = static function (array $nodes) use (&$walk, &$budget, &$done, $count): array {
            $kept = [];

            foreach ($nodes as $node) {
                if ($done || ($node['type'] ?? null) === 'gate-break') {
                    $done = true;
                    break;
                }

                if (! empty($node['children'])) {
                    $node['children'] = $walk((array) $node['children']);

                    if ($node['children'] !== []) {
                        $kept[] = $node;
                    }

                    continue;
                }

                $n = $count($node['props'] ?? []);

                if ($n > $budget) {
                    $done = true;
                    break;
                }

                $budget -= $n;
                $kept[] = $node;
            }

            return $kept;
        };

        return $walk(is_array($value) ? $value : []);
    }

    public function toText(mixed $value, Field $field): string
    {
        return is_array($value) ? app(Document::class)->text($value) : '';
    }

    public function serializesToMarkdown(): bool
    {
        return true;
    }

    public function toMarkdown(mixed $value, Field $field): string
    {
        return app(MarkdownCodec::class)->encode(is_array($value) ? $value : []);
    }

    public function fromMarkdown(string $markdown, Field $field): mixed
    {
        return MarkdownCodec::stableIds(app(MarkdownCodec::class)->decode($markdown));
    }

    public function configFields(): array
    {
        return [
            ['handle' => 'allowed', 'type' => 'tags', 'label' => 'Allowed Blocks', 'instructions' => 'Leave empty to allow every block.'],
        ];
    }
}
