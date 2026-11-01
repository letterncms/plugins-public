<?php

declare(strict_types=1);

namespace Cms\Site\Http;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/** Outside the session middleware: a page a cache may keep carries nobody's cookie. */
final class PublicPage
{
    public function handle(Request $request, Closure $next): Response
    {
        $response = $next($request);

        if ($response->headers->hasCacheControlDirective('public')) {
            foreach ($response->headers->getCookies() as $cookie) {
                $response->headers->removeCookie($cookie->getName(), $cookie->getPath(), $cookie->getDomain());
            }
        }

        return $response;
    }
}
