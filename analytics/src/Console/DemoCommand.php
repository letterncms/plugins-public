<?php

declare(strict_types=1);

namespace Cms\Analytics\Console;

use Carbon\CarbonImmutable;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Cms\Cms;

/**
 * Fills the analytics tables with made-up but plausible traffic for the pages this
 * site has, so the screens can be seen and developed before real visitors arrive.
 * For development only: it refuses to run in production and never mixes with real data.
 */
final class DemoCommand extends Command
{
    protected $signature = 'analytics:demo {--days=120 : How many days back} {--fresh : Replace the analytics data there is}';

    protected $description = 'Fill analytics with demo traffic (development only)';

    private const array SOURCES = [[null, 38], ['Google', 30], ['DuckDuckGo', 4], ['Bing', 3], ['ChatGPT', 4], ['LinkedIn', 5], ['X', 3], ['Hacker News', 2], ['GitHub', 3], ['newsletter', 4], ['dribbble.com', 1], ['Perplexity', 2]];

    private const array CLIENTS = [
        [['desktop', 'Chrome', 'macOS'], 22], [['desktop', 'Chrome', 'Windows'], 18], [['desktop', 'Safari', 'macOS'], 10], [['desktop', 'Firefox', 'Windows'], 4],
        [['desktop', 'Edge', 'Windows'], 6], [['desktop', 'Firefox', 'Linux'], 2], [['mobile', 'Safari', 'iOS'], 20], [['mobile', 'Chrome', 'Android'], 11],
        [['mobile', 'Samsung Internet', 'Android'], 2], [['tablet', 'Safari', 'iOS'], 4], [['desktop', 'Opera', 'Windows'], 1],
    ];

    public function handle(Cms $cms): int
    {
        if ($this->laravel->isProduction()) {
            $this->error('Demo traffic is for development sites only.');

            return self::FAILURE;
        }

        if (DB::table('analytics_visitors')->exists() && ! $this->option('fresh')) {
            $this->error('There is analytics data already. Run with --fresh to replace it.');

            return self::FAILURE;
        }

        DB::table('analytics_visitors')->delete();
        DB::table('analytics_pages')->delete();

        $paths = $this->paths($cms);
        $days = max(1, min(400, (int) $this->option('days')));
        $today = CarbonImmutable::now((string) config('app.timezone', 'UTC'))->startOfDay();
        $total = 0;

        for ($back = $days - 1; $back >= 0; $back--) {
            $day = $today->subDays($back);
            $visitors = [];
            $pages = [];

            // Today is not over: a share of the day's visitors so far, the last few still on the site.
            $now = CarbonImmutable::now((string) config('app.timezone', 'UTC'));
            $elapsed = $back === 0 ? max(60, $now->getTimestamp() - $day->getTimestamp()) : 86_400;
            $count = max(1, (int) round($this->dailyVisitors($day, $back, $days) * $elapsed / 86_400));

            foreach (range(1, $count) as $n) {
                [$device, $browser, $os] = $this->pick(self::CLIENTS);
                $views = $this->views();
                $entry = $this->pick($paths);
                $seen = [$entry];
                $start = $back === 0 && $n > $count - 3 ? $now->subSeconds(random_int(20, 200)) : $day->addSeconds(random_int(0, $elapsed - 1));

                for ($v = 1; $v < $views; $v++) {
                    $seen[] = $this->pick($paths);
                }

                foreach ($seen as $i => $path) {
                    $pages[$path]['views'] = ($pages[$path]['views'] ?? 0) + 1;
                    $pages[$path]['visitors'] = ($pages[$path]['visitors'] ?? 0) + (in_array($path, array_slice($seen, 0, $i), true) ? 0 : 1);
                }

                $visitors[] = [
                    'day' => $day->toDateString(),
                    'visitor' => substr(bin2hex(random_bytes(8)), 0, 16),
                    'views' => $views,
                    'entry' => $entry,
                    'source' => $this->pick(self::SOURCES),
                    'device' => $device,
                    'browser' => $browser,
                    'os' => $os,
                    'pages' => json_encode(array_values(array_unique($seen))),
                    'started_at' => $start->utc()->toDateTimeString(),
                    'last_at' => min($start->addSeconds(($views - 1) * random_int(20, 140)), $back === 0 ? $now : $day->endOfDay())->utc()->toDateTimeString(),
                ];
            }

            foreach (array_chunk($visitors, 500) as $chunk) {
                DB::table('analytics_visitors')->insert($chunk);
            }

            DB::table('analytics_pages')->insert(array_map(static fn ($path, $p) => ['day' => $day->toDateString(), 'path' => $path, ...$p], array_keys($pages), $pages));
            $total += count($visitors);
        }

        $this->info("Added {$total} demo visits over {$days} days.");

        return self::SUCCESS;
    }

    /** The site's published pages, the home page and the first ones most visited. */
    private function paths(Cms $cms): array
    {
        $uris = [];

        foreach ($cms->collections()->all() as $collection) {
            if ($collection->route === null || $collection->system) {
                continue;
            }

            foreach ($cms->entries()->query()->collection($collection->handle)->status('published')->limit(40)->get() as $entry) {
                if ($entry->uri !== null) {
                    $uris[] = $entry->uri;
                }
            }
        }

        $uris = array_values(array_unique($uris ?: ['/', '/about', '/blog', '/contact', '/pricing']));
        usort($uris, static fn ($a, $b) => [$a !== '/', strlen($a)] <=> [$b !== '/', strlen($b)]);

        // Weights fall off with rank: a few pages get most of the traffic.
        return array_map(static fn ($uri, $rank) => [$uri, max(1, (int) round(100 / ($rank + 1) ** 1.1))], $uris, array_keys($uris));
    }

    /** Weekdays busier than weekends, slow growth over the period, noise and the odd spike. */
    private function dailyVisitors(CarbonImmutable $day, int $back, int $days): int
    {
        $growth = 0.55 + 0.45 * (1 - $back / max(1, $days));
        $weekday = $day->isWeekend() ? 0.62 : 1.0;
        $noise = 0.82 + random_int(0, 36) / 100;
        $spike = random_int(1, 40) === 1 ? 2.4 : 1.0;

        return max(3, (int) round(150 * $growth * $weekday * $noise * $spike));
    }

    /** About half bounce; a few read a lot. */
    private function views(): int
    {
        $views = 1;

        while ($views < 12 && random_int(1, 100) <= ($views === 1 ? 54 : 46)) {
            $views++;
        }

        return $views;
    }

    /** @param  list<array{0: mixed, 1: int}>  $weighted */
    private function pick(array $weighted): mixed
    {
        $roll = random_int(1, array_sum(array_column($weighted, 1)));

        foreach ($weighted as [$value, $weight]) {
            if (($roll -= $weight) <= 0) {
                return $value;
            }
        }

        return $weighted[0][0];
    }
}
