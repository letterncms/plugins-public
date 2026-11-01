<?php

declare(strict_types=1);

namespace Cms\Blocks;

/**
 * Image output for blocks. The media plugin (or any replacement) provides
 * responsive <picture> markup through the `media.img` / `media.url` filters;
 * without it, plain URLs still work.
 */
final class Media
{
    /** @param array{priority?: bool, aspect?: ?string, sizes?: string, class?: string} $options */
    public static function img(mixed $image, string $alt = '', array $options = []): string
    {
        $html = cms()->hooks()->filter('media.img', null, $image, $alt, $options);

        if (is_string($html)) {
            return $html;
        }

        $url = self::url($image);

        if ($url === null) {
            self::warn($image);

            return '';
        }

        $priority = ! empty($options['priority']);
        $style = ! empty($options['aspect']) ? ' style="aspect-ratio:'.e($options['aspect']).';object-fit:cover;width:100%"' : '';

        return '<img src="'.e($url).'" alt="'.e($alt).'"'.self::size($url).($priority ? ' fetchpriority="high"' : ' loading="lazy"').' decoding="async"'.$style.'>';
    }

    /** A local file's own size, so the page holds its place while it loads. */
    private static function size(string $url): string
    {
        $file = str_starts_with($url, '/') && ! str_starts_with($url, '//') ? public_path(ltrim((string) parse_url($url, PHP_URL_PATH), '/')) : null;

        if ($file !== null && ! is_file($file)) {
            self::warn($url);

            return '';
        }

        $size = $file !== null ? @getimagesize($file) : false;

        return $size ? ' width="'.$size[0].'" height="'.$size[1].'"' : '';
    }

    /** Tell the build: a page asks for an image that isn't there. */
    private static function warn(mixed $image): void
    {
        $value = is_array($image) ? ($image[0] ?? $image['url'] ?? null) : $image;

        if (is_string($value) && $value !== '' && ! app(BlockRenderer::class)->editor) {
            cms()->hooks()->dispatch('render.warning', __('Missing image: :image', ['image' => $value]));
        }
    }

    public static function url(mixed $image): ?string
    {
        $url = cms()->hooks()->filter('media.url', null, $image);

        if (is_string($url)) {
            return $url;
        }

        $value = is_array($image) ? ($image[0] ?? $image['url'] ?? null) : $image;

        return is_string($value) && preg_match('#^(https?://|/)#', $value) ? $value : null;
    }
}
