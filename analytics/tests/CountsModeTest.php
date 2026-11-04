<?php

declare(strict_types=1);

namespace Cms\Analytics\Tests;

use Carbon\CarbonImmutable;
use Cms\Abilities\AbilityContext;
use Cms\Analytics\Collector;
use Cms\Analytics\Snippet;
use Cms\Support\DailySalt;
use Cms\Tests\TestCase;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\RateLimiter;

final class CountsModeTest extends TestCase
{
    private const string BROWSER = 'Mozilla/5.0 (Macintosh; Intel Mac OS X 10_15_7) AppleWebKit/605.1.15 (KHTML, like Gecko) Version/17.0 Safari/605.1.15';

    protected function plugins(): array
    {
        return ['letterncms/analytics'];
    }

    protected function setUp(): void
    {
        parent::setUp();
        RateLimiter::clear('cms-analytics-views');
        $this->cms()->abilities()->execute('entries/create', ['collection' => 'pages', 'title' => 'Pricing', 'status' => 'published'], AbilityContext::forUser($this->admin(), 'admin'));
    }

    /** @return \Illuminate\Testing\TestResponse */
    private function visit(string $path = '/pricing', array $headers = [], array $cookies = [])
    {
        return $this->call('GET', '/api/analytics/view', ['p' => $path], $cookies, [], ['HTTP_USER_AGENT' => self::BROWSER, 'REMOTE_ADDR' => '203.0.113.7', ...$headers]);
    }

    private function views(): int
    {
        return (int) DB::table('analytics_pages')->sum('views');
    }

    public function test_counts_is_the_default_and_a_page_carries_an_image_and_no_script(): void
    {
        $this->assertSame(Collector::COUNTS, $this->app->make(Collector::class)->mode());

        $foot = implode('', $this->cms()->hooks()->filter('site.foot', [], (object) ['editor' => false, 'preview' => false, 'uri' => '/de/preise?x']));

        $this->assertStringContainsString('<img src="/api/analytics/view?p=%2Fde%2Fpreise%3Fx"', $foot);
        $this->assertStringContainsString('referrerpolicy="no-referrer"', $foot);
        $this->assertStringNotContainsString('<script', $foot);
        $this->assertStringNotContainsString('localStorage', $foot);
        $this->assertStringNotContainsString('innerWidth', $foot);
        $this->assertSame([], $this->cms()->hooks()->filter('site.foot', [], (object) ['editor' => false, 'preview' => true, 'uri' => '/']));
        $this->assertSame([], $this->cms()->hooks()->filter('site.foot', [], (object) ['editor' => true, 'preview' => false, 'uri' => '/']));
    }

    public function test_a_view_is_a_number_and_nothing_about_the_visitor_stays(): void
    {
        $response = $this->visit();

        $response->assertOk()->assertHeader('Content-Type', 'image/gif')->assertHeader('Cache-Control', 'no-store, private');
        $this->assertSame(43, strlen((string) $response->getContent()));
        $this->visit();

        $this->assertSame(2, $this->views());
        $this->assertSame(0, DB::table('analytics_visitors')->count());
        $this->assertFileDoesNotExist(storage_path('app/salts/analytics.json'));
        $this->assertSame(['day', 'path', 'views', 'visitors'], array_keys((array) DB::table('analytics_pages')->first()));
        $this->assertSame(0, (int) DB::table('analytics_pages')->value('visitors'));
    }

