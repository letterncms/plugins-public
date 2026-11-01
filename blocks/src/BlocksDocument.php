<?php

declare(strict_types=1);

namespace Cms\Blocks;

use Countable;
use Illuminate\Contracts\Support\Htmlable;
use Stringable;

/** What templates get for a blocks field: echo it to render (`{{ $entry->content }}`). */
final class BlocksDocument implements Htmlable, Stringable, Countable
{
    /** @param list<array> $nodes */
    public function __construct(public readonly array $nodes) {}

    public function toHtml(): string
    {
        return app(BlockRenderer::class)->render($this->nodes);
    }

    public function __toString(): string
    {
        return $this->toHtml();
    }

    public function count(): int
    {
        return count($this->nodes);
    }

    public function isEmpty(): bool
    {
        return $this->nodes === [];
    }

    /** First block of a type, e.g. the lead image. */
    public function first(string $type): ?array
    {
        $found = null;

        $walk = function (array $nodes) use (&$walk, &$found, $type): void {
            foreach ($nodes as $node) {
                if ($found) {
                    return;
                }

                if (($node['type'] ?? null) === $type) {
                    $found = $node;

                    return;
                }

                $walk((array) ($node['children'] ?? []));
            }
        };

        $walk($this->nodes);

        return $found;
    }

    /**
     * Table of contents: [{level, text, id}] for h2…h$maxLevel. The ids match
     * the ones the rendered headings get.
     *
     * @return list<array{level: int, text: string, id: string}>
     */
    public function headings(int $maxLevel = 3): array
    {
        return (new Anchors)->headings($this->nodes, $maxLevel);
    }

    public function text(): string
    {
        return app(Document::class)->text($this->nodes);
    }
}
