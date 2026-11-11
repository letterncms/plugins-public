<?php

declare(strict_types=1);

namespace Cms\Analytics\Tests;

use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Cms\Abilities\AbilityContext;
use Cms\Abilities\AbilityException;
use Cms\Analytics\Collector;
use Cms\Tests\TestCase;

final class AnalyticsTest extends TestCase
{
    private const string SAFARI = 'Mozilla/5.0 (Macintosh; Intel Mac OS X 10_15_7) AppleWebKit/605.1.15 (KHTML, like Gecko) Version/17.0 Safari/605.1.15';

    private const string ANDROID = 'Mozilla/5.0 (Linux; Android 14; Pixel 8) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/129.0 Mobile Safari/537.36';

    protected function plugins(): array
    {
        return ['letterncms/analytics'];
    }

    protected function setUp(): void
    {
        parent::setUp();
        $this->cms()->settingsRegistry()->save('analytics', ['mode' => 'visitors']);
    }

    private function hit(string $path, string $ua = self::SAFARI, string $ip = '203.0.113.7', array $extra = [], array $headers = []): int
    {
        return $this->call('POST', '/api/analytics/hit', [], [], [], [
            'REMOTE_ADDR' => $ip,
            'HTTP_USER_AGENT' => $ua,
            'CONTENT_TYPE' => 'text/plain',
            ...$headers,
        ], (string) json_encode(['p' => $path, 'w' => 1440, ...$extra]))->getStatusCode();
    }

    public function test_a_view_is_counted_without_storing_anything_personal(): void
    {
        $this->assertSame(204, $this->hit('/pricing?plan=pro#faq', extra: ['r' => 'https://www.google.com/search?q=cms']));
        $this->assertSame(204, $this->hit('/pricing'));
        $this->assertSame(204, $this->hit('/about/'));

        $visitor = DB::table('analytics_visitors')->sole();
        $this->assertSame(3, (int) $visitor->views);
        $this->assertSame('/pricing', $visitor->entry);
        $this->assertSame('Google', $visitor->source);
        $this->assertSame(['desktop', 'Safari', 'macOS'], [$visitor->device, $visitor->browser, $visitor->os]);
        $this->assertSame(16, strlen($visitor->visitor));

        // The IP address and the browser string are nowhere.
        $this->assertStringNotContainsString('203.0.113.7', json_encode(DB::table('analytics_visitors')->get()));
        $this->assertStringNotContainsString('Macintosh', json_encode(DB::table('analytics_visitors')->get()));

        $pages = DB::table('analytics_pages')->orderBy('path')->get(['path', 'views', 'visitors'])->map(fn ($p) => (array) $p)->all();
        $this->assertSame([['path' => '/about', 'views' => 1, 'visitors' => 1], ['path' => '/pricing', 'views' => 2, 'visitors' => 1]], array_map(fn ($p) => array_map(fn ($v) => is_numeric($v) ? (int) $v : $v, $p), $pages));
    }

    public function test_another_device_is_another_visitor_and_tomorrow_nobody_is_recognized(): void
    {
        $collector = $this->app->make(Collector::class);
        $today = CarbonImmutable::parse('2026-10-26 10:00', 'UTC');

        $collector->record('/', null, null, 390, '203.0.113.7', self::ANDROID, ['localhost'], $today);
        $collector->record('/', null, null, 1440, '203.0.113.7', self::SAFARI, ['localhost'], $today);
        $collector->record('/', null, null, 390, '203.0.113.7', self::ANDROID, ['localhost'], $today->addDay());

        $rows = DB::table('analytics_visitors')->orderBy('id')->get();
        $this->assertCount(3, $rows);
        $this->assertSame('mobile', $rows[0]->device);
        // Same person, same phone, the next day: a new salt makes a new, unrelated hash.
        $this->assertNotSame($rows[0]->visitor, $rows[2]->visitor);
    }

    public function test_bots_people_who_opt_out_excluded_paths_and_other_sites_are_not_counted(): void
    {
        $this->hit('/', 'Mozilla/5.0 (compatible; Googlebot/2.1; +http://www.google.com/bot.html)');
        $this->hit('/', '');
        $this->hit('/', headers: ['HTTP_DNT' => '1']);
        $this->hit('/', headers: ['HTTP_SEC_GPC' => '1']);
        $this->hit('/', headers: ['HTTP_ORIGIN' => 'https://spam.example']);
        $this->hit('https://example.com/');
        $this->hit('//evil.example/x');

        $this->cms()->settingsRegistry()->save('analytics', ['exclude' => ['/drafts/*', '/secret']]);
        $this->hit('/drafts/new-thing');
        $this->hit('/secret');

        $this->assertSame(0, DB::table('analytics_visitors')->count());

        $this->cms()->settingsRegistry()->save('analytics', ['enabled' => false]);
        $this->hit('/');
        $this->assertSame(0, DB::table('analytics_visitors')->count());
    }

