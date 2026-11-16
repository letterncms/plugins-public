<?php

declare(strict_types=1);

namespace Cms\Analytics;

use Carbon\CarbonImmutable;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;
use Cms\Cms;
use Cms\Modules\Languages\Languages;

/**
 * Reads the numbers back: a period's headline numbers with the period before and a row per step, and
 * breakdowns by any dimension, each narrowed by any filter. Visitors mode reads visits, page views and
 * events; counts mode only has page views per day and path, and what it can't know is null, never zero.
 */
final class Reports
{
    public const array DIMENSIONS = ['pages', 'entries', 'exits', 'languages', 'channels', 'sources', 'campaigns', 'countries', 'devices', 'browsers', 'os', 'goals', 'props'];

    /** What can still be told with page views alone (and the events plugins record). */
    private const array COUNTS_DIMENSIONS = ['pages', 'languages', 'goals'];

    /** Dimension => the visit's column, for those that are one. */
    private const array VISIT_COLUMNS = ['entries' => 'entry', 'exits' => 'exit', 'channels' => 'channel', 'sources' => 'source', 'campaigns' => 'campaign', 'countries' => 'country', 'devices' => 'device', 'browsers' => 'browser', 'os' => 'os'];

    /** Visitors on the site now: something happened in the last five minutes. */
    public const int CURRENT_MINUTES = 5;

    public function __construct(private readonly Cms $cms, private readonly Goals $goals) {}

    public function counting(): bool
    {
        return $this->cms->setting(Collector::SETTINGS, 'mode', Collector::COUNTS) !== Collector::VISITORS;
    }

    public function enabled(): bool
    {
        return (bool) $this->cms->setting(Collector::SETTINGS, 'enabled', true);
    }

    /** Whether anything was ever counted in the current mode. */
    public function tracked(): bool
    {
        return DB::table($this->counting() ? 'analytics_pages' : 'analytics_visits')->exists();
    }

    /** People on the site now, or null in counts mode, which can't tell. */
    public function current(): ?int
    {
        return $this->counting() ? null : DB::table('analytics_visits')->where('last_at', '>=', CarbonImmutable::now('UTC')->subMinutes(self::CURRENT_MINUTES)->toDateTimeString())->count(DB::raw('distinct visitor'));
    }

    /**
     * The headline numbers for a period and filters, the same for the period before, and one row per step.
     * With a goal filter, also conversions (visitors who reached it) and the conversion rate.
     */
    public function summary(Range $range, Filters $filters, bool $compare = false): array
    {
        $counting = $this->counting();
        $unavailable = $counting && (! $filters->pagesOnly() || $range->since !== null);
        $totals = fn (Range $r) => $unavailable ? self::metrics() : ($counting ? $this->countsTotals($r, $filters) : $this->totals($r, $filters));

        return [
            'period' => $range->period,
            'from' => $range->from->toDateString(),
            'to' => $range->to->toDateString(),
            'interval' => $range->interval,
            'mode' => $counting ? Collector::COUNTS : Collector::VISITORS,
            'filters' => $filters->toArray(),
            ...$totals($range),
            'previous' => $totals($range->previous()),
            'series' => $unavailable ? [] : ($counting ? $this->countsSeries($range, $filters) : $this->series($range, $filters)),
            // The period before, step by step, to draw beside it: step one beside step one.
            ...($compare ? ['previousSeries' => $unavailable ? [] : ($counting ? $this->countsSeries($range->previous(), $filters) : $this->series($range->previous(), $filters))] : []),
            'current' => $this->current(),
            'enabled' => $this->enabled(),
            'tracked' => $this->tracked(),
            ...($unavailable ? ['unavailable' => 'mode'] : []),
        ];
    }

