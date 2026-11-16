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

    /** [source, channel, campaign]: where visits come from. */
    private const array SOURCES = [
        [[null, 'direct', null], 36], [['Google', 'search', null], 30], [['DuckDuckGo', 'search', null], 4], [['Bing', 'search', null], 3],
        [['ChatGPT', 'ai', null], 4], [['Perplexity', 'ai', null], 2], [['Claude', 'ai', null], 1], [['Gemini', 'ai', null], 1],
        [['LinkedIn', 'social', null], 5], [['X', 'social', null], 3], [['Hacker News', 'social', null], 2], [['GitHub', 'referral', null], 3],
        [['newsletter', 'email', 'autumn-update'], 4], [['dribbble.com', 'referral', null], 1], [['Google', 'paid', 'brand'], 1],
    ];

    private const array COUNTRIES = [['US', 24], ['GB', 9], ['DE', 11], ['NL', 8], ['BE', 6], ['FR', 7], ['CA', 4], ['IN', 5], ['ES', 3], ['SE', 2], ['AU', 3], ['BR', 2], ['JP', 2], [null, 3]];

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

        if (DB::table('analytics_visits')->exists() && ! $this->option('fresh')) {
            $this->error('There is analytics data already. Run with --fresh to replace it.');

            return self::FAILURE;
        }

        DB::table('analytics_events')->delete();
        DB::table('analytics_views')->delete();
        DB::table('analytics_visits')->delete();
        DB::table('analytics_pages')->delete();
        $id = 0;

        $paths = $this->paths($cms);
        $days = max(1, min(400, (int) $this->option('days')));
        $today = CarbonImmutable::now((string) config('app.timezone', 'UTC'))->startOfDay();
        $total = 0;

        for ($back = $days - 1; $back >= 0; $back--) {
            $day = $today->subDays($back);
            $visits = [];
            $views = [];
            $events = [];
            $pages = [];

            // Today is not over: a share of the day's visitors so far, the last few still on the site.
            $now = CarbonImmutable::now((string) config('app.timezone', 'UTC'));
            $elapsed = $back === 0 ? max(60, $now->getTimestamp() - $day->getTimestamp()) : 86_400;
            $count = max(1, (int) round($this->dailyVisitors($day, $back, $days) * $elapsed / 86_400));

            foreach (range(1, $count) as $n) {
                [$device, $browser, $os] = $this->pick(self::CLIENTS);
                [$source, $channel, $campaign] = $this->pick(self::SOURCES);
                $length = $this->views();
                $seen = [$this->pick($paths)];
                $start = $back === 0 && $n > $count - 3 ? $now->subSeconds(random_int(20, 200)) : $day->addSeconds(random_int(0, $elapsed - 1));
                $at = $start;
                $engaged = 0;
                $id++;

                for ($v = 1; $v < $length; $v++) {
                    $seen[] = $this->pick($paths);
                }

                foreach ($seen as $i => $path) {
                    $pages[$path]['views'] = ($pages[$path]['views'] ?? 0) + 1;
                    $pages[$path]['visitors'] = ($pages[$path]['visitors'] ?? 0) + (in_array($path, array_slice($seen, 0, $i), true) ? 0 : 1);
                    // Seconds in front of the visitor: most read briefly, some read it all.
                    $reading = (int) round(1000 * min(900, 4 + (random_int(0, 1000) / 1000) ** 2.2 * 260));
                    $measured = random_int(1, 100) <= 75;
                    $views[] = [
                        'visit_id' => $id, 'day' => $day->toDateString(), 'hour' => $at->hour, 'at' => $at->utc()->toDateTimeString(), 'path' => $path,
                        'engaged' => $reading, 'scroll' => min(100, 20 + (int) round($reading / 2500) + random_int(0, 40)),
                        'lcp' => $measured ? random_int(700, $device === 'mobile' ? 4800 : 3000) : null,
                        'inp' => $measured && random_int(0, 1) ? random_int(40, $device === 'mobile' ? 520 : 260) : null,
                        'cls' => $measured ? (random_int(1, 10) === 1 ? random_int(100, 320) : random_int(0, 90)) : null,
                    ];
                    $engaged += $reading;
                    $at = $at->addSeconds(max(5, intdiv($reading, 1000)));
                }

                $done = 0;

                foreach ([['Outbound Link', 6, ['url' => 'https://github.com/letterncms']], ['File Download', 3, ['url' => '/media/brochure.pdf']], ['Form Sent', 4, ['form' => 'contact']], ['Signup', 3, ['plan' => random_int(0, 2) ? 'Free' : 'Pro']]] as [$name, $chance, $props]) {
                    if (random_int(1, 100) <= $chance) {
                        $events[] = ['visit_id' => $id, 'day' => $day->toDateString(), 'hour' => $at->hour, 'at' => $at->utc()->toDateTimeString(), 'name' => $name, 'path' => end($seen), 'props' => json_encode($props)];
                        $done++;
                    }
                }

                $visits[] = [
                    'id' => $id,
                    'day' => $day->toDateString(),
                    'hour' => $start->hour,
                    // Some come back later the same day: another visit of the same visitor.
                    'visitor' => $visits !== [] && random_int(1, 100) <= 18 ? $visits[array_rand($visits)]['visitor'] : substr(bin2hex(random_bytes(8)), 0, 16),
                    'started_at' => $start->utc()->toDateTimeString(),
                    'last_at' => min($at, $back === 0 ? $now : $day->endOfDay())->utc()->toDateTimeString(),
                    'views' => $length,
                    'events' => $done,
                    'engaged' => intdiv($engaged, 1000),
                    'entry' => $seen[0],
                    'exit' => end($seen),
                    'channel' => $channel,
                    'source' => $source,
                    'medium' => $channel === 'email' ? 'email' : ($channel === 'paid' ? 'cpc' : null),
                    'campaign' => $campaign,
                    'country' => $this->pick(self::COUNTRIES),
                    'device' => $device,
                    'browser' => $browser,
                    'os' => $os,
                ];
            }

            foreach (['analytics_visits' => $visits, 'analytics_views' => $views, 'analytics_events' => $events] as $table => $rows) {
                foreach (array_chunk($rows, 300) as $chunk) {
                    DB::table($table)->insert($chunk);
                }
            }

            DB::table('analytics_pages')->insert(array_map(static fn ($path, $p) => ['day' => $day->toDateString(), 'path' => $path, ...$p], array_keys($pages), $pages));
            $total += count($visits);
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
