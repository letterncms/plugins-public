<?php

declare(strict_types=1);

namespace Cms\Brevo;

use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\Route;
use Cms\Abilities\AbilityException;
use Cms\Brevo\Http\SignupController;
use Cms\Modules\Blocks\BlockRegistry;
use Cms\Modules\Email\Transports;
use Cms\Packages\Plugin;
use Throwable;

/** A mail provider plugin: built exactly like a third-party one would be. */
final class BrevoPlugin extends Plugin
{
    public function register(): void
    {
        $this->app->singleton(Client::class);
        $this->app->singleton(Signup::class);

        $this->cms()->whenAvailable(Transports::class, static function (Transports $transports): void {
            $transports->register('brevo', 'Brevo', [
                ['handle' => 'brevo_api_key', 'type' => 'text', 'label' => 'Brevo API Key', 'secret' => true, 'instructions' => 'From Brevo → SMTP & API → API keys.'],
            ], static fn (array $settings) => new BrevoTransport((string) ($settings['brevo_api_key'] ?? '')));
        });
    }

    public function boot(): void
    {
        $this->cms()->abilities()->register('brevo/stats', [
            'label' => 'Brevo Delivery Stats',
            'description' => 'Transactional email totals from Brevo for the last 7 days: requests, delivered, unique opens and clicks, bounces, blocked and spam reports. state is "ok", "inactive" (Brevo is not the mail transport), "no-key" or "error" (with a message). Cached for 10 minutes.',
            'permission' => 'settings.manage',
            'annotations' => ['readOnly' => true],
            'handler' => fn () => $this->stats(),
        ]);

        $this->lists();

        $this->cms()->admin()->widget('brevo-stats', ['label' => 'Brevo', 'description' => __('Delivery, opens, clicks and bounces of the email Brevo sent this week.'), 'icon' => 'brevo', 'column' => 'main', 'order' => 65, 'permission' => 'settings.manage']);
        $this->adminModule();
    }

    /** Newsletter lists: their settings, the signup block, where it posts to, and what agents can do with them. */
    private function lists(): void
    {
        $client = fn (): Client => $this->app->make(Client::class);

        $this->cms()->settingsRegistry()->register('brevo', fields: [
            ['handle' => 'api_key', 'type' => 'text', 'label' => 'Brevo API Key', 'secret' => true],
            ['handle' => 'list', 'type' => 'select', 'label' => 'Default List', 'options' => [], 'dynamic' => 'brevo-lists'],
            ['handle' => 'double_opt_in', 'type' => 'toggle', 'label' => 'Confirm Addresses by Email', 'default' => true],
            ['handle' => 'confirmation_subject', 'type' => 'text', 'label' => 'Confirmation Subject', 'placeholder' => 'Confirm your subscription', 'if' => ['double_opt_in' => true]],
            ['handle' => 'confirmation_message', 'type' => 'textarea', 'label' => 'Confirmation Message', 'rows' => 3, 'if' => ['double_opt_in' => true]],
            ['handle' => 'confirmed_redirect', 'type' => 'url', 'component' => 'link', 'files' => false, 'label' => 'After Confirming, Go To', 'placeholder' => 'A Thank-You Page', 'if' => ['double_opt_in' => true]],
        ], icon: 'brevo', description: __('Newsletter lists and how people join them.'), order: 60);

        // One key is enough: with Brevo's key under Settings → Email, this page doesn't ask for another, and keeps none.
        $mailKey = fn (): bool => (string) $this->cms()->setting('email', 'brevo_api_key', '') !== '';
        $this->cms()->hooks()->addFilter('settings.fields', static fn (array $fields, string $namespace) => $namespace === 'brevo' && $mailKey()
            ? array_values(array_filter($fields, static fn ($field) => ($field['handle'] ?? null) !== 'api_key'))
            : $fields);
        $this->cms()->hooks()->addFilter('settings.saving', static fn (array $values, string $namespace) => $namespace === 'brevo' && $mailKey()
            ? array_diff_key($values, ['api_key' => true])
            : $values);

        // The lists to choose from, in the settings and in the signup block. Each is "#<id>": options are merged
        // by spreading, which would renumber keys that are numbers and leave the names as the values.
        $this->cms()->hooks()->addFilter('fields.options.brevo-lists', static function (array $options) use ($client) {
            foreach ($client()->lists()['lists'] as $list) {
                $options['#'.$list['id']] = $list['name'];
            }

            return $options;
        });

        // What a built page carries (the list, the wording) changes with these.
        $this->cms()->hooks()->addFilter('site.build.signature', fn (array $parts) => [...$parts, 'brevo:'.(int) $this->app->make(Signup::class)->list().':'.(int) $this->setting('double_opt_in', true).':'.$this->app->make(Signup::class)->base()]);

        if ($this->cms()->isActive('letterncms/blocks')) {
            $this->app->make(BlockRegistry::class)->register(SignupBlock::definition());
        }

        RateLimiter::for('cms-brevo', static fn (Request $r) => [Limit::perMinute(5)->by($r->ip()), Limit::perHour(30)->by($r->ip())]);
        Route::middleware(['throttle:cms-brevo'])->post('/api/brevo/subscribe', [SignupController::class, 'subscribe']);
        Route::options('/api/brevo/subscribe', [SignupController::class, 'options']);
        // The link in the email only shows a page: mail scanners follow links, and following one subscribes nobody. Its button posts.
        Route::middleware(['throttle:cms-brevo'])->get('/api/brevo/confirm', [SignupController::class, 'ask']);
        Route::middleware(['throttle:cms-brevo'])->post('/api/brevo/confirm', [SignupController::class, 'confirm']);

        $this->cms()->abilities()->register('brevo/lists', [
            'label' => 'Brevo Lists',
            'description' => 'The contact lists of the Brevo account, each with id, name and subscribers. state is "ok", "no-key" or "error" (with a message). Cached for 10 minutes.',
            'permission' => 'settings.manage',
            'annotations' => ['readOnly' => true],
            'handler' => static fn () => $client()->lists(),
        ]);

        $this->cms()->abilities()->register('brevo/subscribe', [
            'label' => 'Subscribe to a Brevo List',
            'description' => 'Add an email address to a Brevo list (the default list when none is given), right away and without a confirmation email: for someone who already agreed. Visitors join through the brevo/signup block.',
            'permission' => 'settings.manage',
            'annotations' => ['idempotent' => true, 'openWorld' => true],
            'input' => ['type' => 'object', 'properties' => ['email' => ['type' => 'string', 'format' => 'email', 'maxLength' => 254], 'list' => ['type' => 'integer', 'minimum' => 1]], 'required' => ['email']],
            'handler' => function (array $in) use ($client) {
                $list = $this->app->make(Signup::class)->list($in['list'] ?? null) ?? throw AbilityException::invalid(__('Choose a list to add people to.'));

                try {
                    $client()->subscribe(mb_strtolower(trim($in['email'])), $list);
                } catch (Throwable $e) {
                    throw new AbilityException($e->getMessage(), 'upstream');
                }

                $this->cms()->hooks()->dispatch('brevo.subscribed', mb_strtolower(trim($in['email'])), $list);

                return ['email' => mb_strtolower(trim($in['email'])), 'list' => $list];
            },
        ]);

        $this->cms()->context()->register('cms://brevo', 'Brevo', 'Newsletter signups with Brevo: whether a key is set, the default list and whether addresses are confirmed by email. Add a signup to a page with the brevo/signup block ({"list": "#<id>"}, or no list for the default).', fn () => [
            'key' => $client()->key() !== '',
            'defaultList' => $this->app->make(Signup::class)->list(),
            'doubleOptIn' => $this->app->make(Signup::class)->doubleOptIn(),
        ]);
    }

