<?php

declare(strict_types=1);

namespace Cms\Seo\Http;

use Closure;
use Illuminate\Http\Request;
use Cms\Seo\Redirects;
use Symfony\Component\HttpFoundation\Response;

/** Applies content/redirects.yaml to GET requests outside the admin and API. */
final class RedirectMiddleware
{
    public function __construct(private readonly Redirects $redirects) {}

    public function handle(Request $request, Closure $next): Response
    {
        if ($request->isMethod('GET') && ! $request->is('admin', 'admin/*', 'api/*', '__preview/*', '_cms/*')) {
            $redirect = $this->redirects->match($request->path());

            if ($redirect) {
                return redirect($redirect['to'], $redirect['status']);
            }
        }

        return $next($request);
    }
}
