<?php

declare(strict_types=1);

namespace Cms\Analytics;

use Carbon\CarbonImmutable;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Cms\Cms;
use Cms\Support\DailySalt;

/**
 * Counts what happens on the published site, in one of two modes.
 *
 * Counts (the default): a daily number per path and nothing else. Nothing is read from the browser, and
 * nothing about a visitor is kept in any form.
 *
 * Visitors: visits, the pages viewed in them and what people did there. A visitor is a hash of IP address
 * and browser with a salt that is replaced every day, so today's visits can be told apart and nobody can
 * be recognised tomorrow. The snippet also sends where the visit came from, the window's width, the time
 * zone (for the country), how long a page was in front of the visitor, how far they scrolled and how fast
 * the page was for them. It reads data from the browser, so whether it needs consent is for the owner.
 *
 * Plugins and the API record events of their own with `track()` (or the core hook `analytics.track`).
 */
final class Collector
{
    public const string SETTINGS = 'analytics';

    public const string COUNTS = 'counts';

    public const string VISITORS = 'visitors';

    /** Set for people who use the admin, so their own browsing isn't counted. No page or script reads it. */
    public const string STAFF_COOKIE = 'cms_staff';

    /** A visit ends after this long without a page view or an event. */
    public const int VISIT_MINUTES = 30;

    /** The names of the events the snippet sends by itself. */
    public const string OUTBOUND = 'Outbound Link';

    public const string DOWNLOAD = 'File Download';

    public const string FORM = 'Form Sent';

    public const string NOT_FOUND = 'Page Not Found';

    /** Properties an event keeps at most, and how long a name or a value may be. */
    private const int PROPS = 10;

    public function __construct(private readonly Cms $cms) {}

    public function enabled(): bool
    {
        return (bool) $this->cms->setting(self::SETTINGS, 'enabled', true);
    }

    public function mode(): string
    {
        return $this->cms->setting(self::SETTINGS, 'mode', self::COUNTS) === self::VISITORS ? self::VISITORS : self::COUNTS;
    }

    /** Counts mode: one more view of a page that exists. Nobody can fill the table with invented paths. */
    public function recordView(string $path, ?CarbonImmutable $at = null): bool
    {
        $path = self::path($path);

        if ($path === null || ! $this->enabled() || $this->excluded($path) || ! $this->known($path)) {
            return false;
        }

        $this->countPage(self::local($at)->toDateString(), $path, false);

        return true;
    }

    /**
     * Visitors mode: what the snippet sent. `t` says what it is: `pageview` (the default), `engagement` (time,
     * scroll and speed of the page on view, sent as it is hidden; sent again, it only adds what is new) or
     * `event` (a click, a download, a form or a custom event, named `n` with properties `o`).
     *
     * @param  array<string, mixed>  $hit
     * @param  list<string>  $ownHosts  the site's hosts (referrers from them are not sources)
     * @return bool whether something was counted
     */
    public function record(array $hit, string $ip, string $userAgent, array $ownHosts, ?CarbonImmutable $at = null): bool
    {
        $path = is_string($hit['p'] ?? null) ? self::path($hit['p']) : null;

        if ($path === null || ! $this->enabled() || $this->mode() !== self::VISITORS || Client::isBot($userAgent) || $this->excluded($path)) {
            return false;
        }

        $at = self::local($at);
        // The site's own host too: the same person on another site of this server is somebody else there.
        $visitor = substr(hash_hmac('sha256', $ip.'|'.$userAgent.'|'.parse_url($this->cms->url(), PHP_URL_HOST), DailySalt::for('analytics', $at->toDateString())), 0, 16);

        return $this->retry(fn () => match ($hit['t'] ?? 'pageview') {
            'pageview' => $this->pageview($hit, $path, $visitor, $userAgent, $ownHosts, $at),
            'engagement' => $this->engagement($hit, $path, $visitor, $at),
            'event' => $this->clientEvent($hit, $path, $visitor, $at),
            default => false,
        });
    }

    /**
     * An event of a plugin's or the API's, outside any visit: a sale, a sign-up confirmed by email. It counts
     * in both modes; only the snippet's events can say which visitors did it.
     *
     * @param  array<string, scalar|null>  $props
     */
    public function track(string $name, array $props = [], ?string $path = null, ?CarbonImmutable $at = null): bool
    {
        $name = self::name($name);
        $path = $path === null ? null : self::path($path);

        if ($name === null || ! $this->enabled()) {
            return false;
        }

        $at = self::local($at);
        DB::table('analytics_events')->insert([...self::when($at), 'visit_id' => null, 'name' => $name, 'path' => $path, 'props' => self::props($props)]);

        return true;
    }

