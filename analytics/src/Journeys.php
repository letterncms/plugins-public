<?php

declare(strict_types=1);

namespace Cms\Analytics;

use Illuminate\Support\Facades\DB;
use Cms\Abilities\AbilityException;

/**
 * How visits move through the site: funnels (how many visitors took each step, in order, within one
 * visit) and paths (the pages visits go through most, and what comes before and after one page).
 * Visits are read one at a time, in order, so a long period never holds everything at once.
 */
final readonly class Journeys
{
    /** Visits read for one report at most: enough for any site this runs, and a limit for the rest. */
    private const int VISITS = 200_000;

    public function __construct(private Reports $reports, private Goals $goals) {}

    /**
     * @param  list<string>  $steps  each a page (`/pricing`, `/blog/*`) or a goal's name
     * @return array{name: ?string, steps: list<array{label: string, page: ?string, visitors: int, share: float, dropoff: float}>, conversionRate: float}
     */
    public function funnel(Range $range, Filters $filters, ?string $name = null, array $steps = []): array
    {
        if ($name !== null) {
            $steps = $this->goals->funnels()[$name] ?? throw new AbilityException('There is no funnel of that name in the settings.', 'not_found');
        }

        $steps = array_values(array_slice(array_filter(array_map('trim', $steps), static fn ($s) => $s !== ''), 0, 8));

        if (count($steps) < 2) {
            throw AbilityException::invalid('A funnel has two steps at least: pages (starting with /) or goals by their names.');
        }

        $matchers = array_map(fn (string $step) => $this->matcher($step), $steps);
        $reached = array_fill(0, count($steps), []);

        $this->walk($range, $filters, function (string $visitor, array $moments) use ($matchers, &$reached): void {
            $step = 0;

            foreach ($moments as $moment) {
                if ($step < count($matchers) && $matchers[$step]($moment)) {
                    $reached[$step][$visitor] = true;
                    $step++;
                }
            }
        });

        $first = count($reached[0]);
        $out = [];

        foreach ($steps as $i => $step) {
            $visitors = count($reached[$i]);
            $before = $i === 0 ? $visitors : count($reached[$i - 1]);
            $out[] = [
                'label' => $step,
                'page' => $this->goals->path($step),
                'visitors' => $visitors,
                'share' => $first > 0 ? round($visitors / $first, 4) : 0.0,
                'dropoff' => $before > 0 ? round(1 - $visitors / $before, 4) : 0.0,
            ];
        }

        return ['name' => $name, 'steps' => $out, 'conversionRate' => $first > 0 ? round(count($reached[count($steps) - 1]) / $first, 4) : 0.0];
    }

    /**
     * The paths visits take most, page by page (a page reloaded counts once), `length` pages long at most;
     * or, given a page, where visitors were just before it and where they went next.
     */
    public function paths(Range $range, Filters $filters, int $length = 3, int $limit = 10, ?string $page = null): array
    {
        $paths = [];
        $before = [];
        $after = [];
        $page = $page === null ? null : Collector::path($page);

        $this->walk($range, $filters, function (string $visitor, array $moments) use ($length, $page, &$paths, &$before, &$after): void {
            $pages = [];

            foreach ($moments as $moment) {
                if ($moment['kind'] === 'page' && end($pages) !== $moment['value']) {
                    $pages[] = $moment['value'];
                }
            }

            if ($page === null) {
                // A path goes somewhere: a visit of one page is a bounce, not a path.
                count($pages) >= 2 && $paths[implode("\n", array_slice($pages, 0, $length))][$visitor] = true;

                return;
            }

            foreach ($pages as $i => $path) {
                if ($path === $page) {
                    $before[$pages[$i - 1] ?? ''][$visitor] = true;
                    $after[$pages[$i + 1] ?? ''][$visitor] = true;
                }
            }
        }, pagesOnly: true);

        $rank = function (array $counts, bool $sequence = false) use ($limit): array {
            $rows = [];

            foreach ($counts as $key => $visitors) {
                $rows[] = $sequence
                    ? ['pages' => array_map(fn (string $p) => ['path' => $p, 'label' => $this->reports->page($p)['label']], explode("\n", (string) $key)), 'visitors' => count($visitors)]
                    : ['key' => $key === '' ? null : (string) $key, ...($key === '' ? ['label' => '', 'path' => null] : $this->reports->page((string) $key)), 'visitors' => count($visitors)];
            }

            usort($rows, static fn ($a, $b) => $b['visitors'] <=> $a['visitors']);

            return array_slice($rows, 0, $limit);
        };

        return $page === null
            ? ['page' => null, 'paths' => $rank($paths, true)]
            : ['page' => $page, 'before' => $rank($before), 'after' => $rank($after)];
    }

    /** @return callable(array{kind: string, value: string}): bool */
    private function matcher(string $step): callable
    {
        $path = $this->goals->path($step);

        if ($path === null) {
            return static fn (array $m) => $m['kind'] === 'event' && $m['value'] === $step;
        }

        $pattern = str_contains($path, '*') ? '#^'.str_replace('\*', '.*', preg_quote($path, '#')).'$#u' : null;

        return static fn (array $m) => $m['kind'] === 'page' && ($pattern ? preg_match($pattern, $m['value']) === 1 : $m['value'] === $path);
    }

    /**
     * Each visit of the range the filters keep, as what happened in it in order: its page views (and events).
     *
     * @param  callable(string, list<array{kind: string, value: string}>): void  $each  the visitor and the visit's moments
     */
    private function walk(Range $range, Filters $filters, callable $each, bool $pagesOnly = false): void
    {
        $visits = $this->reports->visits($range, $filters)->orderBy('v.id')->limit(self::VISITS)->select('v.id');
        // Joined, not `in`: MySQL takes no limit in an `in` subquery.
        $moments = DB::table('analytics_views as m')->joinSub($visits, 'fv', 'fv.id', '=', 'm.visit_id')->join('analytics_visits as mv', 'mv.id', '=', 'm.visit_id')
            ->selectRaw("m.visit_id, mv.visitor, m.at, m.id, 'page' as kind, m.path as value");

        if (! $pagesOnly) {
            $moments->unionAll(DB::table('analytics_events as m')->joinSub($visits, 'fv', 'fv.id', '=', 'm.visit_id')->join('analytics_visits as mv', 'mv.id', '=', 'm.visit_id')
                ->selectRaw("m.visit_id, mv.visitor, m.at, m.id, 'event' as kind, m.name as value"));
        }

        $current = null;
        $visitor = '';
        $list = [];

        foreach (DB::query()->fromSub($moments, 'all_moments')->orderBy('visit_id')->orderBy('at')->orderBy('kind', 'desc')->orderBy('id')->cursor() as $row) {
            if ($row->visit_id !== $current) {
                $current !== null && $each($visitor, $list);
                [$current, $visitor, $list] = [$row->visit_id, (string) $row->visitor, []];
            }

            $list[] = ['kind' => (string) $row->kind, 'value' => (string) $row->value];
        }

        $current !== null && $each($visitor, $list);
    }
}