    /** @return array<string, int|float|null> */
    private function totals(Range $range, Filters $filters): array
    {
        $visits = $this->visitRow($this->visits($range, $filters));
        $views = $this->viewRow($this->views($range, $filters));
        $metrics = self::metrics($visits, $views);

        if ($filters->has('goal')) {
            $all = (int) $this->visits($range, $filters->without('goal'))->count(DB::raw('distinct v.visitor'));
            $metrics['conversions'] = $metrics['visitors'];
            $metrics['conversionRate'] = $all > 0 ? round($metrics['visitors'] / $all, 4) : 0.0;
        }

        return $metrics;
    }

    /** @return list<array<string, mixed>> one row per step, every step there, with nothing in it as zero */
    private function series(Range $range, Filters $filters): array
    {
        $byStep = [];
        $minute = $range->interval === 'minute';
        // Visitors are told apart within a step: by hour for hours, else by day (a day's visitor is the same all day).
        $hourly = $range->interval === 'hour';

        if ($minute) {
            // Half an hour of a site's visits: few enough to sort into minutes here.
            foreach ($this->visits($range, $filters)->get(['v.visitor', 'v.views', 'v.events', 'v.engaged', 'v.last_at']) as $visit) {
                $key = $range->key(Collector::local(CarbonImmutable::parse((string) $visit->last_at, 'UTC')));
                $byStep[$key]['visits'][] = $visit;
            }

            foreach ($this->views($range, $filters)->get(['w.at', 'w.engaged', 'w.scroll']) as $view) {
                $key = $range->key(Collector::local(CarbonImmutable::parse((string) $view->at, 'UTC')));
                $byStep[$key]['views'][] = $view;
            }

            $rows = [];

            foreach ($range->steps() as $step) {
                $visits = collect($byStep[$step]['visits'] ?? []);
                $views = collect($byStep[$step]['views'] ?? []);
                $rows[] = ['date' => $step, ...self::metrics(
                    ['visits' => $visits->count(), 'visitors' => $visits->pluck('visitor')->unique()->count(), 'pages' => (int) $visits->sum('views'), 'bounces' => $visits->filter(static fn ($v) => (int) $v->views === 1 && (int) $v->events === 0)->count(), 'engaged' => (int) $visits->sum('engaged')],
                    ['views' => $views->count(), 'engaged' => (int) $views->sum('engaged'), 'timed' => $views->whereNotNull('engaged')->count(), 'scroll' => (int) $views->sum('scroll'), 'scrolled' => $views->whereNotNull('scroll')->count()],
                )];
            }

            return $rows;
        }

        $visitSteps = $hourly ? ['v.day', 'v.hour'] : ['v.day'];
        $viewSteps = $hourly ? ['w.day', 'w.hour'] : ['w.day'];

        foreach ($this->visits($range, $filters)->groupBy(...$visitSteps)->select($visitSteps)->selectRaw(self::VISIT_SUMS)->get() as $row) {
            self::add($byStep[$range->key((string) $row->day, (int) ($row->hour ?? 0))]['visits'], (array) $row);
        }

        foreach ($this->views($range, $filters)->groupBy(...$viewSteps)->select($viewSteps)->selectRaw(self::VIEW_SUMS)->get() as $row) {
            self::add($byStep[$range->key((string) $row->day, (int) ($row->hour ?? 0))]['views'], (array) $row);
        }

        return array_map(static fn (string $step) => ['date' => $step, ...self::metrics($byStep[$step]['visits'] ?? [], $byStep[$step]['views'] ?? [])], $range->steps());
    }

    private const string VISIT_SUMS = 'count(*) as visits, count(distinct v.visitor) as visitors, coalesce(sum(v.views), 0) as pages, coalesce(sum(case when v.views = 1 and v.events = 0 then 1 else 0 end), 0) as bounces, coalesce(sum(v.engaged), 0) as engaged';

    private const string VIEW_SUMS = 'count(*) as views, coalesce(sum(w.engaged), 0) as engaged, count(w.engaged) as timed, coalesce(sum(w.scroll), 0) as scroll, count(w.scroll) as scrolled';

    /** Counts mode: page views from the daily totals, narrowed to pages only. */
    private function countsTotals(Range $range, Filters $filters): array
    {
        $views = (int) $filters->views(DB::table('analytics_pages as w')->whereBetween('w.day', $range->dates()))->sum('w.views');

        return self::metrics(null, ['views' => $views]);
    }

