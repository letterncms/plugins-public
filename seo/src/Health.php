<?php

declare(strict_types=1);

namespace Cms\Seo;

use Cms\Content\Collections\Collection;
use Cms\Cms;

/**
 * The Analyzer run over the site: every published page (up to the 200 changed
 * last), cached for ten minutes and forgotten when an entry is saved or deleted.
 * Pages hidden from search engines are left out — their checks do not matter.
 */
final class Health
{
    public const string CACHE = 'seo.health';

    public const int LIMIT = 200;

    /** Pages scoring below this need attention. */
    public const int GOOD = 80;

    public function __construct(private readonly Cms $cms, private readonly Analyzer $analyzer) {}

    /** @return list<array{id: string, collection: string, locale: string, title: string, uri: ?string, score: int, issues: list<array{id: string, label: string, status: string}>}> */
    public function pages(): array
    {
        return cache()->remember(self::CACHE, 600, fn () => $this->analyze());
    }

    public function forget(): void
    {
        cache()->forget(self::CACHE);
    }

    /**
     * Average score, how many pages need attention, the most common issues and the
     * lowest-scoring pages, for the given pages (already filtered to what the reader may see).
     */
    public function summary(array $pages, int $limit = 5): array
    {
        $issues = [];

        foreach ($pages as $page) {
            foreach ($page['issues'] as $issue) {
                $issues[$issue['id']] ??= ['id' => $issue['id'], 'label' => Analyzer::label($issue['id']), 'pages' => 0, 'failing' => 0];
                $issues[$issue['id']]['pages']++;
                $issue['status'] === 'fail' && $issues[$issue['id']]['failing']++;
            }
        }

        usort($issues, static fn (array $a, array $b) => [$b['failing'] > 0, $b['pages']] <=> [$a['failing'] > 0, $a['pages']]);
        $worst = array_filter($pages, static fn (array $p) => $p['score'] < self::GOOD);
        usort($worst, static fn (array $a, array $b) => $a['score'] <=> $b['score']);

        return [
            'pages' => count($pages),
            'score' => $pages === [] ? null : (int) round(array_sum(array_column($pages, 'score')) / count($pages)),
            'attention' => count($worst),
            'good' => self::GOOD,
            'issues' => array_slice($issues, 0, 4),
            'worst' => array_map(static fn (array $p) => [
                'id' => $p['id'],
                'collection' => $p['collection'],
                'locale' => $p['locale'],
                'title' => $p['title'],
                'uri' => $p['uri'],
                'score' => $p['score'],
                'issues' => count($p['issues']),
                'top' => isset($p['issues'][0]) ? Analyzer::label($p['issues'][0]['id']) : null,
            ], array_slice($worst, 0, $limit)),
        ];
    }

    private function analyze(): array
    {
        $handles = array_keys(array_filter($this->cms->collections()->all(), static fn (Collection $c) => $c->routable()));

        if ($handles === []) {
            return [];
        }

        $pages = [];

        foreach ($this->cms->entries()->query()->ungated()->collection(...$handles)->live()->orderBy('-updated_at')->limit(self::LIMIT)->get() as $entry) {
            if (! empty($entry->data['seo']['noindex'])) {
                continue;
            }

            $result = $this->analyzer->analyze($entry);
            $issues = array_values(array_filter($result['checks'], static fn (array $c) => $c['status'] !== 'pass'));
            // Failures first: they say most about what to fix.
            usort($issues, static fn (array $a, array $b) => ($a['status'] === 'fail' ? 0 : 1) <=> ($b['status'] === 'fail' ? 0 : 1));

            $pages[] = [
                'id' => $entry->id,
                'collection' => $entry->collection,
                'locale' => $entry->locale,
                'title' => $entry->title,
                'uri' => $entry->uri,
                'score' => $result['score'],
                'issues' => array_map(static fn (array $c) => ['id' => $c['id'], 'label' => $c['label'], 'status' => $c['status']], $issues),
            ];
        }

        return $pages;
    }
}