    private function pageview(array $hit, string $path, string $visitor, string $userAgent, array $ownHosts, CarbonImmutable $at): bool
    {
        $missing = ($hit['f'] ?? null) === 404;

        if (! $missing && ! $this->known($path)) {
            return false;
        }

        DB::transaction(function () use ($hit, $path, $visitor, $userAgent, $ownHosts, $at, $missing): void {
            $visit = $this->current($visitor, $at);
            $time = $at->utc()->toDateTimeString();

            if ($visit === null) {
                $client = Client::from($userAgent, is_numeric($hit['w'] ?? null) ? (int) $hit['w'] : null);
                $source = Sources::resolve(self::text($hit['r'] ?? null, 1000), [
                    'source' => self::text($hit['s'] ?? null, 120), 'medium' => self::text($hit['m'] ?? null, 60),
                    'campaign' => self::text($hit['c'] ?? null, 100), 'paid' => ($hit['g'] ?? null) === 1,
                ], $ownHosts);
                $id = DB::table('analytics_visits')->insertGetId([
                    ...self::when($at, 'started_at'), 'visitor' => $visitor, 'last_at' => $time, 'views' => 1, 'events' => 0, 'engaged' => 0,
                    'entry' => $path, 'exit' => $path, ...$source, 'country' => Countries::fromTimeZone(self::text($hit['z'] ?? null, 64)),
                    'device' => $client->device, 'browser' => $client->browser, 'os' => $client->os,
                ]);
            } else {
                $id = $visit->id;
                DB::table('analytics_visits')->where('id', $id)->update(['views' => $visit->views + 1, 'exit' => $path, 'last_at' => $time]);
            }

            // The page's visitors today: this one is one more only the first time today.
            $seen = DB::table('analytics_views')->join('analytics_visits', 'analytics_visits.id', '=', 'analytics_views.visit_id')
                ->where('analytics_visits.visitor', $visitor)->where('analytics_views.path', $path)->exists();

            DB::table('analytics_views')->insert([...self::when($at), 'visit_id' => $id, 'path' => $path]);
            $this->countPage($at->toDateString(), $path, ! $seen);

            if ($missing) {
                DB::table('analytics_events')->insert([...self::when($at), 'visit_id' => $id, 'name' => self::NOT_FOUND, 'path' => $path, 'props' => null]);
            }
        });

        return true;
    }

    /** Time on the page, how far down it went and its speed: kept as the most the visitor reached, so a second report adds only what is new. */
    private function engagement(array $hit, string $path, string $visitor, CarbonImmutable $at): bool
    {
        $visit = $this->current($visitor, $at);
        $view = $visit ? DB::table('analytics_views')->where('visit_id', $visit->id)->where('path', $path)->orderByDesc('id')->first() : null;

        if ($view === null) {
            return false;
        }

        // Up to six hours in front of somebody: more is a tab left open, not reading.
        $engaged = self::number($hit['e'] ?? null, 6 * 3_600_000);
        $values = array_filter([
            'engaged' => $engaged === null ? null : max($engaged, (int) $view->engaged),
            'scroll' => ($scroll = self::number($hit['d'] ?? null, 100)) === null ? null : max($scroll, (int) $view->scroll),
            // Largest paint: the last one reported is the page's. Interaction and layout shift: the worst.
            'lcp' => self::number($hit['l'] ?? null, 120_000),
            'inp' => ($inp = self::number($hit['i'] ?? null, 60_000)) === null ? null : max($inp, (int) $view->inp),
            'cls' => ($cls = self::number($hit['x'] ?? null, 100_000)) === null ? null : max($cls, (int) $view->cls),
        ], static fn ($v) => $v !== null);

        if ($values === []) {
            return false;
        }

        DB::transaction(function () use ($values, $view, $visit, $at): void {
            DB::table('analytics_views')->where('id', $view->id)->update($values);
            $engaged = (int) DB::table('analytics_views')->where('visit_id', $visit->id)->sum('engaged');
            // Reading is being on the site: the visit goes on.
            DB::table('analytics_visits')->where('id', $visit->id)->update(['engaged' => intdiv($engaged, 1000), 'last_at' => $at->utc()->toDateTimeString()]);
        });

        return true;
    }

    private function clientEvent(array $hit, string $path, string $visitor, CarbonImmutable $at): bool
    {
        $name = is_string($hit['n'] ?? null) ? self::name($hit['n']) : null;
        $visit = $name === null ? null : $this->current($visitor, $at);

        if ($visit === null) {
            return false;
        }

        DB::transaction(function () use ($visit, $name, $path, $hit, $at): void {
            DB::table('analytics_events')->insert([...self::when($at), 'visit_id' => $visit->id, 'name' => $name, 'path' => $path, 'props' => self::props(is_array($hit['o'] ?? null) ? $hit['o'] : [])]);
            DB::table('analytics_visits')->where('id', $visit->id)->update(['events' => $visit->events + 1, 'last_at' => $at->utc()->toDateTimeString()]);
        });

        return true;
    }