    private function countsSeries(Range $range, Filters $filters): array
    {
        $days = [];

        foreach ($filters->views(DB::table('analytics_pages as w')->whereBetween('w.day', $range->dates()))->groupBy('w.day')->selectRaw('w.day, sum(w.views) as views')->get() as $row) {
            $key = $range->key((string) $row->day);
            $days[$key] = ($days[$key] ?? 0) + (int) $row->views;
        }

        // Days are all counts mode knows: today and yesterday are one step each.
        $steps = in_array($range->interval, ['minute', 'hour'], true) ? [$range->from->toDateString()] : $range->steps();

        return array_map(static fn (string $step) => ['date' => $step, ...self::metrics(null, ['views' => $days[$step] ?? 0])], $steps);
    }

    /**
     * The visitors (and what fits the dimension) per value of a dimension, most first, each with its share of
     * all visitors. `props` are the properties of the events of the goal filtered on (`url: https://…`).
     *
     * @return array{dimension: string, total: int, items: list<array<string, mixed>>, unavailable?: string}
     */
    public function breakdown(Range $range, Filters $filters, string $dimension, int $limit = 10): array
    {
        $counting = $this->counting();

        if ($counting && (! in_array($dimension, self::COUNTS_DIMENSIONS, true) || ! $filters->pagesOnly() || $range->since !== null)) {
            return ['dimension' => $dimension, 'total' => 0, 'items' => [], 'unavailable' => 'mode'];
        }

        if ($dimension === 'props' && $filters->only('goal') === []) {
            return ['dimension' => $dimension, 'total' => 0, 'items' => [], 'unavailable' => 'goal'];
        }

        $total = $counting
            ? (int) $filters->views(DB::table('analytics_pages as w')->whereBetween('w.day', $range->dates()))->sum('w.views')
            : (int) $this->visits($range, $filters->without('goal'))->count(DB::raw('distinct v.visitor'));

        $items = match (true) {
            $dimension === 'languages' => $this->languages($range, $filters, $limit, $counting),
            $dimension === 'goals' => $this->goalRows($range, $filters, $limit, $counting),
            $dimension === 'props' => $this->props($range, $filters, $limit),
            $dimension === 'pages' => $counting ? $this->countsPages($range, $filters, $limit) : $this->pages($range, $filters, $limit),
            default => $this->byVisit($range, $filters, $dimension, $limit),
        };

        $site = $this->cms->url();
        $kinds = ['desktop' => 'desktop', 'mobile' => 'phone', 'tablet' => 'tablet'];

        return ['dimension' => $dimension, 'total' => $total, 'items' => array_map(static function (array $item) use ($total, $site, $dimension, $kinds) {
            $count = $item['visitors'] ?? $item['views'] ?? $item['events'] ?? 0;

            return [...$item,
                'share' => $total > 0 ? round(min(1, $count / $total), 4) : 0,
                ...(isset($item['path']) && in_array($dimension, ['pages', 'entries', 'exits'], true) ? ['url' => $site.$item['path']] : []),
                ...($dimension === 'devices' && isset($kinds[$item['key'] ?? '']) ? ['icon' => $kinds[$item['key']]] : []),
            ];
        }, $items)];
    }

    private function pages(Range $range, Filters $filters, int $limit): array
    {
        $rows = $this->views($range, $filters)->groupBy('w.path')
            ->selectRaw('w.path, count(distinct v.visitor) as visitors, '.self::VIEW_SUMS)
            ->orderByDesc('visitors')->orderByDesc('views')->orderBy('w.path')->limit($limit)->get();

        return $rows->map(fn ($row) => [
            'key' => (string) $row->path,
            ...$this->page((string) $row->path),
            'visitors' => (int) $row->visitors,
            'views' => (int) $row->views,
            ...self::engagement((array) $row),
        ])->all();
    }

