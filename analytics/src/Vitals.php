<?php

declare(strict_types=1);

namespace Cms\Analytics;

/**
 * How fast the site is for the people who visit it: the Core Web Vitals Google ranks pages by, measured in
 * their browsers. Each is the 75th percentile, as Google reads them, and rated by Google's thresholds:
 * Largest Contentful Paint (good up to 2.5 s, poor over 4 s), Interaction to Next Paint (200 ms, 500 ms)
 * and Cumulative Layout Shift (0.1, 0.25).
 */
final readonly class Vitals
{
    /** Metric => [good up to, poor over], as stored (milliseconds; layout shift times 1000). */
    public const array THRESHOLDS = ['lcp' => [2500, 4000], 'inp' => [200, 500], 'cls' => [100, 250]];

    /** Fewer measurements than this say too little about a page to rate it. */
    public const int ENOUGH = 10;

    /** Measurements read for one report at most, the most recent. */
    private const int LIMIT = 100_000;

    public function __construct(private Reports $reports) {}

    /**
     * @return array{site: array<string, mixed>, pages: list<array<string, mixed>>}
     */
    public function report(Range $range, Filters $filters, int $limit = 10): array
    {
        $rows = $this->reports->views($range, $filters)
            ->where(static fn ($q) => $q->whereNotNull('w.lcp')->orWhereNotNull('w.inp')->orWhereNotNull('w.cls'))
            ->orderByDesc('w.id')->limit(self::LIMIT)
            ->get(['w.path', 'w.lcp', 'w.inp', 'w.cls']);

        $byPage = [];

        foreach ($rows as $row) {
            foreach (array_keys(self::THRESHOLDS) as $metric) {
                $row->{$metric} !== null && $byPage[(string) $row->path][$metric][] = (int) $row->{$metric};
            }

            $byPage[(string) $row->path]['samples'] = ($byPage[(string) $row->path]['samples'] ?? 0) + 1;
        }

        $all = ['samples' => count($rows)];

        foreach (array_keys(self::THRESHOLDS) as $metric) {
            $all[$metric] = $rows->pluck($metric)->filter(static fn ($v) => $v !== null)->map(static fn ($v) => (int) $v)->values()->all();
        }

        $pages = [];

        foreach ($byPage as $path => $values) {
            $pages[] = ['key' => (string) $path, ...$this->reports->page((string) $path), ...self::rate($values)];
        }

        // The pages that need it most first: poor before good, then the slowest, then the most visited.
        $order = ['poor' => 0, 'needs-improvement' => 1, 'good' => 2, null => 3];
        usort($pages, static fn ($a, $b) => [$order[$a['rating']], -($a['lcp'] ?? 0), -$a['samples']] <=> [$order[$b['rating']], -($b['lcp'] ?? 0), -$b['samples']]);

        return ['site' => self::rate($all), 'pages' => array_slice($pages, 0, $limit)];
    }

    /**
     * Each metric's 75th percentile and rating, and the page's: its worst metric's, once there are enough measurements.
     *
     * @param  array{samples: int, lcp?: list<int>, inp?: list<int>, cls?: list<int>}  $values
     */
    private static function rate(array $values): array
    {
        $out = ['samples' => (int) $values['samples']];
        $ratings = [];

        foreach (self::THRESHOLDS as $metric => [$good, $poor]) {
            $p75 = self::percentile($values[$metric] ?? [], 0.75);
            $rating = $p75 === null ? null : ($p75 <= $good ? 'good' : ($p75 <= $poor ? 'needs-improvement' : 'poor'));
            // Layout shift is kept times 1000: 0.1 is stored as 100.
            $out[$metric] = $p75 === null ? null : ($metric === 'cls' ? round($p75 / 1000, 3) : $p75);
            $out[$metric.'Rating'] = $rating;
            $rating !== null && $ratings[] = $rating;
        }

        $out['rating'] = $out['samples'] < self::ENOUGH || $ratings === [] ? null : (in_array('poor', $ratings, true) ? 'poor' : (in_array('needs-improvement', $ratings, true) ? 'needs-improvement' : 'good'));

        return $out;
    }

    /** @param list<int> $values */
    private static function percentile(array $values, float $p): ?int
    {
        if ($values === []) {
            return null;
        }

        sort($values);

        return $values[(int) min(count($values) - 1, max(0, (int) ceil($p * count($values)) - 1))];
    }
}
