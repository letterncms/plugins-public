<?php

declare(strict_types=1);

namespace Cms\Site\Rendering;

use ArrayAccess;
use Closure;

/**
 * `globals` in the render scope: loaded on first use, so only pages that show
 * a global depend on it (and rebuild when it changes).
 */
final class LazyGlobals implements ArrayAccess
{
    private ?array $values = null;

    public function __construct(private readonly Closure $load) {}

    public function __get(string $key): mixed
    {
        return $this->all()[$key] ?? null;
    }

    public function __isset(string $key): bool
    {
        return isset($this->all()[$key]);
    }

    public function all(): array
    {
        return $this->values ??= ($this->load)();
    }

    public function offsetExists(mixed $offset): bool
    {
        return isset($this->all()[$offset]);
    }

    public function offsetGet(mixed $offset): mixed
    {
        return $this->all()[$offset] ?? null;
    }

    public function offsetSet(mixed $offset, mixed $value): void {}

    public function offsetUnset(mixed $offset): void {}
}
