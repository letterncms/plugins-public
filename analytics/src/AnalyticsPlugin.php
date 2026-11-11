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
    }

    public function boot(): void
    {
        $cms = $this->cms();
        $cms->permissions()->register('analytics.view', 'View Site Analytics', 'Analytics', roles: ['editor']);

        $cms->settingsRegistry()->register(Collector::SETTINGS, fields: [
            ['handle' => 'enabled', 'type' => 'toggle', 'label' => 'Count Visits', 'default' => true],
            ['handle' => 'mode', 'type' => 'select', 'label' => 'What to Count', 'default' => Collector::COUNTS, 'options' => [Collector::COUNTS => __('Page Views Only'), Collector::VISITORS => __('Visitors, Sources and Devices')], 'display' => 'radio', 'if' => ['enabled' => true]],
            ['handle' => 'consent_required', 'type' => 'toggle', 'label' => 'Wait for Consent', 'default' => true, 'if' => ['enabled' => true, 'mode' => Collector::VISITORS]],
            ['handle' => 'exclude', 'type' => 'tags', 'label' => 'Paths Not to Count', 'placeholder' => '/drafts/*', 'if' => ['enabled' => true]],
            ['handle' => 'retention_days', 'type' => 'number', 'label' => 'Keep Visits For', 'suffix' => 'days', 'min' => 0, 'default' => 730, 'placeholder' => 'Forever', 'if' => ['enabled' => true]],
            ['handle' => 'endpoint', 'type' => 'url', 'label' => 'Reporting Address', 'placeholder' => 'https://cms.example.com', 'if' => ['enabled' => true]],
        ], icon: 'chart', description: 'Visitor counting on the published site.', order: 55, home: 'analytics');

        $this->routes();
        $this->snippet();
        $this->staffCookie();
        $this->app->make(AnalyticsAbilities::class)->register($cms->abilities());

        $admin = $cms->admin();
        $admin->nav('analytics', ['path' => '/analytics', 'icon' => 'chart', 'section' => 'main', 'order' => 10, 'pinned' => true, 'permission' => 'analytics.view']);
        $admin->widget('analytics-overview', ['label' => 'Analytics', 'description' => 'Page views in the last 30 days.', 'icon' => 'chart', 'column' => 'main', 'enabled' => true, 'order' => 5, 'permission' => 'analytics.view']);
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
                $foot[] = Snippet::html($base().'/api/analytics/hit', (bool) $this->setting('consent_required', true));
            } elseif (is_string($context->uri ?? null)) {
                $foot[] = Snippet::image($base().'/api/analytics/view', $context->uri);
            }

            return $foot;
        });

        // Changing what is counted, or where, rebuilds the static pages.
        $hooks->addFilter('site.build.signature', fn (array $parts) => [...$parts, 'analytics:'.(int) $collector()->enabled().':'.$collector()->mode().':'.(int) $this->setting('consent_required', true).':'.$base()]);

        $hooks->on('settings.saved', function (string $namespace) use ($collector) {
            if ($namespace === Collector::SETTINGS && $collector()->mode() === Collector::COUNTS) {
                $collector()->forgetVisitors();
            }
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
