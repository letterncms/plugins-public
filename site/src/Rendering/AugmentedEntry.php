<?php

declare(strict_types=1);

namespace Cms\Site\Rendering;

use ArrayAccess;
use Carbon\CarbonImmutable;
use JsonSerializable;
use Cms\Content\Entries\Entry;
use Cms\Content\Entries\EntryRepository;
use Cms\Cms;

/**
 * Template-friendly entry: `$entry->title`, `$entry->url`, `$entry->excerpt`
 * (fields are augmented on first access — relations resolved, markdown rendered).
 * `$entry->raw('field')` returns the stored value.
 */
final class AugmentedEntry implements ArrayAccess, JsonSerializable
{
    /** @var array<string, mixed> */
    private array $cache = [];

    public readonly Entry $entry;

    /** Everything a page shows goes through here, so it only ever holds what this request may read. */
    public function __construct(Entry $entry)
    {
        $this->entry = app(EntryRepository::class)->gate()->view($entry);
        PageContext::depend('entry:'.$entry->id);
    }

    public function __get(string $key): mixed
    {
        return match ($key) {
            'id' => $this->entry->id,
            'title' => $this->entry->title,
            'slug' => $this->entry->slug,
            'uri', 'url' => $this->url(),
            'collection' => $this->entry->collection,
            'locale' => $this->entry->locale,
            'date', 'published_at' => $this->entry->publishedAt ?? $this->entry->createdAt,
            'updated_at' => $this->entry->updatedAt,
            'created_at' => $this->entry->createdAt,
            'status' => $this->entry->status,
            'author' => $this->entry->author(false),
            'updated' => $this->entry->updatedAt,
            'children' => $this->children(),
            'parent' => $this->parent(),
            'ancestors' => $this->ancestors(),
            'root' => $this->root(),
            'current' => array_key_exists('current', $this->entry->data) ? $this->field('current') : $this->isCurrent(),
            'active' => array_key_exists('active', $this->entry->data) ? $this->field('active') : $this->isActive(),
            'prev' => $this->sibling(-1),
            'next' => $this->sibling(1),
            default => $this->field($key),
        };
    }

    public function __isset(string $key): bool
    {
        return in_array($key, ['id', 'title', 'slug', 'uri', 'url', 'collection', 'locale', 'author', 'date', 'updated', 'children', 'parent', 'ancestors', 'root', 'current', 'active', 'prev', 'next'], true) || array_key_exists($key, $this->entry->data);
    }

    public function url(): string
    {
        $uri = $this->entry->uri ?? '/';

        return cms('Cms\Site\Rendering\UrlGenerator')->to($uri);
    }

    public function raw(string $field, mixed $default = null): mixed
    {
        return $this->entry->data[$field] ?? $default;
    }

    public function field(string $handle): mixed
    {
        if (array_key_exists($handle, $this->cache)) {
            return $this->cache[$handle];
        }

        $blueprint = $this->entry->collection()->blueprint();
        $field = $blueprint->field($handle);
        $value = $this->entry->data[$handle] ?? null;

        return $this->cache[$handle] = $field
            ? cms()->fields()->resolve($field->type)->augment($value, $field)
            : $value;
    }

    /** Children in a tree collection (live only), ordered. */
    public function children(): array
    {
        PageContext::depend('collection:'.$this->entry->collection);

        return array_map(
            static fn (Entry $e) => new self($e),
            app(Cms::class)->entries()->query()->collection($this->entry->collection)->locale($this->entry->locale)->live()->parent($this->entry->id)->orderBy('position')->get()
        );
    }

    public function parent(): ?self
    {
        $parent = $this->entry->parentId ? app(Cms::class)->entries()->find($this->entry->parentId) : null;

        return $parent && $parent->isLive() ? new self($parent) : null;
    }

    /**
     * The entry before or after this one in reading order: depth-first for
     * trees (docs), oldest to newest for dated collections, else the collection's order.
     */
    public function sibling(int $offset): ?self
    {
        static $orders = [];

        $collection = $this->entry->collection();
        $key = $collection->handle.'|'.$this->entry->locale;
        PageContext::depend('collection:'.$collection->handle);

        if (! isset($orders[$key])) {
            $query = fn () => app(Cms::class)->entries()->query()->collection($collection->handle)->locale($this->entry->locale)->live();

            if ($collection->isTree()) {
                $all = $query()->orderBy('position')->get();
                $byParent = [];

                foreach ($all as $e) {
                    $byParent[$e->parentId ?? ''][] = $e;
                }

                $flat = [];
                $walk = static function (string $parent) use (&$walk, &$flat, $byParent): void {
                    foreach ($byParent[$parent] ?? [] as $e) {
                        $flat[] = $e;
                        $walk($e->id);
                    }
                };
                $walk('');
            } else {
                $flat = $query()->orderBy($collection->isDated() ? 'published_at' : $collection->sort)->get();
            }

            $orders[$key] = $flat;
        }

        foreach ($orders[$key] as $i => $e) {
            if ($e->id === $this->entry->id) {
                $other = $orders[$key][$i + $offset] ?? null;

                return $other ? new self($other) : null;
            }
        }

        return null;
    }

    /** Ancestors from the root down (for breadcrumbs). */
    public function ancestors(): array
    {
        $out = [];

        for ($p = $this->parent(), $i = 0; $p && $i < 16; $p = $p->parent(), $i++) {
            array_unshift($out, $p);
        }

        return $out;
    }

    /** The top of this entry's branch in a tree collection: its highest ancestor, or itself. */
    public function root(): self
    {
        return $this->ancestors()[0] ?? $this;
    }

    /** Is this the page being rendered? */
    public function isCurrent(): bool
    {
        return PageContext::current()?->entry?->id === $this->entry->id;
    }

    /** Is this the page being rendered or above it in the tree? The same as `active` on a menu link. */
    public function isActive(): bool
    {
        $page = PageContext::current()?->entry;

        for ($i = 0; $page && $i < 16; $page = $page->parentId ? app(Cms::class)->entries()->find($page->parentId) : null, $i++) {
            if ($page->id === $this->entry->id) {
                return true;
            }
        }

        return false;
    }

    public function date(string $format = 'F j, Y'): string
    {
        $date = $this->entry->publishedAt ?? $this->entry->createdAt ?? CarbonImmutable::now();

        return $date->locale($this->entry->locale)->translatedFormat($format);
    }

    public function is(self|Entry|null $other): bool
    {
        $id = $other instanceof self ? $other->entry->id : $other?->id;

        return $id === $this->entry->id;
    }

    public function offsetExists(mixed $offset): bool
    {
        return $this->__isset((string) $offset);
    }

    public function offsetGet(mixed $offset): mixed
    {
        return $this->__get((string) $offset);
    }

    public function offsetSet(mixed $offset, mixed $value): void {}

    public function offsetUnset(mixed $offset): void {}

    public function jsonSerialize(): array
    {
        return ['id' => $this->entry->id, 'title' => $this->entry->title, 'url' => $this->url(), 'data' => $this->entry->data];
    }

    public function __toString(): string
    {
        return $this->entry->title;
    }
}
