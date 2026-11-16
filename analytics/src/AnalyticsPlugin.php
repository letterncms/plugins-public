<?php

declare(strict_types=1);

namespace Cms\Analytics;

use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Auth\Events\Login;
use Illuminate\Auth\Events\Logout;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cookie;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\Route;
use Cms\Analytics\Http\HitController;
use Cms\Analytics\Http\ViewController;
use Cms\Packages\Plugin;
use Throwable;

/**
 * Analytics for published sites, kept on the site's own server. By default it counts
 * page views per day and reads nothing from the browser; visitors mode adds daily
 * pseudonymous visitors, sources and devices. See Collector.
 */
final class AnalyticsPlugin extends Plugin
{
    public function register(): void
    {
        $this->app->singleton(Collector::class);
        $this->app->singleton(Reports::class);
        $this->app->singleton(SearchConsole::class);

        foreach ([Goals::class, Journeys::class, Vitals::class] as $service) {
            $this->app->singleton($service);
        }
    }

    public function boot(): void
    {
        $cms = $this->cms();
        $cms->permissions()->register('analytics.view', 'View Site Analytics', 'Analytics', roles: ['editor']);
        $cms->permissions()->register('analytics.track', 'Record Analytics Events', 'Analytics', roles: ['admin']);

        $cms->settingsRegistry()->register(Collector::SETTINGS, fields: [
            ['handle' => 'enabled', 'type' => 'toggle', 'label' => 'Count Visits', 'default' => true],
            ['handle' => 'mode', 'type' => 'select', 'label' => 'What to Count', 'default' => Collector::COUNTS, 'options' => [Collector::COUNTS => __('Page Views Only'), Collector::VISITORS => __('Visitors, Sources and Devices')], 'display' => 'radio', 'if' => ['enabled' => true],
                // Said where it happens: saving the switch back asks first.
                'confirmChange' => ['from' => Collector::VISITORS, 'title' => __('Count page views only?'), 'text' => __('Switching back to page views deletes visitor data.'), 'confirm' => __('Delete Visitor Data')]],
            ['handle' => 'consent_required', 'type' => 'toggle', 'label' => 'Wait for Consent', 'default' => true, 'if' => ['enabled' => true, 'mode' => Collector::VISITORS]],
            ['handle' => 'automatic', 'type' => 'toggle', 'label' => 'Count Links, Downloads and Forms', 'default' => true, 'if' => ['enabled' => true, 'mode' => Collector::VISITORS]],
            ['handle' => 'exclude', 'type' => 'list', 'label' => 'Paths Not to Count', 'placeholder' => '/drafts/*', 'if' => ['enabled' => true]],
            ['handle' => 'retention_days', 'type' => 'number', 'label' => 'Keep Visits For', 'suffix' => 'days', 'min' => 0, 'default' => 730, 'placeholder' => 'Forever', 'if' => ['enabled' => true]],
            ['handle' => 'endpoint', 'type' => 'url', 'label' => 'Reporting Address', 'placeholder' => 'https://cms.example.com', 'if' => ['enabled' => true]],
            ['handle' => 'goals', 'section' => 'Goals', 'type' => 'repeater', 'display' => 'table', 'label' => 'Goal Pages', 'add' => 'Add Goal', 'fields' => [
                ['handle' => 'name', 'type' => 'text', 'label' => 'Name', 'placeholder' => 'Signed Up', 'required' => true],
                ['handle' => 'path', 'type' => 'text', 'label' => 'Page', 'mono' => true, 'placeholder' => '/thank-you', 'required' => true],
            ]],
            ['handle' => 'funnels', 'section' => 'Goals', 'type' => 'repeater', 'label' => 'Funnels', 'add' => 'Add Funnel', 'if' => ['mode' => Collector::VISITORS], 'fields' => [
                ['handle' => 'name', 'type' => 'text', 'label' => 'Name', 'placeholder' => 'Sign-Up', 'required' => true],
                ['handle' => 'steps', 'type' => 'list', 'label' => 'Steps', 'placeholder' => '/pricing', 'required' => true],
            ]],
            ['handle' => 'search_key', 'section' => 'Search Console', 'type' => 'textarea', 'label' => 'Service Account Key', 'secret' => true, 'rows' => 3, 'mono' => true, 'placeholder' => '{"type": "service_account", …}'],
            ['handle' => 'search_property', 'section' => 'Search Console', 'type' => 'text', 'label' => 'Search Console Property', 'mono' => true, 'placeholder' => 'sc-domain:example.com'],
        ], icon: 'chart', description: 'Visitor counting on the published site.', order: 55, home: 'analytics');

        $this->routes();
        $this->snippet();
        $this->staffCookie();
        $this->app->make(AnalyticsAbilities::class)->register($cms->abilities());

        $admin = $cms->admin();
        $admin->nav('analytics', ['path' => '/analytics', 'icon' => 'chart', 'section' => 'main', 'order' => 10, 'pinned' => true, 'permission' => 'analytics.view']);
        $admin->widget('analytics-overview', ['label' => 'Analytics', 'description' => 'Page views in the last 30 days.', 'icon' => 'chart', 'column' => 'main', 'enabled' => true, 'order' => 5, 'permission' => 'analytics.view']);
        $admin->widget('analytics-now', ['label' => 'On the Site Now', 'description' => 'Who is on the site and what they read, in the last half hour.', 'icon' => 'activity', 'column' => 'side', 'order' => 6, 'permission' => 'analytics.view']);
        $admin->widget('analytics-pages', ['label' => 'Top Pages', 'description' => 'The pages read most in the last 30 days.', 'icon' => 'file-text', 'column' => 'side', 'order' => 7, 'permission' => 'analytics.view']);
        $admin->widget('analytics-search', ['label' => 'Google Search', 'description' => 'Clicks and searches from Google in the last 30 days.', 'icon' => 'search', 'column' => 'side', 'order' => 8, 'permission' => 'analytics.view']);
        $this->app->make(AnalyticsViews::class)->register();
        $admin->command('analytics', ['label' => 'Open Analytics', 'icon' => 'chart', 'path' => '/analytics', 'permission' => 'analytics.view', 'keywords' => ['visitors', 'traffic', 'stats', 'views']]);
        $this->adminModule();

        if ($this->app->runningInConsole()) {
            $this->commands([Console\DemoCommand::class]);
        }

        // Retention: forget old days now and then.
        $this->app->terminating(function () {
            if (random_int(1, 200) === 1) {
                try {
                    $this->app->make(Collector::class)->prune();
                } catch (Throwable) {
                }
            }
        });
    }

