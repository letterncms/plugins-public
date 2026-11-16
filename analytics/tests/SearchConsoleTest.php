<?php

declare(strict_types=1);

namespace Cms\Analytics\Tests;

use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Cms\Abilities\AbilityContext;
use Cms\Tests\TestCase;

/** Search Console, against a Google that answers as the real one does. */
final class SearchConsoleTest extends TestCase
{
    protected function plugins(): array
    {
        return ['letterncms/analytics'];
    }

    private function ask(array $input): array
    {
        return $this->cms()->abilities()->execute('analytics/search', $input, AbilityContext::forUser($this->admin(), 'admin'));
    }

    /** A service account key as Google gives it, with a private key made for the test. */
    private function key(): string
    {
        $pem = '';
        openssl_pkey_export(openssl_pkey_new(['private_key_bits' => 2048, 'private_key_type' => OPENSSL_KEYTYPE_RSA]), $pem);

        return (string) json_encode(['type' => 'service_account', 'project_id' => 'p', 'private_key_id' => 'k1', 'private_key' => $pem, 'client_email' => 'cms@p.iam.gserviceaccount.com', 'token_uri' => 'https://oauth2.googleapis.com/token']);
    }

    private function google(array $sites = ['sc-domain:localhost']): void
    {
        Http::fake([
            'oauth2.googleapis.com/token' => Http::response(['access_token' => 'token-1', 'expires_in' => 3599]),
            'www.googleapis.com/webmasters/v3/sites' => Http::response(['siteEntry' => array_map(static fn ($s) => ['siteUrl' => $s, 'permissionLevel' => 'siteRestrictedUser'], $sites)]),
            'www.googleapis.com/webmasters/v3/sites/*/searchAnalytics/query' => function (Request $request) {
                $dimensions = $request['dimensions'];

                return Http::response(['rows' => match ($dimensions) {
                    [] => [['clicks' => 40, 'impressions' => 1000, 'ctr' => 0.04, 'position' => 7.25]],
                    ['date'] => [['keys' => [now()->toDateString()], 'clicks' => 40, 'impressions' => 1000, 'ctr' => 0.04, 'position' => 7.25]],
                    ['query'] => [['keys' => ['headless cms'], 'clicks' => 30, 'impressions' => 600, 'ctr' => 0.05, 'position' => 3.1]],
                    ['page'] => [['keys' => ['http://localhost/pricing'], 'clicks' => 12, 'impressions' => 300, 'ctr' => 0.04, 'position' => 5.0]],
                    ['query', 'page'] => [
                        ['keys' => ['cms pricing', 'http://localhost/pricing'], 'clicks' => 3, 'impressions' => 400, 'ctr' => 0.0075, 'position' => 12.4],
                        ['keys' => ['best cms', 'http://localhost/'], 'clicks' => 5, 'impressions' => 900, 'ctr' => 0.0055, 'position' => 6.2],
                        ['keys' => ['my site', 'http://localhost/'], 'clicks' => 80, 'impressions' => 100, 'ctr' => 0.8, 'position' => 1.0],
                        ['keys' => ['rare', 'http://localhost/'], 'clicks' => 0, 'impressions' => 3, 'ctr' => 0, 'position' => 9.0],
                    ],
                    default => [],
                }]);
            },
        ]);
    }

    public function test_without_a_key_it_says_so_and_asks_nothing(): void
    {
        Http::fake();

        $this->assertSame(['connected' => false, 'property' => null, 'problem' => null], $this->ask(['report' => 'status']));
        Http::assertNothingSent();

        $this->cms()->settingsRegistry()->save('analytics', ['search_key' => 'not json']);
        $this->assertSame('key', $this->ask(['report' => 'status'])['problem']);
    }