    /** @return array<string, mixed> */
    private function stats(): array
    {
        if ($this->cms()->setting('email', 'transport', 'log') !== 'brevo') {
            return ['state' => 'inactive'];
        }

        $key = (string) $this->cms()->setting('email', 'brevo_api_key', '');

        if ($key === '') {
            return ['state' => 'no-key'];
        }

        $cacheKey = 'brevo:stats:'.hash('xxh3', $key);

        if (is_array($cached = Cache::get($cacheKey))) {
            return $cached;
        }

        try {
            $response = Http::withHeaders(['api-key' => $key, 'accept' => 'application/json'])
                ->timeout(8)
                ->get('https://api.brevo.com/v3/smtp/statistics/aggregatedReport', ['days' => 7]);
        } catch (ConnectionException) {
            return ['state' => 'error', 'message' => __('Brevo could not be reached.')];
        } catch (Throwable $e) {
            report($e);

            return ['state' => 'error', 'message' => __('Brevo could not be reached.')];
        }

        if (in_array($response->status(), [401, 403], true)) {
            return ['state' => 'error', 'message' => __('Brevo did not accept the API key.')];
        }

        if (! $response->successful() || ! is_array($response->json())) {
            return ['state' => 'error', 'message' => __('Brevo answered with an error (:status).', ['status' => $response->status()])];
        }

        $count = static fn (string $field): int => (int) $response->json($field, 0);
        $stats = [
            'state' => 'ok',
            'days' => 7,
            'requests' => $count('requests'),
            'delivered' => $count('delivered'),
            'opens' => $count('uniqueOpens'),
            'clicks' => $count('uniqueClicks'),
            'hardBounces' => $count('hardBounces'),
            'softBounces' => $count('softBounces'),
            'blocked' => $count('blocked'),
            'spamReports' => $count('spamReports'),
            'invalid' => $count('invalid'),
            'unsubscribed' => $count('unsubscribed'),
            'fetchedAt' => now()->toIso8601String(),
        ];

        Cache::put($cacheKey, $stats, now()->addMinutes(10));

        return $stats;
    }
}