    private function countsPages(Range $range, Filters $filters, int $limit): array
    {
        $rows = $filters->views(DB::table('analytics_pages as w')->whereBetween('w.day', $range->dates()))
            ->selectRaw('w.path, sum(w.views) as views')->groupBy('w.path')->orderByDesc('views')->orderBy('w.path')->limit($limit)->get();

        return $rows->map(fn ($row) => ['key' => (string) $row->path, ...$this->page((string) $row->path), 'visitors' => null, 'views' => (int) $row->views])->all();
    }

    /** A dimension of the visit itself: where it started and ended, came from, and on what. */
    private function byVisit(Range $range, Filters $filters, string $dimension, int $limit): array
    {
        $column = 'v.'.self::VISIT_COLUMNS[$dimension];
        $query = $this->visits($range, $filters);
        $dimension === 'campaigns' && $query->whereNotNull($column);
        $rows = $query->groupBy($column)->selectRaw("{$column} as value, ".self::VISIT_SUMS)->orderByDesc('visitors')->orderBy($column)->limit($limit)->get();

        return $rows->map(function ($row) use ($dimension) {
            $value = $row->value === null ? null : (string) $row->value;
            $metrics = self::metrics((array) $row);

            return [
                'key' => $value,
                'label' => $value ?? ['sources' => 'Direct', 'countries' => 'Unknown'][$dimension] ?? 'None',
                ...(in_array($dimension, ['entries', 'exits'], true) && $value !== null ? $this->page($value) : []),
                'visitors' => $metrics['visitors'],
                'visits' => $metrics['visits'],
                'bounceRate' => $metrics['bounceRate'],
                'visitDuration' => $metrics['visitDuration'],
            ];
        })->all();
    }

    /** Every event by its name, and every Goal Page: who reached it, how often, and the share of visitors that did. */
    private function goalRows(Range $range, Filters $filters, int $limit, bool $counting): array
    {
        $rows = [];

        if ($counting) {
            foreach (DB::table('analytics_events as e')->whereBetween('e.day', $range->dates())->groupBy('e.name')->selectRaw('e.name, count(*) as events')->get() as $row) {
                $rows[] = ['key' => (string) $row->name, 'label' => (string) $row->name, 'kind' => 'event', 'visitors' => null, 'events' => (int) $row->events, 'conversionRate' => null];
            }

            foreach ($this->goals->pages() as $name => $path) {
                $views = (int) Filters::compare(DB::table('analytics_pages as w')->whereBetween('w.day', $range->dates()), 'w.path', 'is', $path)->sum('w.views');
                $views > 0 && $rows[] = ['key' => $name, 'label' => $name, 'kind' => 'page', 'path' => $path, 'visitors' => null, 'events' => $views, 'conversionRate' => null];
            }

            usort($rows, static fn ($a, $b) => [$b['events'], $a['label']] <=> [$a['events'], $b['label']]);

            return array_slice($rows, 0, $limit);
        }

        $all = (int) $this->visits($range, $filters->without('goal'))->count(DB::raw('distinct v.visitor'));
        $visits = $this->visits($range, $filters)->select('v.id');
        $rate = static fn (?int $visitors) => $visitors === null ? null : ($all > 0 ? round($visitors / $all, 4) : 0.0);

        // The snippet's events, by the visitors whose visit they were in. A plugin's, outside visits, add to how often only.
        $inVisits = DB::table('analytics_events as e')->join('analytics_visits as v', 'v.id', '=', 'e.visit_id')->whereIn('e.visit_id', $visits)
            ->groupBy('e.name')->selectRaw('e.name, count(distinct v.visitor) as visitors, count(*) as events')->get()->keyBy('name');
        $outside = $filters->all === []
            ? DB::table('analytics_events as e')->whereNull('e.visit_id')->whereBetween('e.day', $range->dates())->groupBy('e.name')->selectRaw('e.name, count(*) as events')->get()->keyBy('name')
            : collect();

        foreach ($inVisits->keys()->merge($outside->keys())->unique() as $name) {
            $visitors = isset($inVisits[$name]) ? (int) $inVisits[$name]->visitors : null;
            $rows[] = ['key' => (string) $name, 'label' => (string) $name, 'kind' => 'event', 'visitors' => $visitors, 'events' => (int) ($inVisits[$name]->events ?? 0) + (int) ($outside[$name]->events ?? 0), 'conversionRate' => $rate($visitors)];
        }

        foreach ($this->goals->pages() as $name => $path) {
            $row = Filters::compare($this->views($range, $filters), 'w.path', 'is', $path)->selectRaw('count(distinct v.visitor) as visitors, count(*) as views')->first();

            if ((int) $row->views > 0) {
                $rows[] = ['key' => $name, 'label' => $name, 'kind' => 'page', 'path' => $path, 'visitors' => (int) $row->visitors, 'events' => (int) $row->views, 'conversionRate' => $rate((int) $row->visitors)];
            }
        }

        usort($rows, static fn ($a, $b) => [$b['visitors'] ?? -1, $b['events'], $a['label']] <=> [$a['visitors'] ?? -1, $a['events'], $b['label']]);

        return array_slice($rows, 0, $limit);
    }

