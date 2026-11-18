<?php

declare(strict_types=1);

namespace Cms\Analytics\Tests;

use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Cms\Abilities\AbilityContext;
use Cms\Abilities\AbilityException;
use Cms\Analytics\Collector;
use Cms\Analytics\Countries;
use Cms\Analytics\Reports;
use Cms\Analytics\Snippet;
use Cms\Analytics\Sources;
use Cms\Tests\TestCase;

/** Visitors mode: visits, page views, engagement, events and every report on them. */
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

        foreach (['Pricing', 'Docs', 'About', 'Thank You', 'Signup'] as $title) {
            $this->cms()->abilities()->execute('entries/create', ['collection' => 'pages', 'title' => $title, 'status' => 'published'], $this->ctx());
        }
    }

    private function ctx(): AbilityContext
    {
        return AbilityContext::forUser($this->admin(), 'admin');
    }

    private function ask(string $ability, array $input = []): array
    {
        return $this->cms()->abilities()->execute($ability, $input, $this->ctx());
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

    /** What the script sends, straight to the collector, at a time of the test's choosing. */
    private function record(string $path, array $hit = [], string $ip = '192.0.2.1', string $ua = self::SAFARI, ?CarbonImmutable $at = null): bool
    {
        return $this->app->make(Collector::class)->record(['p' => $path, 'w' => 1440, ...$hit], $ip, $ua, ['localhost'], $at ?? CarbonImmutable::now());
    }

    public function test_a_view_is_counted_without_storing_anything_personal(): void
    {
        $this->assertSame(204, $this->hit('/pricing?plan=pro#faq', extra: ['r' => 'https://www.google.com/search?q=cms', 'z' => 'Europe/Brussels']));
        $this->assertSame(204, $this->hit('/pricing'));
        $this->assertSame(204, $this->hit('/about/'));

        $visit = DB::table('analytics_visits')->sole();
        $this->assertSame(3, (int) $visit->views);
        $this->assertSame(['/pricing', '/about'], [$visit->entry, $visit->exit]);
        $this->assertSame(['Google', 'search', 'BE'], [$visit->source, $visit->channel, $visit->country]);
        $this->assertSame(['desktop', 'Safari', 'macOS'], [$visit->device, $visit->browser, $visit->os]);
        $this->assertSame(16, strlen($visit->visitor));
        $this->assertSame(['/pricing', '/pricing', '/about'], DB::table('analytics_views')->orderBy('id')->pluck('path')->all());

        // The IP address, the browser string and the referrer's address are nowhere.
        $everything = json_encode([DB::table('analytics_visits')->get(), DB::table('analytics_views')->get(), DB::table('analytics_pages')->get()]);
        $this->assertStringNotContainsString('203.0.113.7', $everything);
        $this->assertStringNotContainsString('Macintosh', $everything);
        $this->assertStringNotContainsString('q=cms', $everything);

        $pages = DB::table('analytics_pages')->orderBy('path')->get(['path', 'views', 'visitors'])->map(fn ($p) => [$p->path, (int) $p->views, (int) $p->visitors])->all();
        $this->assertSame([['/about', 1, 1], ['/pricing', 2, 1]], $pages);
    }

    public function test_another_device_is_another_visitor_and_tomorrow_nobody_is_recognized(): void
    {
        $today = CarbonImmutable::parse('2026-10-26 10:00', 'UTC');

        $this->record('/', ['w' => 390], ua: self::ANDROID, at: $today);
        $this->record('/', at: $today);
        $this->record('/', ['w' => 390], ua: self::ANDROID, at: $today->addDay());

        $rows = DB::table('analytics_visits')->orderBy('id')->get();
        $this->assertCount(3, $rows);
        $this->assertSame('mobile', $rows[0]->device);
        // Same person, same phone, the next day: a new salt makes a new, unrelated hash.
        $this->assertNotSame($rows[0]->visitor, $rows[2]->visitor);
    }

    public function test_a_visit_ends_after_half_an_hour_without_anything_and_the_visitor_stays_one(): void
    {
        // Noon on the site, so the morning's visits are earlier today whenever the test runs, after midnight too.
        \Carbon\Carbon::setTestNow(Collector::local()->startOfDay()->addHours(12));
        $at = Collector::local()->startOfDay()->addHours(9);

        $this->record('/', at: $at);
        $this->record('/docs', at: $at->addMinutes(20));
        $this->record('/pricing', ['r' => 'https://news.ycombinator.com/'], at: $at->addMinutes(55));

        $visits = DB::table('analytics_visits')->orderBy('id')->get();
        $this->assertCount(2, $visits);
        $this->assertSame([2, 1], [(int) $visits[0]->views, (int) $visits[1]->views]);
        $this->assertSame($visits[0]->visitor, $visits[1]->visitor);
        $this->assertSame([null, 'Hacker News'], [$visits[0]->source, $visits[1]->source]);

        $summary = $this->ask('analytics/summary', ['period' => 'today']);
        $this->assertSame([1, 2, 3], [$summary['visitors'], $summary['visits'], $summary['views']]);
        $this->assertSame('hour', $summary['interval']);
        $this->assertSame(1, collect($summary['series'])->firstWhere('date', $at->format('Y-m-d H:00'))['visitors']);
    }

    public function test_bots_people_who_opt_out_excluded_paths_and_other_sites_are_not_counted(): void
    {
        $this->hit('/', 'Mozilla/5.0 (compatible; Googlebot/2.1; +http://www.google.com/bot.html)');
        $this->hit('/', 'Mozilla/5.0 AppleWebKit/537.36 (KHTML, like Gecko; compatible; GPTBot/1.2; +https://openai.com/gptbot)');
        $this->hit('/', 'Mozilla/5.0 AppleWebKit/537.36 (KHTML, like Gecko); compatible; ChatGPT-User/1.0; +https://openai.com/bot');
        $this->hit('/', 'Mozilla/5.0 AppleWebKit/537.36 (KHTML, like Gecko; compatible; Claude-User/1.0; +Claude-User@anthropic.com)');
        $this->hit('/', 'Mozilla/5.0 AppleWebKit/537.36 (KHTML, like Gecko; compatible; Perplexity-User/1.0; +https://perplexity.ai/perplexity-user)');
        $this->hit('/', '');
        $this->hit('/', headers: ['HTTP_DNT' => '1']);
        $this->hit('/', headers: ['HTTP_SEC_GPC' => '1']);
        $this->hit('/', headers: ['HTTP_ORIGIN' => 'https://spam.example']);
        $this->hit('https://example.com/');
        $this->hit('//evil.example/x');
        $this->hit('/made-up-page');
        $this->call('POST', '/api/analytics/hit', [], [], [], ['REMOTE_ADDR' => '203.0.113.7', 'HTTP_USER_AGENT' => self::SAFARI], str_repeat('x', 9000));

        $this->cms()->settingsRegistry()->save('analytics', ['exclude' => ['/drafts/*', '/secret']]);
        $this->hit('/drafts/new-thing', extra: ['f' => 404]);
        $this->hit('/secret', extra: ['f' => 404]);

        $this->assertSame(0, DB::table('analytics_visits')->count());

        $this->cms()->settingsRegistry()->save('analytics', ['enabled' => false]);
        $this->hit('/');
        $this->assertSame(0, DB::table('analytics_visits')->count());
    }

    public function test_the_page_for_a_missing_address_counts_and_says_which_address(): void
    {
        $this->hit('/old-link', extra: ['f' => 404]);

        $this->assertSame('/old-link', DB::table('analytics_views')->value('path'));
        $this->assertSame([Collector::NOT_FOUND, '/old-link'], [DB::table('analytics_events')->value('name'), DB::table('analytics_events')->value('path')]);

        $goals = $this->ask('analytics/breakdown', ['dimension' => 'goals']);
        $this->assertSame(Collector::NOT_FOUND, $goals['items'][0]['key']);
    }

    public function test_sources_belong_to_channels(): void
    {
        $cases = [
            [null, [], [null, Sources::DIRECT]],
            ['http://localhost/blog', [], [null, Sources::DIRECT]],
            ['https://www.google.de/', [], ['Google', Sources::SEARCH]],
            ['https://gemini.google.com/app', [], ['Gemini', Sources::AI]],
            ['https://chatgpt.com/', [], ['ChatGPT', Sources::AI]],
            [null, ['source' => 'chatgpt.com'], ['ChatGPT', Sources::AI]],
            ['https://www.perplexity.ai/search', [], ['Perplexity', Sources::AI]],
            ['https://claude.ai/chat/1', [], ['Claude', Sources::AI]],
            ['https://news.ycombinator.com/item?id=1', [], ['Hacker News', Sources::SOCIAL]],
            ['https://l.instagram.com/', [], ['Instagram', Sources::SOCIAL]],
            [null, ['source' => 'spring-newsletter', 'medium' => 'email', 'campaign' => 'spring'], ['spring-newsletter', Sources::EMAIL]],
            [null, ['source' => 'google', 'medium' => 'cpc'], ['Google', Sources::PAID]],
            ['https://www.google.com/', ['paid' => true], ['Google', Sources::PAID]],
            [null, ['source' => 'podcast'], ['podcast', Sources::CAMPAIGN]],
            ['https://mail.google.com/', [], ['Email', Sources::EMAIL]],
            ['https://blog.example.org/post', [], ['blog.example.org', Sources::REFERRAL]],
        ];

        foreach ($cases as [$referrer, $link, $expected]) {
            $source = Sources::resolve($referrer, $link, ['localhost']);
            $this->assertSame($expected, [$source['source'], $source['channel']], json_encode([$referrer, $link]));
        }

        $this->assertSame('springscript', Sources::resolve(null, ['campaign' => 'spring<script>'], [])['campaign']);
    }

    public function test_countries_come_from_the_time_zone(): void
    {
        $this->assertSame('BE', Countries::fromTimeZone('Europe/Brussels'));
        $this->assertSame('US', Countries::fromTimeZone('America/Indiana/Indianapolis'));
        $this->assertSame('JP', Countries::fromTimeZone('Asia/Tokyo'));
        $this->assertNull(Countries::fromTimeZone('UTC'));
        $this->assertNull(Countries::fromTimeZone('Etc/GMT+2'));
        $this->assertNull(Countries::fromTimeZone('Not/AZone'));
        $this->assertNull(Countries::fromTimeZone('../../etc/passwd'));
    }

    public function test_engagement_keeps_the_most_a_page_reached_and_makes_the_visits_duration(): void
    {
        $this->record('/pricing');
        $this->record('/pricing', ['t' => 'engagement', 'e' => 12_000, 'd' => 40, 'l' => 1800, 'i' => 120, 'x' => 50]);
        // Sent again as the tab is hidden a second time: more time, scrolled less this time, a slower interaction.
        $this->record('/pricing', ['t' => 'engagement', 'e' => 30_000, 'd' => 20, 'l' => 1900, 'i' => 300]);
        // A page this visit never viewed: nothing to add to.
        $this->assertFalse($this->record('/docs', ['t' => 'engagement', 'e' => 5000]));

        $view = DB::table('analytics_views')->sole();
        $this->assertSame([30_000, 40, 1900, 300, 50], [(int) $view->engaged, (int) $view->scroll, (int) $view->lcp, (int) $view->inp, (int) $view->cls]);
        $this->assertSame(30, (int) DB::table('analytics_visits')->value('engaged'));

        $summary = $this->ask('analytics/summary', ['period' => '7d']);
        $this->assertSame(30, $summary['visitDuration']);
        $this->assertEquals(30.0, $summary['timeOnPage']);
        $this->assertSame(40, $summary['scrollDepth']);

        $pages = $this->ask('analytics/breakdown', ['dimension' => 'pages']);
        $this->assertEquals([30.0, 40], [$pages['items'][0]['timeOnPage'], $pages['items'][0]['scrollDepth']]);
    }

    public function test_events_belong_to_the_visit_and_keep_only_short_properties(): void
    {
        $this->record('/');
        $props = ['url' => 'https://github.com/x', 'nested' => ['no'], 'flag' => true, ...array_combine(array_map(fn ($i) => "p{$i}", range(1, 12)), range(1, 12))];
        $this->assertTrue($this->record('/', ['t' => 'event', 'n' => Collector::OUTBOUND, 'o' => $props]));
        $this->assertFalse($this->record('/', ['t' => 'event', 'n' => '   ']));
        // No visit going on: an event alone is not a visit.
        $this->assertFalse($this->record('/', ['t' => 'event', 'n' => 'Signup'], ip: '192.0.2.99'));

        $event = DB::table('analytics_events')->sole();
        $kept = json_decode($event->props, true);
        $this->assertCount(10, $kept);
        $this->assertSame(['https://github.com/x', 'true'], [$kept['url'], $kept['flag']]);
        $this->assertArrayNotHasKey('nested', $kept);

        // A visit with a click did not bounce.
        $this->assertSame(1, (int) DB::table('analytics_visits')->value('events'));
        $this->assertEquals(0, $this->ask('analytics/summary')['bounceRate']);

        $props = $this->ask('analytics/breakdown', ['dimension' => 'props', 'filters' => [['dimension' => 'goal', 'value' => Collector::OUTBOUND]]]);
        $this->assertSame('https://github.com/x', collect($props['items'])->firstWhere('prop', 'url')['label']);
        $this->assertSame('goal', $this->ask('analytics/breakdown', ['dimension' => 'props'])['unavailable']);
    }

    public function test_plugins_and_the_api_record_events_of_their_own(): void
    {
        $this->cms()->hooks()->dispatch('analytics.track', 'Purchase', ['plan' => 'Pro'], '/pricing');
        $this->assertTrue($this->ask('analytics/track', ['name' => 'Purchase', 'props' => ['plan' => 'Team']])['recorded']);

        $this->assertSame(2, DB::table('analytics_events')->whereNull('visit_id')->where('name', 'Purchase')->count());

        $goal = collect($this->ask('analytics/breakdown', ['dimension' => 'goals'])['items'])->firstWhere('key', 'Purchase');
        $this->assertSame([2, null], [$goal['events'], $goal['visitors']]);

        $this->expectException(AbilityException::class);
        $this->cms()->abilities()->execute('analytics/track', ['name' => 'Purchase'], AbilityContext::forUser($this->userWithRole('editor'), 'admin'));
    }

    public function test_reports_sum_up_a_period_and_compare_it_with_the_one_before(): void
    {
        $now = Collector::local();

        // Two visitors today (one bounces), one eight days ago (the previous week).
        $this->record('/', ip: '192.0.2.1', at: $now);
        $this->record('/docs', ip: '192.0.2.1', at: $now);
        $this->record('/docs', ['r' => 'https://github.com/cms', 'w' => 390], ip: '192.0.2.2', ua: self::ANDROID, at: $now);
        $this->record('/', ip: '192.0.2.3', at: $now->subDays(8));

        $summary = $this->ask('analytics/summary', ['period' => '7d']);

        $this->assertSame([2, 2, 3], [$summary['visitors'], $summary['visits'], $summary['views']]);
        $this->assertEquals(1.5, $summary['viewsPerVisit']);
        $this->assertEquals(0.5, $summary['bounceRate']);
        $this->assertSame(1, $summary['previous']['visitors']);
        $this->assertCount(7, $summary['series']);
        $this->assertSame(2, end($summary['series'])['visitors']);
        $this->assertSame(2, $summary['current']);
        $this->assertTrue($summary['tracked']);

        $pages = $this->ask('analytics/breakdown', ['period' => '7d', 'dimension' => 'pages']);
        $this->assertSame(['/docs', '/'], array_column($pages['items'], 'path'));
        $this->assertSame([2, 1], array_column($pages['items'], 'visitors'));
        $this->assertSame('Docs', $pages['items'][0]['label']);

        $sources = $this->ask('analytics/breakdown', ['period' => '7d', 'dimension' => 'sources']);
        $this->assertEqualsCanonicalizing([null, 'GitHub'], array_column($sources['items'], 'key'));

        $devices = $this->ask('analytics/breakdown', ['period' => '7d', 'dimension' => 'devices']);
        $this->assertEqualsCanonicalizing(['desktop', 'mobile'], array_column($devices['items'], 'key'));
        $this->assertEqualsCanonicalizing(['desktop', 'phone'], array_column($devices['items'], 'icon'));
        $this->assertEqualsWithDelta(1, array_sum(array_column($devices['items'], 'share')), 0.001);

        $this->assertSame(['/docs'], array_column($this->ask('analytics/breakdown', ['period' => '7d', 'dimension' => 'exits'])['items'], 'key'));

        // Every period and every dimension answers in the shape that is said.
        foreach (['realtime', 'today', 'yesterday', '7d', '30d', '90d', '12mo'] as $period) {
            $this->assertSame($period, $this->ask('analytics/summary', ['period' => $period])['period']);

            foreach (Reports::DIMENSIONS as $dimension) {
                $this->ask('analytics/breakdown', ['period' => $period, 'dimension' => $dimension]);
            }
        }

        $custom = $this->ask('analytics/summary', ['period' => 'custom', 'from' => $now->subDays(9)->toDateString(), 'to' => $now->toDateString()]);
        $this->assertSame([3, 10], [$custom['visitors'], count($custom['series'])]);
        $this->assertSame('month', $this->ask('analytics/summary', ['period' => '12mo'])['interval']);
        $this->assertSame(2, $this->ask('analytics/summary', ['period' => 'realtime'])['visitors']);
    }

    public function test_a_custom_period_must_make_sense(): void
    {
        $this->expectException(AbilityException::class);
        $this->ask('analytics/summary', ['period' => 'custom', 'from' => '2026-11-10', 'to' => '2026-11-01']);
    }

    public function test_filters_narrow_every_number(): void
    {
        $now = Collector::local();
        $this->record('/', ['r' => 'https://www.google.com/'], ip: '192.0.2.1', at: $now);
        $this->record('/pricing', ip: '192.0.2.1', at: $now);
        $this->record('/thank-you', ip: '192.0.2.1', at: $now);
        $this->record('/docs', ['r' => 'https://www.bing.com/'], ip: '192.0.2.2', at: $now);
        $this->record('/pricing', ['w' => 390], ip: '192.0.2.3', ua: self::ANDROID, at: $now);
        $this->cms()->settingsRegistry()->save('analytics', ['goals' => [['name' => 'Signed Up', 'path' => '/thank-you']]]);

        $google = $this->ask('analytics/summary', ['filters' => [['dimension' => 'source', 'value' => 'Google']]]);
        $this->assertSame([1, 3], [$google['visitors'], $google['views']]);

        $direct = $this->ask('analytics/summary', ['filters' => [['dimension' => 'source', 'value' => null]]]);
        $this->assertSame(1, $direct['visitors']);

        // A page: the visitors who viewed it, and the views of that page alone.
        $pricing = $this->ask('analytics/summary', ['filters' => [['dimension' => 'page', 'value' => '/pricing']]]);
        $this->assertSame([2, 2], [$pricing['visitors'], $pricing['views']]);
        $this->assertSame(3, $this->ask('analytics/summary', ['filters' => [['dimension' => 'page', 'value' => '/pricing', 'op' => 'is_not']]])['views']);
        $this->assertSame(2, $this->ask('analytics/summary', ['filters' => [['dimension' => 'channel', 'value' => 'search']]])['visitors']);
        $this->assertSame(1, $this->ask('analytics/summary', ['filters' => [['dimension' => 'device', 'value' => 'mobile']]])['visitors']);

        $converted = $this->ask('analytics/summary', ['filters' => [['dimension' => 'goal', 'value' => 'Signed Up']]]);
        $this->assertSame(1, $converted['conversions']);
        $this->assertEqualsWithDelta(1 / 3, $converted['conversionRate'], 0.001);

        $pages = $this->ask('analytics/breakdown', ['dimension' => 'pages', 'filters' => [['dimension' => 'page', 'value' => '/p*']]]);
        $this->assertSame(['/pricing'], array_column($pages['items'], 'key'));

        $goal = collect($this->ask('analytics/breakdown', ['dimension' => 'goals'])['items'])->firstWhere('key', 'Signed Up');
        $this->assertSame(['page', 1], [$goal['kind'], $goal['visitors']]);

        $this->expectException(AbilityException::class);
        $this->ask('analytics/summary', ['filters' => [['dimension' => 'password', 'value' => 'x']]]);
    }

    public function test_funnels_count_the_visitors_who_took_each_step_in_order(): void
    {
        $now = Collector::local();
        // One goes through, one stops at pricing, one reaches the sign-up page without pricing before it.
        foreach (['/', '/pricing', '/signup'] as $path) {
            $this->record($path, ip: '192.0.2.1', at: $now);
        }
        $this->record('/signup', ['t' => 'event', 'n' => 'Signup'], ip: '192.0.2.1', at: $now);
        $this->record('/', ip: '192.0.2.2', at: $now);
        $this->record('/pricing', ip: '192.0.2.2', at: $now);
        $this->record('/signup', ip: '192.0.2.3', at: $now);
        $this->record('/', ip: '192.0.2.3', at: $now);

        $this->cms()->settingsRegistry()->save('analytics', ['funnels' => [['name' => 'Sign-Up', 'steps' => ['/', '/pricing', 'Signup']]]]);
        $funnel = $this->ask('analytics/funnel', ['funnel' => 'Sign-Up']);

        $this->assertSame([3, 2, 1], array_column($funnel['steps'], 'visitors'));
        $this->assertEqualsWithDelta(1 / 3, $funnel['conversionRate'], 0.001);
        $this->assertEquals(0.5, $funnel['steps'][2]['dropoff']);

        $adHoc = $this->ask('analytics/funnel', ['steps' => ['/signup', '/']]);
        $this->assertSame([2, 1], array_column($adHoc['steps'], 'visitors'));

        $paths = $this->ask('analytics/paths', ['length' => 2]);
        $this->assertSame(['/', '/pricing'], array_column($paths['paths'][0]['pages'], 'path'));
        $this->assertSame(2, $paths['paths'][0]['visitors']);

        $around = $this->ask('analytics/paths', ['page' => '/pricing']);
        $this->assertSame([['/', 2]], array_map(null, array_column($around['before'], 'path'), array_column($around['before'], 'visitors')));
        $this->assertEqualsCanonicalizing(['/signup', null], array_column($around['after'], 'path'));

        try {
            $this->ask('analytics/funnel', ['funnel' => 'Nope']);
            $this->fail('A funnel that is not in the settings is not found.');
        } catch (AbilityException $e) {
            $this->assertSame('not_found', $e->type);
        }
    }

    public function test_page_speed_is_the_75th_percentile_rated_as_google_does(): void
    {
        for ($i = 1; $i <= 12; $i++) {
            $ip = "192.0.2.{$i}";
            $this->record('/pricing', ip: $ip);
            $this->record('/pricing', ['t' => 'engagement', 'e' => 4000, 'l' => $i * 400, 'i' => 100, 'x' => 20], ip: $ip);
        }

        $vitals = $this->ask('analytics/vitals');

        // Twelve paints of 400 ms to 4.8 s: the ninth is the 75th percentile, 3.6 s, which needs improvement.
        $this->assertSame([3600, 'needs-improvement'], [$vitals['site']['lcp'], $vitals['site']['lcpRating']]);
        $this->assertSame([100, 'good'], [$vitals['site']['inp'], $vitals['site']['inpRating']]);
        $this->assertSame([0.02, 'good'], [$vitals['site']['cls'], $vitals['site']['clsRating']]);
        $this->assertSame(['/pricing', 'needs-improvement', 12], [$vitals['pages'][0]['path'], $vitals['pages'][0]['rating'], $vitals['pages'][0]['samples']]);
    }

    public function test_reports_need_the_permission(): void
    {
        $this->expectException(AbilityException::class);
        $this->cms()->abilities()->execute('analytics/summary', [], AbilityContext::forUser($this->userWithRole('author'), 'admin'));
    }

    public function test_published_pages_carry_the_script_and_previews_do_not(): void
    {
        $hooks = $this->cms()->hooks();
        $entry = $this->cms()->entries()->findByUri('/pricing');

        $page = implode('', $hooks->filter('site.foot', [], (object) ['editor' => false, 'preview' => false, 'entry' => $entry, 'archive' => null]));
        $this->assertStringContainsString('data-endpoint="/api/analytics/hit"', $page);
        $this->assertStringContainsString('data-consent="1"', $page);
        $this->assertStringContainsString('data-auto="1"', $page);
        $this->assertStringNotContainsString('data-status="404"', $page);
        $this->assertStringNotContainsString('localStorage', $page);
        $this->assertStringNotContainsString('document.cookie', $page);
        $this->assertStringNotContainsString('// ', Snippet::script(), 'comments stay in the source');

        $missing = implode('', $hooks->filter('site.foot', [], (object) ['editor' => false, 'preview' => false, 'entry' => null, 'archive' => null]));
        $this->assertStringContainsString('data-status="404"', $missing);
        // The same script everywhere: one hash in the content security policy.
        $script = static fn (string $html) => preg_replace('/^<script[^>]*>/', '', $html);
        $this->assertSame($script($page), $script($missing));

        $this->cms()->settingsRegistry()->save('analytics', ['automatic' => false, 'consent_required' => false]);
        $plain = implode('', $hooks->filter('site.foot', [], (object) ['editor' => false, 'preview' => false, 'entry' => $entry, 'archive' => null]));
        $this->assertStringNotContainsString('data-auto="1"', $plain);
        $this->assertStringNotContainsString('data-consent="1"', $plain);

        $this->assertSame([], $hooks->filter('site.foot', [], (object) ['editor' => false, 'preview' => true]));
        $this->assertSame([], $hooks->filter('site.foot', [], (object) ['editor' => true, 'preview' => false]));
    }

    public function test_a_reporting_address_elsewhere_is_in_the_sites_content_security_policy(): void
    {
        $policy = fn () => $this->cms()->hooks()->filter('site.policy', ['connect-src' => ["'self'"]]);
        $this->assertSame(['connect-src' => ["'self'"]], $policy(), 'its own site needs nothing more');

        $this->cms()->settingsRegistry()->save('analytics', ['endpoint' => 'https://Stats.example.com/']);
        $this->assertSame(["'self'", 'https://stats.example.com'], $policy()['connect-src']);
        $this->assertSame(["'self'", 'https://stats.example.com'], $policy()['img-src']);
    }

    public function test_old_days_are_forgotten(): void
    {
        $this->record('/', at: CarbonImmutable::now()->subDays(40));
        $this->record('/', ['t' => 'event', 'n' => 'Signup'], at: CarbonImmutable::now()->subDays(40));
        $this->record('/', at: CarbonImmutable::now());
        $this->app->make(Collector::class)->track('Purchase', at: CarbonImmutable::now()->subDays(40));

        $this->cms()->settingsRegistry()->save('analytics', ['retention_days' => 30]);
        $this->app->make(Collector::class)->prune();

        $this->assertSame(1, DB::table('analytics_visits')->count());
        $this->assertSame(1, DB::table('analytics_views')->count());
        $this->assertSame(0, DB::table('analytics_events')->count());
        $this->assertSame(1, DB::table('analytics_pages')->count());
    }

    public function test_demo_traffic_fills_every_report(): void
    {
        $this->assertSame(0, \Illuminate\Support\Facades\Artisan::call('analytics:demo', ['--days' => 3]));

        $summary = $this->ask('analytics/summary', ['period' => '7d']);
        $this->assertGreaterThan(0, $summary['visitors']);
        $this->assertNotNull($summary['visitDuration']);
        $this->assertNotSame([], $this->ask('analytics/breakdown', ['dimension' => 'goals'])['items']);
        $this->assertNotSame([], $this->ask('analytics/breakdown', ['dimension' => 'countries'])['items']);
        $this->assertGreaterThan(0, $this->ask('analytics/vitals')['site']['samples']);
        $this->assertNotSame([], $this->ask('analytics/paths')['paths']);

        $this->assertSame(1, \Illuminate\Support\Facades\Artisan::call('analytics:demo', ['--days' => 3]), 'it never mixes with data there is');
    }

    public function test_editors_start_with_analytics(): void
    {
        $this->assertContains('analytics.view', app(\Cms\Users\Roles::class)->defaults()['editor']['permissions']);
    }
}
