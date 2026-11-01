<?php

declare(strict_types=1);

namespace Cms\Analytics;

use Carbon\CarbonImmutable;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Cms\Cms;
use Cms\Support\DailySalt;

/**
 * Counts page views, in one of two modes.
 *
 * Counts (the default): a daily number per path and nothing else. Nothing is read from
 * the browser, and nothing about a visitor is kept in any form.
 *
 * Visitors: also a daily pseudonymous hash of IP address and browser with a salt that is
 * replaced every day, to tell today's visits apart, plus source, device and browser. It
 * reads data from the browser, so whether it needs consent is for the site owner to check.
 */
final class Collector
{
    public const string SETTINGS = 'analytics';

    public const string COUNTS = 'counts';

    public const string VISITORS = 'visitors';

    /** Set for people who use the admin, so their own browsing isn't counted. No page or script reads it. */
    public const string STAFF_COOKIE = 'cms_staff';

    /** Pages remembered per visitor and day, to count each page's visitors once. */
    private const int PAGES = 100;

    public function __construct(private readonly Cms $cms) {}

    public function enabled(): bool
    {
        return (bool) $this->cms->settings(self::SETTINGS, 'enabled', true);
    }

    public function mode(): string
    {
        return $this->cms->settings(self::SETTINGS, 'mode', self::COUNTS) === self::VISITORS ? self::VISITORS : self::COUNTS;
    }

    /** Counts mode: one more view of a page that exists. Nobody can fill the table with invented paths. */
    public function recordView(string $path, ?CarbonImmutable $at = null): bool
    {
        $path = self::path($path);

        if ($path === null || ! $this->enabled() || $this->excluded($path) || ! $this->known($path)) {
            return false;
        }

        $day = ($at ?? CarbonImmutable::now((string) config('app.timezone', 'UTC')))->toDateString();
        $count = static function () use ($day, $path): void {
            if (DB::table('analytics_pages')->where('day', $day)->where('path', $path)->update(['views' => DB::raw('views + 1')]) === 0) {
                DB::table('analytics_pages')->insert(['day' => $day, 'path' => $path, 'views' => 1, 'visitors' => 0]);
            }
        };

        try {
            $count();
        } catch (QueryException) {
            // Two first views of the same page at once: the other one inserted the row.
            $count();
        }

        return true;
    }

    /** Back to counts: everything visitors mode kept about visitors goes. */
    public function forgetVisitors(): void
    {
        DB::table('analytics_visitors')->delete();
        DB::table('analytics_pages')->where('visitors', '>', 0)->update(['visitors' => 0]);
        @unlink(storage_path('app/salts/analytics.json'));
    }

    private function known(string $path): bool
    {
        $page = (string) preg_replace('#/page/\d+$#', '', $path);

        if ($page === '' || $page === '/' || $this->cms->entries()->findByUri($page) !== null) {
            return true;
        }

        return (bool) $this->cms->hooks()->filter('analytics.known-path', false, $path);
    }

    /**
     * @param  list<string>  $ownHosts  the site's hosts (referrers from them are not sources)
     * @return bool whether the view was counted
     */
    public function record(string $path, ?string $referrer, ?string $campaign, ?int $width, string $ip, string $userAgent, array $ownHosts, ?CarbonImmutable $at = null): bool
    {
        $path = self::path($path);

        if ($path === null || ! $this->enabled() || $this->mode() !== self::VISITORS || Client::isBot($userAgent) || $this->excluded($path)) {
            return false;
        }

        $at ??= CarbonImmutable::now((string) config('app.timezone', 'UTC'));
        $day = $at->toDateString();
        $visitor = substr(hash_hmac('sha256', $ip.'|'.$userAgent, DailySalt::for('analytics', $day)), 0, 16);
        $client = Client::from($userAgent, $width);
        $source = Sources::resolve($referrer, $campaign, $ownHosts);
        $time = $at->utc()->toDateTimeString();

        $count = function () use ($day, $visitor, $path, $source, $client, $time): void {
            DB::transaction(function () use ($day, $visitor, $path, $source, $client, $time): void {
                $row = DB::table('analytics_visitors')->where('day', $day)->where('visitor', $visitor)->lockForUpdate()->first();
                $firstOnPage = true;

                if ($row === null) {
                    DB::table('analytics_visitors')->insert([
                        'day' => $day, 'visitor' => $visitor, 'views' => 1, 'entry' => $path, 'source' => $source,
                        'device' => $client->device, 'browser' => $client->browser, 'os' => $client->os,
                        'pages' => json_encode([$path]), 'started_at' => $time, 'last_at' => $time,
                    ]);
                } else {
                    $pages = (array) json_decode((string) $row->pages, true);
                    $firstOnPage = ! in_array($path, $pages, true);

                    if ($firstOnPage && count($pages) < self::PAGES) {
                        $pages[] = $path;
                    }

                    DB::table('analytics_visitors')->where('id', $row->id)->update(['views' => $row->views + 1, 'last_at' => $time, 'pages' => json_encode($pages)]);
                }

                $updated = DB::table('analytics_pages')->where('day', $day)->where('path', $path)->update([
                    'views' => DB::raw('views + 1'),
                    'visitors' => DB::raw('visitors + '.($firstOnPage ? 1 : 0)),
                ]);

                if ($updated === 0) {
                    DB::table('analytics_pages')->insert(['day' => $day, 'path' => $path, 'views' => 1, 'visitors' => 1]);
                }
            });
        };

        try {
            $count();
        } catch (QueryException) {
            // Two first views of the same visitor or page at once: the other one inserted the row.
            $count();
        }

        return true;
    }

    /** Forget days older than the retention setting (0 keeps everything). */
    public function prune(): int
    {
        $days = (int) $this->cms->settings(self::SETTINGS, 'retention_days', 0);

        if ($days <= 0) {
            return 0;
        }

        $before = CarbonImmutable::now((string) config('app.timezone', 'UTC'))->subDays($days)->toDateString();

        return DB::table('analytics_visitors')->where('day', '<', $before)->delete() + DB::table('analytics_pages')->where('day', '<', $before)->delete();
    }

    /** The path part of what the snippet sent, or null when it is not a page path. */
    public static function path(string $path): ?string
    {
        $path = rawurldecode((string) strtok(trim($path), '?#'));

        if ($path === '' || $path[0] !== '/' || str_starts_with($path, '//') || strlen($path) > 400 || ! mb_check_encoding($path, 'UTF-8') || preg_match('/[\x00-\x1F\x7F]/', $path)) {
            return null;
        }

        return $path === '/' ? '/' : rtrim($path, '/');
    }

    private function excluded(string $path): bool
    {
        foreach ((array) $this->cms->settings(self::SETTINGS, 'exclude', []) as $pattern) {
            $pattern = trim((string) $pattern);

            if ($pattern !== '' && ($pattern === $path || fnmatch($pattern, $path))) {
                return true;
            }
        }

        return false;
    }
}
