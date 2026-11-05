<?php

declare(strict_types=1);

namespace Cms\Analytics\Http;

use Illuminate\Http\Request;
use Cms\Analytics\Collector;
use Cms\Cms;
use Symfony\Component\HttpFoundation\Response;

/**
 * Where the snippet on published pages reports a view (visitors mode). Always answers 204 —
 * counted or not — so the page learns nothing and nothing waits on it.
 */
final class HitController
{
    public function __invoke(Request $request, Collector $collector, Cms $cms): Response
    {
        $own = array_values(array_filter([$request->getHost(), parse_url($cms->url(), PHP_URL_HOST)]));

        if ($this->consents($request) && ! $request->cookies->has(Collector::STAFF_COOKIE) && $this->fromOwnSite($request, $own)) {
            $hit = json_decode((string) $request->getContent(), true);

            if (is_array($hit) && is_string($hit['p'] ?? null)) {
                $collector->record(
                    path: $hit['p'],
                    referrer: is_string($hit['r'] ?? null) ? mb_substr($hit['r'], 0, 1000) : null,
                    campaign: is_string($hit['s'] ?? null) ? mb_substr($hit['s'], 0, 100) : null,
                    width: is_numeric($hit['w'] ?? null) ? (int) $hit['w'] : null,
                    ip: (string) $request->ip(),
                    userAgent: mb_substr((string) $request->userAgent(), 0, 500),
                    ownHosts: $own,
                );
            }
        }

        return response('', 204, ['Cache-Control' => 'no-store']);
    }

    /** Do Not Track and Global Privacy Control are respected here too, not only in the snippet. */
    private function consents(Request $request): bool
    {
        return $request->header('DNT') !== '1' && $request->header('Sec-GPC') !== '1';
    }

    /** Views come from the site's own pages: another website cannot post into its numbers. */
    private function fromOwnSite(Request $request, array $own): bool
    {
        $origin = $request->headers->get('Origin') ?? $request->headers->get('Referer');

        return $origin === null || $origin === 'null' || in_array(parse_url($origin, PHP_URL_HOST), $own, true);
    }
}