    /** The visitor's visit that is still going on: something happened in it in the last half hour, today. */
    private function current(string $visitor, CarbonImmutable $at): ?object
    {
        return DB::table('analytics_visits')->where('visitor', $visitor)
            ->where('last_at', '>=', $at->utc()->subMinutes(self::VISIT_MINUTES)->toDateTimeString())
            ->orderByDesc('id')->lockForUpdate()->first();
    }

    /** Daily views per page, in both modes: the numbers of page views only, and the same numbers on a switch. */
    private function countPage(string $day, string $path, bool $newVisitor): void
    {
        $this->retry(static function () use ($day, $path, $newVisitor): bool {
            $updated = DB::table('analytics_pages')->where('day', $day)->where('path', $path)
                ->update(['views' => DB::raw('views + 1'), 'visitors' => DB::raw('visitors + '.($newVisitor ? 1 : 0))]);

            if ($updated === 0) {
                DB::table('analytics_pages')->insert(['day' => $day, 'path' => $path, 'views' => 1, 'visitors' => $newVisitor ? 1 : 0]);
            }

            return true;
        });
    }

    /** Two first rows at once: the other one inserted it, so the second try updates it. */
    private function retry(callable $count): bool
    {
        try {
            return $count();
        } catch (QueryException) {
            return $count();
        }
    }

    /** Back to counts: everything visitors mode kept about visitors goes. Events of plugins stay. */
    public function forgetVisitors(): void
    {
        DB::table('analytics_events')->whereNotNull('visit_id')->delete();
        DB::table('analytics_views')->delete();
        DB::table('analytics_visits')->delete();
        DB::table('analytics_pages')->where('visitors', '>', 0)->update(['visitors' => 0]);
        @unlink(storage_path('app/salts/analytics.json'));
    }

    /** Forget days older than the retention setting (0 keeps everything). */
    public function prune(): int
    {
        $days = (int) $this->cms->setting(self::SETTINGS, 'retention_days', 0);

        if ($days <= 0) {
            return 0;
        }

        $before = self::local()->subDays($days)->toDateString();

        return DB::table('analytics_events')->where('day', '<', $before)->delete()
            + DB::table('analytics_views')->where('day', '<', $before)->delete()
            + DB::table('analytics_visits')->where('day', '<', $before)->delete()
            + DB::table('analytics_pages')->where('day', '<', $before)->delete();
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

    /** An event's name as it is kept: plain words, at most 120 characters. */
    public static function name(string $name): ?string
    {
        $name = trim((string) preg_replace('/[\x00-\x1F\x7F]+/', '', $name));

        return $name === '' ? null : mb_substr($name, 0, 120);
    }

    /** The site's clock: days and hours are the site's. */
    public static function local(?CarbonImmutable $at = null): CarbonImmutable
    {
        return ($at ?? CarbonImmutable::now())->setTimezone((string) config('app.timezone', 'UTC'));
    }

    private function known(string $path): bool
    {
        $page = (string) preg_replace('#/page/\d+$#', '', $path);

        if ($page === '' || $page === '/' || $this->cms->entries()->findByUri($page) !== null) {
            return true;
        }

        return (bool) $this->cms->hooks()->filter('analytics.known-path', false, $path);
    }

    private function excluded(string $path): bool
    {
        foreach ((array) $this->cms->setting(self::SETTINGS, 'exclude', []) as $pattern) {
            $pattern = trim((string) $pattern);

            if ($pattern !== '' && ($pattern === $path || fnmatch($pattern, $path))) {
                return true;
            }
        }

        return false;
    }

    /** @return array{day: string, hour: int} and the time, in a column of its own name */
    private static function when(CarbonImmutable $at, string $column = 'at'): array
    {
        return ['day' => $at->toDateString(), 'hour' => $at->hour, $column => $at->utc()->toDateTimeString()];
    }

    /** At most ten properties, each a short name and a short text: nothing else is kept. */
    private static function props(array $props): ?string
    {
        $kept = [];

        foreach ($props as $key => $value) {
            $key = is_string($key) ? trim((string) preg_replace('/[^\p{L}\p{N} _.-]+/u', '', $key)) : '';

            if ($key === '' || ! (is_scalar($value) || $value === null) || count($kept) >= self::PROPS) {
                continue;
            }

            $value = is_bool($value) ? ($value ? 'true' : 'false') : trim((string) preg_replace('/[\x00-\x1F\x7F]+/', '', (string) $value));
            $value !== '' && $kept[mb_substr($key, 0, 40)] = mb_substr($value, 0, 300);
        }

        return $kept === [] ? null : (string) json_encode($kept, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    }

    private static function text(mixed $value, int $length): ?string
    {
        return is_string($value) && $value !== '' ? mb_substr($value, 0, $length) : null;
    }

    private static function number(mixed $value, int $max): ?int
    {
        return is_numeric($value) && $value >= 0 ? (int) min($max, round((float) $value)) : null;
    }
}
