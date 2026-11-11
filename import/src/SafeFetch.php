<?php

declare(strict_types=1);

namespace Cms\Import;

use Cms\Support\SafeFetcher;

/** Over the core's fetcher: http(s) only, public addresses only, checked after DNS and on every redirect. */
final class SafeFetch implements Fetcher
{
    public function fetch(string $url, int $maxBytes): string
    {
        return SafeFetcher::get($url, $maxBytes, 30)['body'];
    }
}