    private function routes(): void
    {
        RateLimiter::for('cms-analytics', static fn (Request $r) => Limit::perMinute(120)->by($r->ip()));
        Route::middleware(['throttle:cms-analytics'])->post('/api/analytics/hit', HitController::class);
        Route::get('/api/analytics/view', ViewController::class);
    }

    /** Every published page reports its views: built or served, never previews or the canvas. */
    private function snippet(): void
    {
        $hooks = $this->cms()->hooks();
        $collector = fn () => $this->app->make(Collector::class);
        $base = fn () => rtrim((string) ($this->setting('endpoint') ?: ''), '/');

        $hooks->addFilter('site.foot', function (array $foot, $context = null) use ($collector, $base) {
            if (($context->editor ?? false) || ($context->preview ?? false) || ! $collector()->enabled()) {
                return $foot;
            }

            if ($collector()->mode() === Collector::VISITORS) {
                // Only the page for a missing address is rendered without an entry or an archive.
                $missing = ($context->entry ?? null) === null && ($context->archive ?? null) === null;
                $foot[] = Snippet::html($base().'/api/analytics/hit', (bool) $this->setting('consent_required', true), (bool) $this->setting('automatic', true), $missing);
            } elseif (is_string($context->uri ?? null)) {
                $foot[] = Snippet::image($base().'/api/analytics/view', $context->uri);
            }

            return $foot;
        });

        // Changing what is counted, or where, rebuilds the static pages.
        // A reporting address on another host is one the pages call and load a pixel from: the site's content security
        // policy (core `site.policy`) names it.
        $hooks->addFilter('site.policy', static function (array $policy) use ($collector, $base) {
            if ($collector()->enabled() && preg_match('~^https://[^/"\'\s?#]+~i', $base(), $m)) {
                foreach (['connect-src', 'img-src'] as $directive) {
                    $policy[$directive] = [...($policy[$directive] ?? ["'self'"]), strtolower($m[0])];
                }
            }

            return $policy;
        });

        $hooks->addFilter('site.build.signature', fn (array $parts) => [...$parts, 'analytics:'.(int) $collector()->enabled().':'.$collector()->mode().':'.(int) $this->setting('consent_required', true).':'.(int) $this->setting('automatic', true).':'.$base().':'.md5(Snippet::script())]);

        // Any plugin counts an event of its own without depending on this one: `$cms->hooks()->dispatch('analytics.track', 'Purchase', ['plan' => 'Pro'])`.
        $hooks->on('analytics.track', static function (mixed $name, mixed $props = [], mixed $path = null) use ($collector): void {
            is_string($name) && $collector()->track($name, is_array($props) ? $props : [], is_string($path) ? $path : null);
        });

        $hooks->on('settings.saved', function (string $namespace) use ($collector) {
            if ($namespace !== Collector::SETTINGS) {
                return;
            }

            $collector()->mode() === Collector::COUNTS && $collector()->forgetVisitors();
            // Another key or property: what Search Console said before is not this one's.
            $this->app->make(SearchConsole::class)->forget();
        });
    }

    /** People who use the admin aren't visitors. A site's members are, so they get no cookie. */
    private function staffCookie(): void
    {
        Event::listen(Login::class, static function (Login $event) {
            if ($event->guard === 'web' && method_exists($event->user, 'hasPermission') && $event->user->hasPermission('admin.access')) {
                Cookie::queue(cookie(Collector::STAFF_COOKIE, '1', 60 * 24 * 400, '/', null, null, true, false, 'lax'));
            }
        });

        Event::listen(Logout::class, static function (Logout $event) {
            if ($event->guard === 'web') {
                Cookie::queue(Cookie::forget(Collector::STAFF_COOKIE, '/'));
            }
        });
    }
}