    public function test_it_signs_in_as_the_service_account_and_finds_the_property(): void
    {
        $this->google();
        $this->cms()->settingsRegistry()->save('analytics', ['search_key' => $this->key()]);

        $this->assertSame(['connected' => true, 'property' => 'sc-domain:localhost', 'problem' => null], $this->ask(['report' => 'status']));

        Http::assertSent(function (Request $request) {
            if (! str_contains($request->url(), 'oauth2.googleapis.com')) {
                return false;
            }

            [$header, $claims, $signature] = explode('.', $request['assertion']);
            $claims = json_decode(base64_decode(strtr($claims, '-_', '+/')), true);

            return $request['grant_type'] === 'urn:ietf:params:oauth:grant-type:jwt-bearer' && $signature !== ''
                && $claims['iss'] === 'cms@p.iam.gserviceaccount.com' && $claims['scope'] === 'https://www.googleapis.com/auth/webmasters.readonly';
        });

        // The key itself is kept encrypted, out of the site's tracked settings.
        $this->assertStringNotContainsString('PRIVATE KEY', (string) @file_get_contents(base_path('config/analytics.yaml')));
    }

    public function test_reports_read_clicks_searches_pages_and_opportunities(): void
    {
        $this->google();
        $this->cms()->settingsRegistry()->save('analytics', ['search_key' => $this->key()]);

        $summary = $this->ask(['report' => 'summary', 'period' => '7d']);
        $this->assertSame([40, 1000, 0.04, 7.3], [$summary['clicks'], $summary['impressions'], $summary['ctr'], $summary['position']]);
        $this->assertCount(7, $summary['series']);
        $this->assertSame(40, end($summary['series'])['clicks']);

        $this->assertSame('headless cms', $this->ask(['report' => 'queries'])['items'][0]['label']);
        $page = $this->ask(['report' => 'pages'])['items'][0];
        $this->assertSame(['/pricing', 'http://localhost/pricing'], [$page['path'], $page['url']]);

        // Close to the top and shown often enough: the one at the top and the rare one are not.
        $opportunities = $this->ask(['report' => 'opportunities']);
        $this->assertSame(['best cms', 'cms pricing'], array_column($opportunities['items'], 'label'));
        $this->assertSame(['top-ten', 'page-two'], array_column($opportunities['items'], 'kind'));

        // One page: Search Console is asked about that page's address.
        $this->ask(['report' => 'summary', 'page' => '/pricing']);
        Http::assertSent(static fn (Request $r) => str_contains($r->url(), 'searchAnalytics') && ($r['dimensionFilterGroups'][0]['filters'][0]['expression'] ?? null) === 'http://localhost/pricing');
    }

    public function test_an_account_without_access_is_told_what_to_do(): void
    {
        $this->google(sites: []);
        $this->cms()->settingsRegistry()->save('analytics', ['search_key' => $this->key()]);

        $this->assertSame(['connected' => false, 'property' => null, 'problem' => 'access'], $this->ask(['report' => 'status']));
    }

    public function test_a_property_the_account_cannot_read_is_refused(): void
    {
        Http::fake(['oauth2.googleapis.com/token' => Http::response(['access_token' => 't']), '*' => Http::response(['error' => ['code' => 403]], 403)]);
        $this->cms()->settingsRegistry()->save('analytics', ['search_key' => $this->key(), 'search_property' => 'https://example.com/']);

        try {
            $this->ask(['report' => 'summary']);
            $this->fail('A property the account cannot read is refused.');
        } catch (\Cms\Abilities\AbilityException $e) {
            $this->assertSame(['forbidden', 'access'], [$e->type, $e->details['problem']]);
        }
    }

    public function test_a_refused_key_is_a_key_problem(): void
    {
        Http::fake(['oauth2.googleapis.com/token' => Http::response(['error' => 'invalid_grant'], 400)]);
        $this->cms()->settingsRegistry()->save('analytics', ['search_key' => $this->key()]);

        $this->assertSame('key', $this->ask(['report' => 'status'])['problem']);
    }
}
