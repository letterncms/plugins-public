<?php

declare(strict_types=1);

namespace Cms\Brevo;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Cms\Mail\Transports;
use Cms\Packages\Plugin;
use Throwable;

/** A mail provider plugin: built exactly like a third-party one would be. */
final class BrevoPlugin extends Plugin
{
    public function register(): void
    {
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
            'package' => $this->manifest->name,
            'handler' => fn () => $this->stats(),
        ]);

        $this->cms()->admin()->widget('brevo-stats', ['label' => 'Brevo', 'description' => __('Delivery, opens, clicks and bounces of the email Brevo sent this week.'), 'icon' => 'brevo', 'column' => 'main', 'order' => 65, 'permission' => 'settings.manage']);
        $this->adminModule();
    }

    /** @return array<string, mixed> */
    private function stats(): array
    {
        if ($this->cms()->settings('mail', 'transport', 'log') !== 'brevo') {
            return ['state' => 'inactive'];
        }

        $key = (string) $this->cms()->settings('mail', 'brevo_api_key', '');

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
