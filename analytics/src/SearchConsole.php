<?php

declare(strict_types=1);

namespace Cms\Analytics;

use Carbon\CarbonImmutable;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Cms\Abilities\AbilityException;
use Cms\Cms;
use Throwable;

/**
 * What Google Search says about the site, from Google Search Console: the searches it showed up in, how
 * often it was shown and clicked, and its average position. It signs in as a service account (its key in
 * the settings, kept encrypted), which the owner added as a user of the property; the property is the
 * setting's, or the one of this site's host the account can see. Answers are kept for an hour: Google
 * itself is a day or two behind. Nothing of it is joined with visits.
 */
final class SearchConsole
{
    private const string SCOPE = 'https://www.googleapis.com/auth/webmasters.readonly';

    private const string API = 'https://www.googleapis.com/webmasters/v3';

    public const array DIMENSIONS = ['queries' => 'query', 'pages' => 'page', 'countries' => 'country', 'devices' => 'device'];

    /** Searches where the site is close to the top: worth improving the page for. */
    private const array CLOSE = [4, 20];

    public function __construct(private readonly Cms $cms) {}

    /** @return array{connected: bool, property: ?string, problem: ?string} `problem`: key, access or unreachable */
    public function status(): array
    {
        if ($this->key() === null) {
            return ['connected' => false, 'property' => null, 'problem' => trim((string) $this->setting('search_key')) === '' ? null : 'key'];
        }

        try {
            return ['connected' => true, 'property' => $this->property(), 'problem' => null];
        } catch (AbilityException $e) {
            return ['connected' => false, 'property' => null, 'problem' => $e->details['problem'] ?? 'unreachable'];
        }
    }

    /** Clicks, impressions, click-through rate and position for the period and the one before, and a row per step. */
    public function summary(Range $range, ?string $page = null, ?string $query = null): array
    {
        [$from, $to] = $this->dates($range);
        $previous = $this->dates($range->previous());
        $filters = $this->filters($page, $query);
        $totals = fn (array $dates) => self::metrics($this->query($dates, [], $filters, 1)[0] ?? null);
        $days = [];

        foreach ($this->query([$from, $to], ['date'], $filters, 1000) as $row) {
            $key = $range->key((string) $row['keys'][0]);
            $days[$key][] = $row;
        }

        $steps = in_array($range->interval, ['minute', 'hour'], true) ? [$range->from->toDateString()] : $range->steps();

        return [
            'period' => $range->period, 'from' => $from, 'to' => $to, 'interval' => in_array($range->interval, ['minute', 'hour'], true) ? 'day' : $range->interval,
            'property' => $this->property(),
            ...$totals([$from, $to]),
            'previous' => $totals($previous),
            'series' => array_map(static fn (string $step) => ['date' => $step, ...self::sum($days[$step] ?? [])], $steps),
        ];
    }

    /** The searches, pages, countries or devices, most clicked first. */
    public function breakdown(Range $range, string $dimension, ?string $page = null, ?string $query = null, int $limit = 10): array
    {
        $column = self::DIMENSIONS[$dimension] ?? throw AbilityException::invalid('Search Console breaks down by queries, pages, countries or devices.');
        $site = rtrim($this->cms->url(), '/');
        $rows = $this->query($this->dates($range), [$column], $this->filters($page, $query), $limit);

        return ['dimension' => $dimension, 'items' => array_map(function (array $row) use ($column, $site) {
            $key = (string) $row['keys'][0];
            $path = $column === 'page' && str_starts_with($key, $site) ? (Collector::path(substr($key, strlen($site)) ?: '/') ?? '/') : null;

            return ['key' => $key, 'label' => $key, ...($path !== null ? ['path' => $path, 'label' => $this->title($path) ?? $key] : []), ...($column === 'page' ? ['url' => $key] : []), ...self::metrics($row)];
        }, $rows)];
    }

    /**
     * Searches the site is shown for near the top (positions 4 to 20), the most shown first, with the page
     * Google shows: lifting such a page a few places is where more clicks are closest.
     */
    public function opportunities(Range $range, int $limit = 10): array
    {
        $site = rtrim($this->cms->url(), '/');
        $rows = array_filter($this->query($this->dates($range), ['query', 'page'], [], 2000), static fn (array $row) => $row['position'] >= self::CLOSE[0] && $row['position'] <= self::CLOSE[1] && $row['impressions'] >= 10);
        usort($rows, static fn ($a, $b) => $b['impressions'] <=> $a['impressions']);

        return ['items' => array_map(function (array $row) use ($site) {
            [$query, $url] = $row['keys'];
            $path = str_starts_with($url, $site) ? Collector::path(substr($url, strlen($site)) ?: '/') : null;

            return ['key' => $query."\n".$url, 'label' => $query, 'url' => $url, 'path' => $path, 'page' => $path !== null ? ($this->title($path) ?? $path) : $url, 'kind' => $row['position'] > 10 ? 'page-two' : 'top-ten', ...self::metrics($row)];
        }, array_slice(array_values($rows), 0, $limit))];
    }

