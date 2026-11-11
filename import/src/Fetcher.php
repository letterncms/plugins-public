<?php

declare(strict_types=1);

namespace Cms\Import;

/** Downloads a file an import refers to. Bound to SafeFetch; a test binds a fake. */
interface Fetcher
{
    /**
     * @return string The file's bytes.
     *
     * @throws \RuntimeException when it can't be had.
     */
    public function fetch(string $url, int $maxBytes): string;
}
