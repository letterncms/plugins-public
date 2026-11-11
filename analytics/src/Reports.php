<?php

declare(strict_types=1);

namespace Cms\Analytics;

use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Cms\Cms;
use Cms\Modules\Languages\Languages;

/** Reads the daily totals back: headline numbers, a series per day and breakdowns. */
final class Reports
{
    /** Period => days, today included. */
    public const array PERIODS = ['7d' => 7, '30d' => 30, '90d' => 90];

    public const array DIMENSIONS = ['pages', 'languages', 'entries', 'sources', 'devices', 'browsers', 'os'];

    /** What can still be told with page views alone. */
    private const array COUNTS_DIMENSIONS = ['pages', 'languages'];

    public function __construct(private readonly Cms $cms) {}

    /** @return array{0: CarbonImmutable, 1: CarbonImmutable} first and last day of a period ending today */
    public function range(string $period, int $back = 0): array
    {
        $days = self::PERIODS[$period] ?? 30;
        $to = CarbonImmutable::now((string) config('app.timezone', 'UTC'))->startOfDay()->subDays($days * $back);

        return [$to->subDays($days - 1), $to];
    }

    /** Page views only (the default), rather than visitors, sources and devices. */
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
        return DB::table($this->counting() ? 'analytics_pages' : 'analytics_visitors')->exists();
    }

    /** Counts mode: page views only. What it can't know is null, not zero. */
    private function viewsSummary(string $period): array
    {
        [$from, $to] = $this->range($period);
        [$previousFrom, $previousTo] = $this->range($period, 1);
        $views = static fn (CarbonImmutable $a, CarbonImmutable $b) => (int) DB::table('analytics_pages')->whereBetween('day', [$a->toDateString(), $b->toDateString()])->sum('views');
        $metrics = static fn (int $views) => ['visitors' => null, 'views' => $views, 'viewsPerVisit' => null, 'bounceRate' => null];
        $days = DB::table('analytics_pages')->selectRaw('day, sum(views) as views')->whereBetween('day', [$from->toDateString(), $to->toDateString()])->groupBy('day')->get()
            ->mapWithKeys(static fn ($row) => [substr((string) $row->day, 0, 10) => (int) $row->views]);

        return [
            'period' => $period,
            'from' => $from->toDateString(),
            'to' => $to->toDateString(),
            'mode' => Collector::COUNTS,
            ...$metrics($views($from, $to)),
            'previous' => $metrics($views($previousFrom, $previousTo)),
            'series' => array_map(static fn (string $day) => ['date' => $day, ...$metrics((int) ($days[$day] ?? 0))], $this->days($from, $to)),
            'current' => null,
            'enabled' => $this->enabled(),
            'tracked' => $this->tracked(),
        ];
    }

    public function summary(string $period): array
    {
        if ($this->counting()) {
            return $this->viewsSummary($period);
        }

        [$from, $to] = $this->range($period);
        [$previousFrom, $previousTo] = $this->range($period, 1);
        $days = $this->days($from, $to);
        $series = [];

        foreach ($days as $day) {
            $series[$day] = ['date' => $day, ...$this->metrics(0, 0, 0)];
        }

        $rows = DB::table('analytics_visitors')
            ->selectRaw('day, count(*) as visitors, sum(views) as views, sum(case when views = 1 then 1 else 0 end) as bounces')
            ->whereBetween('day', [$from->toDateString(), $to->toDateString()])
            ->groupBy('day')
            ->get();

        foreach ($rows as $row) {
            $day = substr((string) $row->day, 0, 10);

            if (isset($series[$day])) {
                $series[$day] = ['date' => $day, ...$this->metrics((int) $row->visitors, (int) $row->views, (int) $row->bounces)];
            }
        }

        return [
            'period' => $period,
            'from' => $from->toDateString(),
            'to' => $to->toDateString(),
            'mode' => Collector::VISITORS,
            ...$this->totals($from, $to),
            'previous' => $this->totals($previousFrom, $previousTo),
            'series' => array_values($series),
            // Visitors whose last view was in the past five minutes.
            'current' => DB::table('analytics_visitors')->where('last_at', '>=', CarbonImmutable::now('UTC')->subMinutes(5)->toDateTimeString())->count(),
            'enabled' => $this->enabled(),
            'tracked' => $this->tracked(),
        ];
    }

    /**
     * Visitors (and views for pages) per value of a dimension, most first. Entries are
     * the pages visits started on.
     *
     * @return array{dimension: string, total: int, items: list<array{key: ?string, label: string, visitors: int, views?: int, path?: string, entry?: array}>}
     */
    public function breakdown(string $period, string $dimension, int $limit = 10): array
    {
        $result = $this->rows($period, $dimension, $limit);
        $total = $result['total'];
        $site = $this->cms->url();
        $kinds = ['desktop' => 'desktop', 'mobile' => 'phone', 'tablet' => 'tablet'];

        // Each value's share of the whole (0 to 1), a page's address on the site, and a kind of device's icon.
        $result['items'] = array_map(static function (array $item) use ($total, $site, $dimension, $kinds) {
            $count = $item['visitors'] ?? $item['views'] ?? 0;

            return [...$item,
                'share' => $total > 0 ? round($count / $total, 4) : 0,
                ...(isset($item['path']) ? ['url' => $site.$item['path']] : []),
                ...($dimension === 'devices' && isset($kinds[$item['key'] ?? '']) ? ['icon' => $kinds[$item['key']]] : []),
            ];
        }, $result['items']);

        return $result;
    }

    private function rows(string $period, string $dimension, int $limit): array
    {
        [$from, $to] = $this->range($period);
        $between = [$from->toDateString(), $to->toDateString()];

        if ($this->counting() && ! in_array($dimension, self::COUNTS_DIMENSIONS, true)) {
            return ['dimension' => $dimension, 'total' => 0, 'items' => [], 'unavailable' => 'mode'];
        }

        if ($dimension === 'languages') {
            return $this->languages($between, $limit);
        }

        if ($this->counting()) {
            $rows = DB::table('analytics_pages')->selectRaw('path, sum(views) as views')->whereBetween('day', $between)->groupBy('path')->orderByDesc('views')->orderBy('path')->limit($limit)->get();

            return ['dimension' => $dimension, 'total' => (int) DB::table('analytics_pages')->whereBetween('day', $between)->sum('views'), 'items' => $rows->map(fn ($row) => [
                'key' => (string) $row->path,
                'visitors' => null,
                'views' => (int) $row->views,
                ...$this->page((string) $row->path),
            ])->all()];
        }

        $total = DB::table('analytics_visitors')->whereBetween('day', $between)->count();

        if ($dimension === 'pages') {
            $rows = DB::table('analytics_pages')
                ->selectRaw('path, sum(visitors) as visitors, sum(views) as views')
                ->whereBetween('day', $between)
                ->groupBy('path')
                ->orderByDesc('visitors')->orderByDesc('views')->orderBy('path')
                ->limit($limit)
                ->get();

            $items = $rows->map(fn ($row) => [
                'key' => (string) $row->path,
                'visitors' => (int) $row->visitors,
                'views' => (int) $row->views,
                ...$this->page((string) $row->path),
            ])->all();

            return ['dimension' => $dimension, 'total' => $total, 'items' => $items];
        }

        $column = ['entries' => 'entry', 'sources' => 'source', 'devices' => 'device', 'browsers' => 'browser', 'os' => 'os'][$dimension];
        $rows = DB::table('analytics_visitors')
            ->selectRaw("{$column} as value, count(*) as visitors")
            ->whereBetween('day', $between)
            ->groupBy($column)
            ->orderByDesc('visitors')->orderBy($column)
            ->limit($limit)
            ->get();

        return ['dimension' => $dimension, 'total' => $total, 'items' => $rows->map(fn ($row) => [
            'key' => $row->value === null ? null : (string) $row->value,
            'label' => $row->value === null ? 'Direct' : (string) $row->value,
            'visitors' => (int) $row->visitors,
            ...($dimension === 'entries' ? $this->page((string) $row->value) : []),
        ])->all()];
    }

    /** Page views per language, by the path's prefix: the Languages module's, so /pt-br/… counts for pt-BR. */
    private function languages(array $between, int $limit): array
    {
        $default = $this->cms->defaultLocale();
        $prefixes = [];

        if ($this->cms->isActive('letterncms/languages')) {
            $languages = app(Languages::class);

            foreach ($languages->codes() as $code) {
                ($prefix = $languages->prefix($code)) !== '' && $prefixes[$prefix] = $code;
            }
        }

        $views = [];

        foreach (DB::table('analytics_pages')->selectRaw('path, sum(views) as views')->whereBetween('day', $between)->groupBy('path')->get() as $row) {
            $locale = $prefixes[explode('/', ltrim((string) $row->path, '/'))[0]] ?? $default;
            $views[$locale] = ($views[$locale] ?? 0) + (int) $row->views;
        }

        arsort($views);
        $items = [];

        foreach (array_slice($views, 0, $limit, true) as $locale => $count) {
            $items[] = ['key' => (string) $locale, 'label' => Languages::name((string) $locale), 'visitors' => null, 'views' => $count];
        }

        return ['dimension' => 'languages', 'total' => array_sum($views), 'items' => $items];
    }

    /** A path with the title of the entry it shows, when there is one. */
    private function page(string $path): array
    {
        $entry = $this->cms->entries()->findByUri($path);

        return [
            'label' => ($entry?->working()['title'] ?? '') ?: $path,
            'path' => $path,
            'entry' => $entry ? ['id' => $entry->id, 'collection' => $entry->collection] : null,
        ];
    }

    /** @return array{visitors: int, views: int, viewsPerVisit: float, bounceRate: float} */
    private function totals(CarbonImmutable $from, CarbonImmutable $to): array
    {
        $row = DB::table('analytics_visitors')
            ->selectRaw('count(*) as visitors, coalesce(sum(views), 0) as views, coalesce(sum(case when views = 1 then 1 else 0 end), 0) as bounces')
            ->whereBetween('day', [$from->toDateString(), $to->toDateString()])
            ->first();

        return $this->metrics((int) $row->visitors, (int) $row->views, (int) $row->bounces);
    }

    /** @return array{visitors: int, views: int, viewsPerVisit: float, bounceRate: float} */
    private function metrics(int $visitors, int $views, int $bounces): array
    {
        return [
            'visitors' => $visitors,
            'views' => $views,
            'viewsPerVisit' => $visitors > 0 ? round($views / $visitors, 2) : 0.0,
            'bounceRate' => $visitors > 0 ? round($bounces / $visitors, 4) : 0.0,
        ];
    }

    /** @return list<string> */
    private function days(CarbonImmutable $from, CarbonImmutable $to): array
    {
        $out = [];

        for ($day = $from; $day <= $to; $day = $day->addDay()) {
            $out[] = $day->toDateString();
        }

        return $out;
    }
}
