<?php

declare(strict_types=1);

namespace Cms\Brevo;

use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Cms\Cms;
use RuntimeException;
use Throwable;

/** Everything said to Brevo's API about contacts and lists, in one place: tests answer for it with Http::fake(). */
final class Client
{
    public const string API = 'https://api.brevo.com/v3';

    public function __construct(private readonly Cms $cms) {}

    /** Brevo's lists come 50 at a time; an account with more than this many is asked no further. */
    private const int PAGES = 20;

    /** The key of the email transport (Settings → Email), or the plugin's own when Brevo doesn't send the site's email. */
    public function key(): string
    {
        $mail = (string) $this->cms->setting('email', 'brevo_api_key', '');

        return $mail !== '' ? $mail : (string) $this->cms->setting('brevo', 'api_key', '');
    }

    /**
     * The account's lists. Cached for 10 minutes; a failure for one, so an editor that asks on every load doesn't wait on Brevo each time.
     *
     * @return array{state: 'ok'|'no-key'|'error', lists: list<array{id: int, name: string, subscribers: int}>, message?: string}
     */
    public function lists(): array
    {
        $key = $this->key();

        if ($key === '') {
            return ['state' => 'no-key', 'lists' => []];
        }

        $cacheKey = 'brevo:lists:'.hash('xxh3', $key);

        if (is_array($cached = Cache::get($cacheKey))) {
            return $cached;
        }

        try {
            $lists = [];
            $result = null;

            // Every list, a page of 50 at a time, until Brevo's own count is reached.
            for ($page = 0; $page < self::PAGES; $page++) {
                $response = $this->request($key)->get(self::API.'/contacts/lists', ['limit' => 50, 'offset' => $page * 50, 'sort' => 'desc']);

                if (! $response->successful() || ! is_array($response->json('lists') ?? [])) {
                    $result = ['state' => 'error', 'lists' => [], 'message' => self::problem($response)];
                    break;
                }

                $got = array_filter((array) $response->json('lists', []), static fn ($l) => is_array($l) && isset($l['id']));

                foreach ($got as $l) {
                    $lists[(int) $l['id']] = ['id' => (int) $l['id'], 'name' => (string) ($l['name'] ?? $l['id']), 'subscribers' => (int) ($l['uniqueSubscribers'] ?? $l['totalSubscribers'] ?? 0)];
                }

                if (count($got) < 50 || ($page + 1) * 50 >= (int) $response->json('count', 0)) {
                    break;
                }
            }

            $result ??= ['state' => 'ok', 'lists' => array_values($lists)];
        } catch (Throwable) {
            $result = ['state' => 'error', 'lists' => [], 'message' => __('Brevo could not be reached.')];
        }

        Cache::put($cacheKey, $result, $result['state'] === 'ok' ? now()->addMinutes(10) : now()->addMinute());

        return $result;
    }

    /** Add an address to a list, or put a contact Brevo already has on it. */
    public function subscribe(string $email, int $list): void
    {
        $key = $this->key();

        if ($key === '') {
            throw new RuntimeException(__('Add Your Brevo API Key'));
        }

        $response = $this->request($key)->post(self::API.'/contacts', ['email' => $email, 'listIds' => [$list], 'updateEnabled' => true]);

        if (! $response->successful()) {
            throw new RuntimeException(self::problem($response));
        }
    }

    private function request(string $key): \Illuminate\Http\Client\PendingRequest
    {
        return Http::withHeaders(['api-key' => $key, 'accept' => 'application/json'])->timeout(8);
    }

    private static function problem(Response $response): string
    {
        return in_array($response->status(), [401, 403], true)
            ? __('Brevo did not accept the API key.')
            : __('Brevo answered with an error (:status).', ['status' => $response->status()]);
    }
}