    /** The properties of a goal's events, each value as a row: `url` https://github.com/…, `form` contact. */
    private function props(Range $range, Filters $filters, int $limit): array
    {
        $goal = $filters->only('goal')[0]['value'];
        $counts = [];
        $visits = $this->visits($range, $filters)->select('v.id');
        $events = DB::table('analytics_events as e')->leftJoin('analytics_visits as v', 'v.id', '=', 'e.visit_id')->where('e.name', (string) $goal)->whereNotNull('e.props')
            ->where(static fn (Builder $q) => $q->whereIn('e.visit_id', $visits)->orWhere(static fn (Builder $q) => $q->whereNull('e.visit_id')->whereBetween('e.day', $range->dates())))
            ->orderByDesc('e.id')->limit(50_000);

        foreach ($events->get(['e.props', 'v.visitor']) as $event) {
            foreach ((array) json_decode((string) $event->props, true) as $key => $value) {
                $id = $key."\0".$value;
                $counts[$id] ??= ['key' => $id, 'label' => (string) $value, 'prop' => (string) $key, 'visitors' => [], 'events' => 0];
                $counts[$id]['events']++;
                $event->visitor !== null && $counts[$id]['visitors'][$event->visitor] = true;
            }
        }

        $rows = array_map(static fn (array $row) => [...$row, 'visitors' => count($row['visitors'])], array_values($counts));
        usort($rows, static fn ($a, $b) => [$b['visitors'], $b['events'], $a['label']] <=> [$a['visitors'], $a['events'], $b['label']]);

        return array_slice($rows, 0, $limit);
    }

    /** Page views per language, by the path's prefix: the Languages module's, so /pt-br/… counts for pt-BR. */
    private function languages(Range $range, Filters $filters, int $limit, bool $counting): array
    {
        $default = $this->cms->defaultLocale();
        $prefixes = [];

        if ($this->cms->isActive('letterncms/languages')) {
            $languages = app(Languages::class);

            foreach ($languages->codes() as $code) {
                ($prefix = $languages->prefix($code)) !== '' && $prefixes[$prefix] = $code;
            }
        }

        $rows = $counting
            ? $filters->views(DB::table('analytics_pages as w')->whereBetween('w.day', $range->dates()))->selectRaw('w.path, sum(w.views) as views')->groupBy('w.path')->get()
            : $this->views($range, $filters)->selectRaw('w.path, count(*) as views')->groupBy('w.path')->get();
        $views = [];

        foreach ($rows as $row) {
            $locale = $prefixes[explode('/', ltrim((string) $row->path, '/'))[0]] ?? $default;
            $views[$locale] = ($views[$locale] ?? 0) + (int) $row->views;
        }

        arsort($views);
        $items = [];

        foreach (array_slice($views, 0, $limit, true) as $locale => $count) {
            $items[] = ['key' => (string) $locale, 'label' => Languages::name((string) $locale), 'visitors' => null, 'views' => $count];
        }

        return $items;
    }