    public function test_own_pages_are_navigation_and_campaigns_name_their_source(): void
    {
        $this->hit('/a', ip: '198.51.100.1', extra: ['r' => 'http://localhost/blog']);
        $this->hit('/b', ip: '198.51.100.2', extra: ['r' => 'https://news.ycombinator.com/item?id=1']);
        $this->hit('/c', ip: '198.51.100.3', extra: ['s' => 'spring-newsletter', 'r' => 'https://mail.example.com/']);

        $this->assertSame([null, 'Hacker News', 'spring-newsletter'], DB::table('analytics_visitors')->orderBy('id')->pluck('source')->all());
    }

    public function test_editors_start_with_analytics(): void
    {
        $this->assertContains('analytics.view', app(\Cms\Users\Roles::class)->defaults()['editor']['permissions']);
    }

    public function test_reports_sum_up_a_period_and_compare_it_with_the_one_before(): void
    {
        $collector = $this->app->make(Collector::class);
        $now = CarbonImmutable::now((string) config('app.timezone'));

        // Two visitors today (one bounces), one eight days ago (the previous week).
        $collector->record('/', null, null, 1440, '192.0.2.1', self::SAFARI, ['localhost'], $now);
        $collector->record('/docs', null, null, 1440, '192.0.2.1', self::SAFARI, ['localhost'], $now);
        $collector->record('/docs', 'https://github.com/cms', null, 390, '192.0.2.2', self::ANDROID, ['localhost'], $now);
        $collector->record('/', null, null, 1440, '192.0.2.3', self::SAFARI, ['localhost'], $now->subDays(8));

        $ctx = AbilityContext::forUser($this->admin(), 'admin');
        $summary = $this->cms()->abilities()->execute('analytics/summary', ['period' => '7d'], $ctx);

        $this->assertSame(2, $summary['visitors']);
        $this->assertSame(3, $summary['views']);
        $this->assertEquals(1.5, $summary['viewsPerVisit']);
        $this->assertEquals(0.5, $summary['bounceRate']);
        $this->assertSame(1, $summary['previous']['visitors']);
        $this->assertCount(7, $summary['series']);
        $this->assertSame(2, end($summary['series'])['visitors']);
        $this->assertTrue($summary['tracked']);

        $pages = $this->cms()->abilities()->execute('analytics/breakdown', ['period' => '7d', 'dimension' => 'pages'], $ctx);
        $this->assertSame(['/docs', '/'], array_column($pages['items'], 'path'));
        $this->assertSame([2, 1], array_column($pages['items'], 'visitors'));

        $sources = $this->cms()->abilities()->execute('analytics/breakdown', ['period' => '7d', 'dimension' => 'sources'], $ctx);
        $this->assertEqualsCanonicalizing([null, 'GitHub'], array_column($sources['items'], 'key'));

        $devices = $this->cms()->abilities()->execute('analytics/breakdown', ['period' => '7d', 'dimension' => 'devices'], $ctx);

        // Visitors mode knows every dimension: each answers in the shape that is said.
        foreach (\Cms\Analytics\Reports::DIMENSIONS as $dimension) {
            $this->assertArrayNotHasKey('unavailable', $this->cms()->abilities()->execute('analytics/breakdown', ['period' => '7d', 'dimension' => $dimension], $ctx));
        }
        $this->assertEqualsCanonicalizing(['desktop', 'mobile'], array_column($devices['items'], 'key'));
        $this->assertEqualsCanonicalizing(['desktop', 'phone'], array_column($devices['items'], 'icon'));
        $this->assertEqualsWithDelta(1, array_sum(array_column($devices['items'], 'share')), 0.001);
    }

    public function test_reports_need_the_permission(): void
    {
        $this->expectException(AbilityException::class);
        $this->cms()->abilities()->execute('analytics/summary', [], AbilityContext::forUser($this->userWithRole('author'), 'admin'));
    }

    public function test_published_pages_carry_the_snippet_and_previews_do_not(): void
    {
        $hooks = $this->cms()->hooks();

        $page = $hooks->filter('site.foot', [], (object) ['editor' => false, 'preview' => false]);
        $this->assertStringContainsString('/api/analytics/hit', implode('', $page));
        $this->assertStringNotContainsString('localStorage', implode('', $page));
        $this->assertSame([], $hooks->filter('site.foot', [], (object) ['editor' => false, 'preview' => true]));
        $this->assertSame([], $hooks->filter('site.foot', [], (object) ['editor' => true, 'preview' => false]));
    }

    public function test_old_days_are_forgotten(): void
    {
        $collector = $this->app->make(Collector::class);
        $collector->record('/', null, null, 1440, '192.0.2.1', self::SAFARI, ['localhost'], CarbonImmutable::now()->subDays(40));
        $collector->record('/', null, null, 1440, '192.0.2.1', self::SAFARI, ['localhost'], CarbonImmutable::now());

        $this->cms()->settingsRegistry()->save('analytics', ['retention_days' => 30]);
        $collector->prune();

        $this->assertSame(1, DB::table('analytics_visitors')->count());
        $this->assertSame(1, DB::table('analytics_pages')->count());
    }
}