    public function test_every_answer_is_the_same_and_only_real_views_count(): void
    {
        $same = (string) $this->visit()->getContent();
        $this->assertSame(1, $this->views());

        $cases = [
            'unknown path' => $this->visit('/made-up'),
            'not a path' => $this->visit('https://example.com/'),
            'gpc' => $this->visit(headers: ['HTTP_SEC_GPC' => '1']),
            'dnt' => $this->visit(headers: ['HTTP_DNT' => '1']),
            'embedded elsewhere' => $this->visit(headers: ['HTTP_SEC_FETCH_SITE' => 'cross-site']),
            'a subdomain' => $this->visit(headers: ['HTTP_SEC_FETCH_SITE' => 'same-site']),
            'a bot' => $this->visit(headers: ['HTTP_USER_AGENT' => 'Mozilla/5.0 (compatible; Googlebot/2.1)']),
            'someone using the admin' => $this->visit(cookies: [Collector::STAFF_COOKIE => '1']),
        ];

        foreach ($cases as $name => $response) {
            $response->assertOk();
            $this->assertSame($same, (string) $response->getContent(), $name);
            $this->assertSame(1, $this->views(), $name);
        }

        $this->visit(headers: ['HTTP_SEC_FETCH_SITE' => 'same-origin']);
        $this->assertSame(2, $this->views());

        $this->visit(cookies: ['cms_session' => 'a-visitor-with-a-session']);
        $this->assertSame(3, $this->views(), 'a session is no reason to skip someone');
    }

    public function test_a_paginated_page_and_the_home_page_count(): void
    {
        $this->visit('/');
        $this->visit('/pricing/page/2');

        $this->assertSame(['/', '/pricing/page/2'], DB::table('analytics_pages')->orderBy('path')->pluck('path')->all());
    }

    public function test_past_the_limit_a_view_gets_the_same_answer_and_no_row(): void
    {
        for ($i = 0; $i < 600; $i++) {
            RateLimiter::hit('cms-analytics-views', 60);
        }

        $this->visit()->assertOk();
        $this->assertSame(0, $this->views());

        RateLimiter::clear('cms-analytics-views');
        $this->visit();
        $this->assertSame(1, $this->views());
    }

    public function test_the_staff_cookie_comes_with_signing_in_to_the_admin_and_goes_with_signing_out(): void
    {
        $admin = $this->admin();
        Auth::guard('web')->login($admin);
        $cookie = collect(\Illuminate\Support\Facades\Cookie::getQueuedCookies())->firstWhere(fn ($c) => $c->getName() === Collector::STAFF_COOKIE);

        $this->assertNotNull($cookie);
        $this->assertTrue($cookie->isHttpOnly());
        $this->assertSame('lax', strtolower((string) $cookie->getSameSite()));
        $this->assertSame('/', $cookie->getPath());

        Auth::guard('web')->logout();
        $gone = collect(\Illuminate\Support\Facades\Cookie::getQueuedCookies())->firstWhere(fn ($c) => $c->getName() === Collector::STAFF_COOKIE);
        $this->assertTrue($gone->isCleared());
    }

    public function test_a_member_without_admin_access_is_counted_like_anyone(): void
    {
        \Illuminate\Support\Facades\Cookie::queue(\Illuminate\Support\Facades\Cookie::make('other', 'x'));
        Auth::guard('web')->login($this->userWithRole('subscriber'));

        $names = collect(\Illuminate\Support\Facades\Cookie::getQueuedCookies())->map(fn ($c) => $c->getName());
        $this->assertNotContains(Collector::STAFF_COOKIE, $names);
    }

    public function test_going_back_to_page_views_only_deletes_everything_about_visitors(): void
    {
        $settings = $this->cms()->settingsRegistry();
        $collector = $this->app->make(Collector::class);
        $settings->save('analytics', ['mode' => 'visitors']);
        $collector->record('/pricing', null, null, 1440, '203.0.113.7', self::BROWSER, ['localhost']);
        $this->assertFileExists(storage_path('app/salts/analytics.json'));
        $this->assertSame(1, DB::table('analytics_visitors')->count());

        $settings->save('analytics', ['mode' => 'counts']);

        $this->assertSame(0, DB::table('analytics_visitors')->count());
        $this->assertFileDoesNotExist(storage_path('app/salts/analytics.json'));
        $this->assertSame(1, $this->views(), 'the views stay');
        $this->assertSame(0, (int) DB::table('analytics_pages')->value('visitors'));

        $collector->record('/pricing', null, null, 1440, '203.0.113.7', self::BROWSER, ['localhost']);
        $this->assertSame(0, DB::table('analytics_visitors')->count(), 'visitors mode code stays out of counts mode');
    }

