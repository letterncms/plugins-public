<?php

declare(strict_types=1);

namespace Cms\Site\Rendering;

use Cms\Content\Entries\Entry;
use Cms\Content\Entries\EntryQuery;

/**
 * What templates get from cms_entries(): a query over live entries that
 * returns AugmentedEntry objects and records the dependency for builds.
 *
 *   @foreach (cms_entries('posts')->orderBy('-published_at')->limit(3)->get() as $post)
 */
final class EntryQueryProxy
{
    public function __construct(private EntryQuery $query) {}

    public function __call(string $method, array $args): mixed
    {
        $result = $this->query->{$method}(...$args);

        return $result instanceof EntryQuery ? $this : $result;
    }

    /** @return list<AugmentedEntry> */
    public function get(): array
    {
        $this->depend();

        return array_map(static fn (Entry $e) => new AugmentedEntry($e), $this->query->get());
    }

    public function first(): ?AugmentedEntry
    {
        $this->depend();
        $entry = $this->query->first();

        return $entry ? new AugmentedEntry($entry) : null;
    }

    public function count(): int
    {
        $this->depend();

        return $this->query->count();
    }

    /**
     * Paginate using the page number of the current URL (/blog/page/2).
     * The builder renders every page this reports.
     *
     * @return array{items: list<AugmentedEntry>, page: int, pages: int, total: int, prev: ?string, next: ?string}
     */
    public function paginate(int $perPage = 10): array
    {
        $this->depend();
        $context = PageContext::current();
        $page = $context?->page ?? 1;
        $result = $this->query->paginate($perPage, $page);

        if ($context) {
            $context->pages = max($context->pages, $result['pages']);
        }

        $base = rtrim($context?->uri ?? '/', '/');
        $link = static fn (int $p) => $p === 1 ? ($base === '' ? '/' : $base) : "{$base}/page/{$p}";

        return [
            'items' => array_map(static fn (Entry $e) => new AugmentedEntry($e), $result['items']),
            'page' => $result['page'],
            'pages' => $result['pages'],
            'total' => $result['total'],
            'prev' => $result['page'] > 1 ? $link($result['page'] - 1) : null,
            'next' => $result['page'] < $result['pages'] ? $link($result['page'] + 1) : null,
        ];
    }

    private function depend(): void
    {
        foreach ($this->query->collections as $handle) {
            PageContext::depend('collection:'.$handle);
        }
    }
}
