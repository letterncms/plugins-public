<?php

declare(strict_types=1);

namespace Cms\Media;

use Throwable;

/**
 * Responsive image variants in public/_cms/img/<id>/<width>[-<w>x<h>]-<version>.<format>.
 * The version changes with the pixels and the focal point, since these files are cached
 * for a year: new pixels need a new URL. Generated when a page renders (so static builds are complete) or on first
 * request; afterwards they are plain static files.
 */
final class Variants
{
    public const array WIDTHS = [480, 960, 1440, 1920];

    public function __construct(private readonly ImageProcessor $processor) {}

    /** @return list<string> */
    public function formats(): array
    {
        $formats = [];

        if (cms()->settings('media', 'avif', true) && $this->processor->supports('avif')) {
            $formats[] = 'avif';
        }

        $formats[] = 'webp';

        return $formats;
    }

    public function url(MediaItem $item, int $width, string $format, ?string $ratio = null): string
    {
        return "/_cms/img/{$item->id}/".$this->name($item, $width, $format, $ratio);
    }

    public function path(MediaItem $item, int $width, string $format, ?string $ratio = null): string
    {
        return public_path("_cms/img/{$item->id}/".$this->name($item, $width, $format, $ratio));
    }

    /** Make sure a variant exists; returns false when it cannot be produced. */
    public function ensure(MediaItem $item, int $width, string $format, ?string $ratio = null): bool
    {
        $target = $this->path($item, $width, $format, $ratio);

        if (is_file($target)) {
            return true;
        }

        try {
            $this->processor->resize(
                public_path($item->path),
                $target,
                $width,
                $format,
                (int) cms()->settings('media', 'quality', 78),
                $ratio ? $this->ratio($ratio) : null,
                [$item->focal_x, $item->focal_y],
            );

            return true;
        } catch (Throwable $e) {
            report($e);

            return false;
        }
    }

    /** @return list<int> widths worth generating for this image */
    public function widths(MediaItem $item): array
    {
        $max = (int) ($item->width ?: 1920);
        $widths = array_values(array_filter(self::WIDTHS, static fn ($w) => $w < $max));

        return [...$widths, min($max, 2560)];
    }

    public function srcset(MediaItem $item, string $format, ?string $ratio = null): string
    {
        $parts = [];

        foreach ($this->widths($item) as $w) {
            if ($this->ensure($item, $w, $format, $ratio)) {
                $parts[] = $this->url($item, $w, $format, $ratio)." {$w}w";
            }
        }

        return implode(', ', $parts);
    }

    /**
     * <picture> with AVIF/WebP sources, intrinsic size, lazy loading and focal point.
     *
     * @param array{priority?: bool, aspect?: ?string, sizes?: string, class?: string} $options
     */
    public function picture(MediaItem $item, ?string $alt = null, array $options = []): string
    {
        $alt = e($alt ?? (string) $item->alt);
        $class = isset($options['class']) ? ' class="'.e($options['class']).'"' : '';

        if (! $item->isImage()) {
            return $item->isSvg() ? '<img src="'.e($item->url()).'" alt="'.$alt.'"'.$class.' loading="lazy" decoding="async"'.($item->width ? ' width="'.$item->width.'" height="'.$item->height.'"' : '').'>' : '';
        }

        $ratio = isset($options['aspect']) && preg_match('#^(\d{1,2})/(\d{1,2})$#', (string) $options['aspect'], $m) ? "{$m[1]}x{$m[2]}" : null;
        $sizes = e($options['sizes'] ?? '100vw');
        $formats = $this->formats();
        $fallback = array_pop($formats);
        $sources = '';

        foreach ($formats as $format) {
            $sources .= '<source type="image/'.$format.'" srcset="'.e($this->srcset($item, $format, $ratio)).'" sizes="'.$sizes.'">';
        }

        $widths = $this->widths($item);
        $defaultWidth = $widths[min(1, count($widths) - 1)];
        $this->ensure($item, $defaultWidth, $fallback, $ratio);

        [$w, $h] = [(int) $item->width, (int) $item->height];

        if ($ratio) {
            [$rw, $rh] = array_map('intval', explode('x', $ratio));
            $h = (int) round($w * $rh / $rw);
        }

        $priority = ! empty($options['priority']);
        $attrs = 'src="'.e($this->url($item, $defaultWidth, $fallback, $ratio)).'" srcset="'.e($this->srcset($item, $fallback, $ratio)).'" sizes="'.$sizes.'"'
            .' width="'.$w.'" height="'.$h.'" alt="'.$alt.'"'.$class
            .($priority ? ' fetchpriority="high" loading="eager"' : ' loading="lazy"').' decoding="async"'
            .(($item->focal_x !== 50 || $item->focal_y !== 50) ? ' style="object-position:'.$item->focal_x.'% '.$item->focal_y.'%"' : '');

        return '<picture>'.$sources.'<img '.$attrs.'></picture>';
    }

    public function forget(MediaItem $item): void
    {
        $dir = public_path("_cms/img/{$item->id}");

        if (is_dir($dir)) {
            \Cms\Support\Files::deleteDirectory($dir);
        }
    }

    public function version(MediaItem $item): string
    {
        return substr(hash('sha256', $item->hash.'|'.($item->focal_x ?? 50).'|'.($item->focal_y ?? 50)), 0, 8);
    }

    private function name(MediaItem $item, int $width, string $format, ?string $ratio): string
    {
        return $width.($ratio ? "-{$ratio}" : '').'-'.$this->version($item).'.'.$format;
    }

    private function ratio(string $ratio): float
    {
        [$w, $h] = array_map('intval', explode('x', $ratio));

        return $h > 0 ? $w / $h : 1;
    }
}
