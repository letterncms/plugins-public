<?php

declare(strict_types=1);

namespace Cms\Blocks;

use Illuminate\Support\Str;

/**
 * Stable ids for headings ("Getting started" → #getting-started), unique per
 * document. The renderer and cms_headings() walk blocks in the same order
 * with a fresh instance, so table-of-contents links always match.
 */
final class Anchors
{
    /** @var array<string, int> */
    private array $used = [];

    /** The id for a heading node: its own anchor if set, else one made from its text. */
    public function for(array $node): string
    {
        $explicit = (string) ($node['props']['anchor'] ?? $node['anchor'] ?? '');

        if ($explicit !== '') {
            $this->used[$explicit] = ($this->used[$explicit] ?? 0) + 1;

            return $explicit;
        }

        $base = Str::slug(html_entity_decode(strip_tags((string) ($node['props']['text'] ?? '')), ENT_QUOTES | ENT_HTML5)) ?: 'section';
        $base = preg_match('/^[a-z]/', $base) ? $base : 'h-'.$base;
        $count = $this->used[$base] = ($this->used[$base] ?? 0) + 1;

        return $count === 1 ? $base : $base.'-'.$count;
    }

    /**
     * Headings of a block tree, in reading order.
     *
     * @return list<array{level: int, text: string, id: string}>
     */
    public function headings(array $nodes, int $maxLevel = 3): array
    {
        $out = [];

        foreach ($nodes as $node) {
            if (! is_array($node) || ($node['hidden'] ?? false)) {
                continue;
            }

            if (($node['type'] ?? null) === 'heading') {
                $id = $this->for($node);
                $level = (int) ($node['props']['level'] ?? 2);

                if ($level >= 2 && $level <= $maxLevel) {
                    $out[] = ['level' => $level, 'text' => trim(html_entity_decode(strip_tags((string) ($node['props']['text'] ?? '')), ENT_QUOTES | ENT_HTML5)), 'id' => $id];
                }
            }

            array_push($out, ...$this->headings((array) ($node['children'] ?? []), $maxLevel));
        }

        return $out;
    }
}
