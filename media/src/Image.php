<?php

declare(strict_types=1);

namespace Cms\Media;

use Illuminate\Contracts\Support\Htmlable;
use Stringable;

/**
 * What templates get for a media field: `{{ $entry->cover }}` renders a
 * responsive <picture>; `$entry->cover->url`, `->alt`, `->img(['aspect' => '16/9'])`.
 */
final class Image implements Htmlable, Stringable
{
    public function __construct(public readonly MediaItem $item) {}

    public function __get(string $key): mixed
    {
        return match ($key) {
            'url' => $this->item->url(),
            'alt' => (string) $this->item->alt,
            'caption' => (string) $this->item->caption,
            'width' => $this->item->width,
            'height' => $this->item->height,
            'mime' => $this->item->mime,
            'name' => $this->item->name,
            'id' => $this->item->id,
            default => null,
        };
    }

    /** @param array{priority?: bool, aspect?: ?string, sizes?: string, class?: string, alt?: string} $options */
    public function img(array $options = []): string
    {
        return app(Variants::class)->picture($this->item, $options['alt'] ?? null, $options);
    }

    public function src(int $width = 1440, string $format = 'webp'): string
    {
        $variants = app(Variants::class);
        $variants->ensure($this->item, $width, $format);

        return $variants->url($this->item, $width, $format);
    }

    public function toHtml(): string
    {
        return $this->img();
    }

    public function __toString(): string
    {
        return $this->toHtml();
    }
}