    public function test_visitors_mode_waits_for_a_signal_from_the_browser(): void
    {
        $this->cms()->settingsRegistry()->save('analytics', ['mode' => 'visitors']);
        $foot = implode('', $this->cms()->hooks()->filter('site.foot', [], (object) ['editor' => false, 'preview' => false, 'uri' => '/']));

        $this->assertStringContainsString('/api/analytics/hit', $foot);
        $this->assertStringContainsString('w.cmsConsent', $foot);
        $this->assertStringContainsString('cms:consent', $foot);
        $this->assertStringNotContainsString('/api/analytics/view', $foot);

        $this->assertStringNotContainsString('cmsConsent', Snippet::html('/api/analytics/hit', false));
    }

    public function test_the_hit_endpoint_counts_nothing_in_counts_mode(): void
    {
        $this->call('POST', '/api/analytics/hit', [], [], [], ['HTTP_USER_AGENT' => self::BROWSER, 'CONTENT_TYPE' => 'text/plain'], (string) json_encode(['p' => '/pricing']))->assertNoContent();

        $this->assertSame(0, $this->views());
        $this->assertSame(0, DB::table('analytics_visitors')->count());
    }

    public function test_reports_say_what_they_can_and_null_for_what_they_cannot(): void
    {
        $collector = $this->app->make(Collector::class);
        $now = CarbonImmutable::now((string) config('app.timezone'));
        $collector->recordView('/pricing', $now);
        $collector->recordView('/pricing', $now);
        $collector->recordView('/', $now);
        $collector->recordView('/', $now->subDays(8));

        $ctx = AbilityContext::forUser($this->admin(), 'admin');
        $abilities = $this->cms()->abilities();
        $summary = $abilities->execute('analytics/summary', ['period' => '7d'], $ctx);

        $this->assertSame('counts', $summary['mode']);
        $this->assertSame(3, $summary['views']);
        $this->assertSame(1, $summary['previous']['views']);
        $this->assertNull($summary['visitors']);
        $this->assertNull($summary['bounceRate']);
        $this->assertNull($summary['current']);
        $this->assertTrue($summary['tracked']);
        $this->assertSame(3, end($summary['series'])['views']);

        $pages = $abilities->execute('analytics/breakdown', ['period' => '7d', 'dimension' => 'pages'], $ctx);
        $this->assertSame(['/pricing', '/'], array_column($pages['items'], 'path'));
        $this->assertSame([2, 1], array_column($pages['items'], 'views'));

        $languages = $abilities->execute('analytics/breakdown', ['period' => '7d', 'dimension' => 'languages'], $ctx);
        $this->assertSame(['en'], array_column($languages['items'], 'key'));
        $this->assertSame(3, $languages['total']);

        $this->assertSame('mode', $abilities->execute('analytics/breakdown', ['period' => '7d', 'dimension' => 'sources'], $ctx)['unavailable']);
    }

    public function test_a_salt_is_not_even_made_in_counts_mode(): void
    {
        $this->visit();
        $this->assertFileDoesNotExist(storage_path('app/salts/analytics.json'));
        // The helper still works for visitors mode and forms.
        $this->assertNotSame('', DailySalt::today('forms'));
    }


    public function test_the_place_is_a_view_of_what_is_counted(): void
    {
        $view = fn () => json_encode($this->cms()->abilities()->execute('views/get', ['id' => 'analytics'], AbilityContext::forUser($this->admin(), 'admin'))['root'], JSON_UNESCAPED_SLASHES);
        $this->assertStringContainsString('No visits yet', $view());

        DB::table('analytics_pages')->insert(['day' => now()->toDateString(), 'path' => '/pricing', 'views' => 3]);
        $this->assertStringContainsString('analytics/overview', $view());
        $this->assertStringNotContainsString('"sources"', $view());
        $this->assertEquals(1, $this->cms()->abilities()->execute('analytics/breakdown', ['dimension' => 'pages'], AbilityContext::system())['items'][0]['share']);
    }
}
