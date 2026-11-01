<?php

declare(strict_types=1);

namespace Cms\Site\Rendering;

use ArrayAccess;
use Closure;

/** A scope value read fresh on every access (e.g. pagination, set while the page renders). */
final class LiveData implements ArrayAccess
{
    public function __construct(private readonly Closure $read) {}

    public function __get(string $key): mixed
    {
        return ($this->read)()[$key] ?? null;
    }

    public function __isset(string $key): bool
    {
        return isset(($this->read)()[$key]);
    }

    public function offsetExists(mixed $offset): bool
    {
        return isset(($this->read)()[$offset]);
    }

    public function offsetGet(mixed $offset): mixed
    {
        return ($this->read)()[$offset] ?? null;
    }

    public function offsetSet(mixed $offset, mixed $value): void {}

    public function offsetUnset(mixed $offset): void {}
}
