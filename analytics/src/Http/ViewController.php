<?php

declare(strict_types=1);

namespace Cms\Analytics\Http;

use Cms\Analytics\Client;
use Cms\Analytics\Collector;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Symfony\Component\HttpFoundation\Response;

/**
 * Counts mode: the image at the end of a published page. Always the same answer, counted
 * or not, so it reveals nothing and the image never fails. It reads no session, and the IP
 * address and browser string stay in memory: the rate limit is one bucket for everybody,
 * because a key per visitor would store a hash of their address.
 */
final class ViewController
{
    private const string GIF = "GIF89a\x01\x00\x01\x00\x80\x00\x00\x00\x00\x00\xff\xff\xff\x21\xf9\x04\x01\x00\x00\x00\x00\x2c\x00\x00\x00\x00\x01\x00\x01\x00\x00\x02\x02\x44\x01\x00\x3b";

    private const int PER_MINUTE = 600;

    public function __invoke(Request $request, Collector $collector): Response
    {
        if ($this->counts($request, $collector) && ! RateLimiter::tooManyAttempts('cms-analytics-views', self::PER_MINUTE)) {
            RateLimiter::hit('cms-analytics-views', 60);
            $collector->recordView((string) $request->query('p', ''));
        }

        return response(self::GIF, 200, ['Content-Type' => 'image/gif', 'Cache-Control' => 'no-store']);
    }

    private function counts(Request $request, Collector $collector): bool
    {
        if ($collector->mode() !== Collector::COUNTS || $request->header('DNT') === '1' || $request->header('Sec-GPC') === '1' || $request->cookies->has(Collector::STAFF_COOKIE)) {
            return false;
        }

        // Any page can embed this image, which would inflate the numbers. A site published to another domain has said so in its settings.
        $site = $request->header('Sec-Fetch-Site');

        if ($site !== null && $site !== 'same-origin' && (string) cms()->setting(Collector::SETTINGS, 'endpoint', '') === '') {
            return false;
        }

        return ! Client::isBot((string) $request->userAgent());
    }
}
