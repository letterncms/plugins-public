<?php

declare(strict_types=1);

namespace Cms\I18n;

/**
 * Pulls the human-readable text out of entry content (titles, fields, block
 * props, Block Markdown) as a flat list, and puts translated text back in the
 * same places. Structure, ids, URLs, tokens and handles never leave, so a
 * translator cannot break a page.
 */
final class StringMap
{
    /** Keys whose string values are never prose. */
    private const array SKIP_KEYS = [
        'id', 'type', 'url', 'href', 'src', 'slug', 'uri', 'handle', 'class', 'anchor', 'style', 'base', 'tablet', 'mobile',
        'sm', 'md', 'lg', 'xl', 'hover', 'focus', 'variant', 'size', 'align', 'justify', 'width', 'height', 'icon', 'color',
        'media', 'image', 'video', 'file', 'entry', 'entries', 'form', 'pattern', 'name', 'autocomplete', 'target', 'rel',
        'locale', 'collection', 'blueprint', 'language', 'format', 'layout', 'position', 'status', 'code', 'html', 'svg', 'embed',
        'tag', 'level', 'as', 'element', 'direction', 'fit', 'ratio', 'span', 'gap', 'order', 'display', 'method', 'action', 'mode', 'lang', 'dir',
        // What blocks do rather than say: logic, motion, bindings, data sources, ARIA states.
        'logic', 'motion', 'source', 'path', 'menu', 'ref', 'role', 'sizes', 'schema', 'template', 'sort', 'collections', 'tags', 'hastag',
        'aria-pressed', 'aria-selected', 'aria-expanded', 'aria-current', 'aria-hidden', 'aria-controls', 'aria-haspopup', 'kind',
    ];

    /**
     * @return array{strings: list<string>, paths: list<list<string|int>>}
     */
    public static function extract(array $content): array
    {
        $strings = [];
        $paths = [];

        $walk = static function (mixed $value, array $path) use (&$walk, &$strings, &$paths): void {
            if (is_array($value)) {
                foreach ($value as $key => $child) {
                    if (is_string($key) && in_array(strtolower($key), self::SKIP_KEYS, true)) {
                        continue;
                    }

                    // A block's own label names it in the editor; only its props reach visitors.
                    if ($key === 'label' && isset($value['type']) && (isset($value['id']) || isset($value['props']))) {
                        continue;
                    }

                    // A binding is a data path, except a sentence around one: "per site, extension {globals.plans.ext}".
                    if ($key === 'bind') {
                        $child = array_filter((array) $child, static fn ($v) => is_string($v) && str_contains($v, '{') && preg_match('/\p{L}/u', (string) preg_replace('/\{[^}]*\}/', '', $v)));
                    }

                    $walk($child, [...$path, $key]);
                }

                return;
            }

            if (is_string($value) && self::prose($value)) {
                $strings[] = $value;
                $paths[] = $path;
            }
        };

        $walk($content, []);

        return ['strings' => $strings, 'paths' => $paths];
    }

    /**
     * @param  list<list<string|int>>  $paths
     * @param  list<string>  $translated  Same order and length as the extracted strings
     */
    public static function apply(array $content, array $paths, array $translated): array
    {
        foreach ($paths as $i => $path) {
            if (! isset($translated[$i]) || ! is_string($translated[$i]) || $translated[$i] === '') {
                continue;
            }

            $ref = &$content;

            foreach ($path as $segment) {
                $ref = &$ref[$segment];
            }

            $ref = $translated[$i];
            unset($ref);
        }

        return $content;
    }

    /** Text a person reads, as opposed to ids, URLs, colors, tokens or numbers. */
    private static function prose(string $value): bool
    {
        $value = trim($value);

        if ($value === '' || ! preg_match('/\p{L}/u', $value)) {
            return false;
        }

        return ! preg_match('~^(https?://|mailto:|tel:|/|#|token:|var\(|\{brand\.[a-z_]*url\}|[0-9a-z]{26}$|(?i:[^\s@]+@[^\s@]+\.[a-z]{2,})$|[a-z0-9]+(?:[-_/][a-z0-9]+)+$|[a-z]+(?:\.[a-z0-9_-]+)+$)~', $value);
    }
}
