<?php

declare(strict_types=1);

namespace Cms\Navigation;

use Cms\Content\Entries\Entry;
use Cms\Extend\Hooks;
use Cms\Cms;
use Cms\Site\Rendering\PageContext;

/** Turns a stored menu into links for templates: live entries only, current page marked. */
final class MenuResolver
{
    public function __construct(private readonly Menus $menus, private readonly Cms $cms, private readonly Hooks $hooks) {}

    /**
     * @param  string|null  $current  URI of the page being rendered (marks current/active items)
     * @return list<array{label: string, url: string, newTab: bool, current: bool, active: bool, children: list<array>}>|null  null when there is no such menu
     */
    public function resolve(string $handle, ?string $current = null, ?string $locale = null): ?array
    {
        $site = class_exists(PageContext::class);
        $site && PageContext::depend('navigation:'.$handle);

        $menu = $this->menus->find($handle);

        if (! $menu) {
            return null;
        }

        $ids = [];
        array_walk_recursive($menu['items'], static function ($value, $key) use (&$ids) {
            $key === 'entry' && $ids[] = $value;
        });

        $entries = [];
        foreach ($this->cms->entries()->findMany(array_values(array_unique($ids))) as $entry) {
            $entries[$entry->id] = $entry;
            // Renaming, moving or unpublishing a linked entry rebuilds every page that shows this menu.
            $site && PageContext::depend('entry:'.$entry->id);
        }

        return $this->items($menu['items'], $entries, $current === null ? null : self::normal($current), $locale);
    }

    /** @param array<string, Entry> $entries */
    private function items(array $items, array $entries, ?string $current, ?string $locale): array
    {
        $out = [];

        foreach ($items as $item) {
            // An item's own wording (and link) in the language being read.
            $item = [...$item, ...(array) ($item['locales'][$locale] ?? [])];

            if (isset($item['entry'])) {
                $entry = $this->hooks->filter('navigation.entry', $entries[$item['entry']] ?? null, $locale);

                if (! $entry instanceof Entry || ! $entry->isLive() || $entry->uri === null) {
                    continue; // Hidden with its children until the page is live.
                }

                $uri = $entry->uri;
                $url = function_exists('cms_url') ? cms_url($uri) : $uri;
                $label = $item['label'] ?? $entry->title;
            } else {
                $uri = $url = $item['url'];
                $label = $item['label'] ?? $url;
            }

            $children = $this->items($item['children'], $entries, $current, $locale);
            $isCurrent = $current !== null && str_starts_with($uri, '/') && self::normal($uri) === $current;

            $out[] = $this->hooks->filter('navigation.item', [
                'label' => $label,
                'url' => $url,
                'newTab' => (bool) ($item['newTab'] ?? false),
                'current' => $isCurrent,
                'active' => $isCurrent || array_filter($children, static fn ($c) => $c['active']) !== []
                    || ($current !== null && str_starts_with($uri, '/') && self::normal($uri) !== '/' && str_starts_with($current.'/', self::normal($uri).'/')),
                'children' => $children,
            ], $item);
        }

        return $out;
    }

    private static function normal(string $uri): string
    {
        $path = (string) parse_url($uri, PHP_URL_PATH);

        return $path === '' || $path === '/' ? '/' : rtrim($path, '/');
    }
}