    /** Visits of the range, narrowed by the filters: aliased `v`. */
    public function visits(Range $range, Filters $filters): Builder
    {
        $query = DB::table('analytics_visits as v');
        $range->since !== null
            ? $query->where('v.last_at', '>=', $range->since->toDateTimeString())->where('v.last_at', '<', $range->until()->toDateTimeString())
            : $query->whereBetween('v.day', $range->dates());

        return $filters->visits($query, $this->goals);
    }

    /** Page views of the range, of the visits the filters keep and of the pages they name: `w`, with its visit `v`. */
    public function views(Range $range, Filters $filters): Builder
    {
        $query = DB::table('analytics_views as w')->join('analytics_visits as v', 'v.id', '=', 'w.visit_id');
        $range->since !== null
            ? $query->where('w.at', '>=', $range->since->toDateTimeString())->where('w.at', '<', $range->until()->toDateTimeString())
            : $query->whereBetween('w.day', $range->dates());

        return $filters->views($filters->visits($query, $this->goals));
    }

    /** A path with the title of the entry it shows, when there is one. */
    public function page(string $path): array
    {
        $entry = $this->cms->entries()->findByUri($path);

        return [
            'label' => ($entry?->working()['title'] ?? '') ?: $path,
            'path' => $path,
            'entry' => $entry ? ['id' => $entry->id, 'collection' => $entry->collection] : null,
        ];
    }

    private function visitRow(Builder $visits): array
    {
        return (array) $visits->selectRaw(self::VISIT_SUMS)->first();
    }

    private function viewRow(Builder $views): array
    {
        return (array) $views->selectRaw(self::VIEW_SUMS)->first();
    }

    /** @param array<string, int|float|null>|null $into */
    private static function add(?array &$into, array $row): void
    {
        foreach ($row as $key => $value) {
            if (! in_array($key, ['day', 'hour'], true)) {
                $into[$key] = ($into[$key] ?? 0) + (int) $value;
            }
        }
    }

    /** @return array{timeOnPage: ?float, scrollDepth: ?int} seconds and percent, or null where nothing was measured */
    private static function engagement(array $views): array
    {
        $timed = (int) ($views['timed'] ?? 0);
        $scrolled = (int) ($views['scrolled'] ?? 0);

        return [
            'timeOnPage' => $timed > 0 ? round((int) $views['engaged'] / $timed / 1000, 1) : null,
            'scrollDepth' => $scrolled > 0 ? (int) round((int) $views['scroll'] / $scrolled) : null,
        ];
    }

    /**
     * Every headline number from the sums: visitors mode passes visits' sums, counts mode null.
     *
     * @return array{visitors: ?int, visits: ?int, views: ?int, viewsPerVisit: ?float, bounceRate: ?float, visitDuration: ?int, timeOnPage: ?float, scrollDepth: ?int, conversions: ?int, conversionRate: ?float}
     */
    public static function metrics(?array $visits = null, ?array $views = null): array
    {
        $count = (int) ($visits['visits'] ?? 0);

        return [
            'visitors' => $visits === null ? null : (int) ($visits['visitors'] ?? 0),
            'visits' => $visits === null ? null : $count,
            'views' => $views === null ? null : (int) ($views['views'] ?? 0),
            'viewsPerVisit' => $visits === null ? null : ($count > 0 ? round((int) $visits['pages'] / $count, 2) : 0.0),
            'bounceRate' => $visits === null ? null : ($count > 0 ? round((int) $visits['bounces'] / $count, 4) : 0.0),
            'visitDuration' => $visits === null ? null : ($count > 0 ? (int) round((int) $visits['engaged'] / $count) : 0),
            ...($visits === null ? ['timeOnPage' => null, 'scrollDepth' => null] : self::engagement($views ?? [])),
            'conversions' => null,
            'conversionRate' => null,
        ];
    }
}