    /** Forget what Google said: after the key or the property changed. */
    public function forget(): void
    {
        Cache::forever('cms-analytics-search-v', (int) Cache::get('cms-analytics-search-v', 0) + 1);
    }

    /** @return list<array{keys: list<string>, clicks: int, impressions: int, ctr: float, position: float}> */
    private function query(array $dates, array $dimensions, array $filters, int $limit): array
    {
        $property = $this->property();
        $body = ['startDate' => $dates[0], 'endDate' => $dates[1], 'dimensions' => $dimensions, 'rowLimit' => min(25_000, $limit), 'dataState' => 'all',
            ...($filters ? ['dimensionFilterGroups' => [['filters' => $filters]]] : [])];

        return $this->remember('query:'.$property.':'.json_encode($body), function () use ($property, $body) {
            $response = $this->api()->post(self::API.'/sites/'.rawurlencode($property).'/searchAnalytics/query', $body);
            $this->check($response);

            return array_map(static fn (array $row) => [
                'keys' => array_map('strval', (array) ($row['keys'] ?? [])),
                'clicks' => (int) ($row['clicks'] ?? 0), 'impressions' => (int) ($row['impressions'] ?? 0),
                'ctr' => (float) ($row['ctr'] ?? 0), 'position' => (float) ($row['position'] ?? 0),
            ], (array) ($response->json('rows') ?? []));
        });
    }

    /** The setting's property, or the one for this site's host that the account can see: a domain property first. */
    private function property(): string
    {
        $set = trim((string) $this->setting('search_property'));

        if ($set !== '') {
            return $set;
        }

        $sites = $this->remember('sites', function () {
            $response = $this->api()->get(self::API.'/sites');
            $this->check($response);

            return array_values(array_filter(array_map(static fn ($s) => (string) ($s['siteUrl'] ?? ''), (array) ($response->json('siteEntry') ?? []))));
        });

        $url = rtrim($this->cms->url(), '/');
        $host = (string) preg_replace('/^www\./', '', strtolower((string) parse_url($url, PHP_URL_HOST)));

        foreach (["sc-domain:{$host}", $url.'/', preg_replace('#//www\.#', '//', $url).'/', preg_replace('#//#', '//www.', $url, 1).'/'] as $candidate) {
            if (in_array($candidate, $sites, true)) {
                return $candidate;
            }
        }

        throw new AbilityException("The service account can't see a Search Console property for this site: add it as a user of the property, or name the property in the settings.", 'forbidden', ['problem' => 'access']);
    }

    private function api(): \Illuminate\Http\Client\PendingRequest
    {
        return Http::withToken($this->token())->acceptJson()->timeout(15);
    }

    /** An access token for the service account, from a token it signs itself. Kept until shortly before it runs out. */
    private function token(): string
    {
        $key = $this->key() ?? throw new AbilityException('Search Console is not connected: its service account key is missing or not one.', 'conflict', ['problem' => 'key']);
        $cache = 'cms-analytics-search-token:'.md5($key['client_email'].$key['private_key_id']);

        if (is_string($token = Cache::get($cache))) {
            return $token;
        }

        $now = time();
        $segment = static fn (array $data) => rtrim(strtr(base64_encode((string) json_encode($data)), '+/', '-_'), '=');
        $unsigned = $segment(['alg' => 'RS256', 'typ' => 'JWT', 'kid' => $key['private_key_id']]).'.'.$segment([
            'iss' => $key['client_email'], 'scope' => self::SCOPE, 'aud' => $key['token_uri'], 'iat' => $now, 'exp' => $now + 3600,
        ]);

        if (! @openssl_sign($unsigned, $signature, $key['private_key'], OPENSSL_ALGO_SHA256)) {
            throw new AbilityException('The Search Console key has no private key that can sign.', 'conflict', ['problem' => 'key']);
        }

        try {
            $response = Http::asForm()->timeout(15)->post($key['token_uri'], [
                'grant_type' => 'urn:ietf:params:oauth:grant-type:jwt-bearer',
                'assertion' => $unsigned.'.'.rtrim(strtr(base64_encode($signature), '+/', '-_'), '='),
            ]);
        } catch (ConnectionException) {
            throw new AbilityException('Google could not be reached. Try again in a moment.', 'failed', ['problem' => 'unreachable']);
        }

        if (! $response->successful() || ! is_string($token = $response->json('access_token'))) {
            throw new AbilityException('Google did not accept the Search Console key: it may have been deleted or disabled.', 'conflict', ['problem' => 'key']);
        }

        Cache::put($cache, $token, max(60, (int) $response->json('expires_in', 3600) - 300));

        return $token;
    }

