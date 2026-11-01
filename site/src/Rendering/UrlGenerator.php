<?php

declare(strict_types=1);

namespace Cms\Site\Rendering;

/** Site URLs. Relative by default so a static build works on any domain and in previews. */
final class UrlGenerator
{
    public function to(string $uri): string
    {
        if (preg_match('#^(https?:)?//|^(mailto|tel):|^\##i', $uri)) {
            return $uri;
        }

        return '/'.ltrim($uri, '/');
    }

    public function absolute(string $uri): string
    {
        return rtrim((string) cms()->site('url', config('app.url')), '/').$this->to($uri);
    }
}