    /** The service account key from the settings, when it is one. */
    private function key(): ?array
    {
        $key = json_decode(trim((string) $this->setting('search_key')), true);

        return is_array($key) && ($key['type'] ?? null) === 'service_account' && is_string($key['client_email'] ?? null) && is_string($key['private_key'] ?? null)
            ? [...$key, 'private_key_id' => (string) ($key['private_key_id'] ?? ''), 'token_uri' => is_string($key['token_uri'] ?? null) && str_starts_with($key['token_uri'], 'https://') ? $key['token_uri'] : 'https://oauth2.googleapis.com/token']
            : null;
    }

    private function check(Response $response): void
    {
        if ($response->successful()) {
            return;
        }

        $status = $response->status();

        throw match (true) {
            $status === 401 => new AbilityException('Google did not accept the Search Console key.', 'conflict', ['problem' => 'key']),
            $status === 403 || $status === 404 => new AbilityException("The service account can't read this Search Console property: add it as a user of the property.", 'forbidden', ['problem' => 'access']),
            default => new AbilityException('Search Console did not answer. Try again in a moment.', 'failed', ['problem' => 'unreachable']),
        };
    }

    /** @template T @param callable(): T $fetch @return T */
    private function remember(string $key, callable $fetch): mixed
    {
        $version = (int) Cache::get('cms-analytics-search-v', 0);
        $cache = 'cms-analytics-search:'.$version.':'.md5($this->setting('search_key').$key);

        if (($hit = Cache::get($cache)) !== null) {
            return $hit;
        }

        try {
            $value = $fetch();
        } catch (ConnectionException) {
            throw new AbilityException('Google could not be reached. Try again in a moment.', 'failed', ['problem' => 'unreachable']);
        }

        Cache::put($cache, $value, 3600);

        return $value;
    }

    /** @return array{0: string, 1: string} Search Console's days: today at the latest */
    private function dates(Range $range): array
    {
        return $range->since !== null ? [$range->to->toDateString(), $range->to->toDateString()] : $range->dates();
    }

    private function filters(?string $page, ?string $query): array
    {
        return array_values(array_filter([
            $page !== null ? ['dimension' => 'page', 'operator' => 'equals', 'expression' => rtrim($this->cms->url(), '/').($page === '/' ? '/' : $page)] : null,
            $query !== null ? ['dimension' => 'query', 'operator' => 'equals', 'expression' => $query] : null,
        ]));
    }

    private function title(string $path): ?string
    {
        try {
            $entry = $this->cms->entries()->findByUri($path);
        } catch (Throwable) {
            return null;
        }

        return ($entry?->working()['title'] ?? '') ?: null;
    }

    private function setting(string $key): mixed
    {
        return $this->cms->setting(Collector::SETTINGS, $key);
    }

    /** @param array{clicks: int, impressions: int, ctr: float, position: float}|null $row */
    private static function metrics(?array $row): array
    {
        return [
            'clicks' => (int) ($row['clicks'] ?? 0),
            'impressions' => (int) ($row['impressions'] ?? 0),
            'ctr' => round((float) ($row['ctr'] ?? 0), 4),
            'position' => $row === null || ($row['impressions'] ?? 0) === 0 ? null : round((float) $row['position'], 1),
        ];
    }

    /** Days added up into a week or month: position weighed by how often each day showed the site. */
    private static function sum(array $rows): array
    {
        $clicks = array_sum(array_column($rows, 'clicks'));
        $impressions = array_sum(array_column($rows, 'impressions'));
        $weighted = array_sum(array_map(static fn ($r) => $r['position'] * $r['impressions'], $rows));

        return ['clicks' => $clicks, 'impressions' => $impressions, 'ctr' => $impressions > 0 ? round($clicks / $impressions, 4) : 0.0, 'position' => $impressions > 0 ? round($weighted / $impressions, 1) : null];
    }
}
