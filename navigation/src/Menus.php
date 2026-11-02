<?php

declare(strict_types=1);

namespace Cms\Navigation;

use Illuminate\Support\Str;
use InvalidArgumentException;
use Cms\Extend\Hooks;
use Cms\Support\Files;
use Cms\Support\RecordTimestamps;
use Cms\Support\Yaml;

/**
 * Menus live in content/navigation/<handle>.yaml (git-friendly). Items link
 * to an entry by id — so they follow the page when it moves or is renamed —
 * or to a URL.
 *
 * @phpstan-type Item array{id: string, entry?: string, url?: string, label?: string, newTab?: bool, children: list<array>}
 */
final class Menus
{
    public const int MAX_ITEMS = 500;

    private ?array $cache = null;

    public function __construct(private readonly Hooks $hooks) {}

    /** @return array<string, array{handle: string, title: string, maxDepth: int, items: list<array>}> */
    public function all(): array
    {
        if ($this->cache !== null) {
            return $this->cache;
        }

        $menus = [];

        foreach (glob(content_path('navigation').'/*.yaml') ?: [] as $file) {
            $handle = basename($file, '.yaml');

            if (! Files::isHandle($handle)) {
                continue;
            }

            $data = Yaml::read($file);
            $menus[$handle] = [
                'handle' => $handle,
                ...RecordTimestamps::api($data),
                'title' => (string) ($data['title'] ?? Str::headline($handle)),
                'maxDepth' => max(1, min(5, (int) ($data['max_depth'] ?? 3))),
                'items' => $this->clean((array) ($data['items'] ?? [])),
            ];
        }

        ksort($menus);

        return $this->cache = $menus;
    }

    public function find(string $handle): ?array
    {
        return $this->all()[$handle] ?? null;
    }

    public function save(string $handle, array $menu): array
    {
        if (! Files::isHandle($handle)) {
            throw new InvalidArgumentException(__('Use lowercase letters, numbers, dashes or underscores for the menu handle.'));
        }

        $maxDepth = max(1, min(5, (int) ($menu['maxDepth'] ?? $this->find($handle)['maxDepth'] ?? 3)));
        $count = 0;
        $items = $this->validate(array_values((array) ($menu['items'] ?? [])), 1, $maxDepth, $count);
        $this->assertEntriesExist($items);

        Yaml::write(content_path("navigation/{$handle}.yaml"), [
            ...RecordTimestamps::saved($this->find($handle)),
            'title' => trim((string) ($menu['title'] ?? '')) ?: Str::headline($handle),
            'max_depth' => $maxDepth,
            'items' => $this->export($items),
        ]);

        $this->cache = null;
        $this->hooks->dispatch('navigation.saved', $handle);

        return $this->find($handle);
    }

    public function delete(string $handle): bool
    {
        $file = content_path("navigation/{$handle}.yaml");

        if (! Files::isHandle($handle) || ! is_file($file)) {
            return false;
        }

        unlink($file);
        $this->cache = null;
        $this->hooks->dispatch('navigation.saved', $handle);

        return true;
    }

    /** Entry ids referenced by any menu. */
    public function entryIds(): array
    {
        $ids = [];
        $walk = static function (array $items) use (&$walk, &$ids): void {
            foreach ($items as $item) {
                if (isset($item['entry'])) {
                    $ids[$item['entry']] = true;
                }
                $walk($item['children']);
            }
        };

        foreach ($this->all() as $menu) {
            $walk($menu['items']);
        }

        return array_keys($ids);
    }

    private function assertEntriesExist(array $items): void
    {
        $ids = [];
        array_walk_recursive($items, static function ($value, $key) use (&$ids) {
            $key === 'entry' && $ids[] = $value;
        });

        $found = array_map(static fn ($entry) => $entry->id, cms()->entries()->findMany($ids));
        $missing = array_diff(array_unique($ids), $found);

        if ($missing !== []) {
            throw new InvalidArgumentException(__('These entries do not exist: :ids. Find ids with content/search.', ['ids' => implode(', ', $missing)]));
        }
    }

    /** Strict checks for input from people and agents. */
    private function validate(array $items, int $depth, int $maxDepth, int &$count): array
    {
        $out = [];

        foreach ($items as $i => $item) {
            if (! is_array($item)) {
                throw new InvalidArgumentException(__('Menu item :index is not an object.', ['index' => $i]));
            }

            if (++$count > self::MAX_ITEMS) {
                throw new InvalidArgumentException(__('A menu can have at most :count items.', ['count' => self::MAX_ITEMS]));
            }

            $entry = strtolower(trim((string) ($item['entry'] ?? '')));
            $url = trim((string) ($item['url'] ?? ''));
            $label = trim((string) ($item['label'] ?? ''));

            if (($entry === '') === ($url === '')) {
                throw new InvalidArgumentException(__('Each menu item links to either an entry or a URL.'));
            }

            if ($url !== '' && ! self::safeUrl($url)) {
                throw new InvalidArgumentException(__('“:url” is not a link :brand can use. Use https://…, /path, #anchor, mailto: or tel:.', ['url' => $url]));
            }

            if ($url !== '' && $label === '') {
                throw new InvalidArgumentException(__('The link to :url needs a label.', ['url' => $url]));
            }

            $children = array_values((array) ($item['children'] ?? []));

            if ($children !== [] && $depth >= $maxDepth) {
                throw new InvalidArgumentException($maxDepth === 1 ? __('This menu allows 1 level.') : __('This menu allows :count levels.', ['count' => $maxDepth]));
            }

            $out[] = [
                'id' => preg_match('/^[a-z0-9]{6,26}$/i', (string) ($item['id'] ?? '')) ? (string) $item['id'] : strtolower(Str::random(8)),
                ...($entry !== '' ? ['entry' => $entry] : ['url' => $url]),
                ...($label !== '' ? ['label' => mb_substr($label, 0, 120)] : []),
                ...(! empty($item['newTab']) ? ['newTab' => true] : []),
                ...(($locales = self::locales($item)) !== [] ? ['locales' => $locales] : []),
                'children' => $this->validate($children, $depth + 1, $maxDepth, $count),
            ];
        }

        return $out;
    }

    /** Lenient normalization of what is on disk (hand-edited files). */
    private function clean(array $items): array
    {
        $out = [];

        foreach ($items as $item) {
            if (! is_array($item) || (empty($item['entry']) && (empty($item['url']) || ! self::safeUrl((string) $item['url'])))) {
                continue;
            }

            $out[] = [
                'id' => (string) ($item['id'] ?? substr(md5(json_encode($item)), 0, 8)),
                ...(! empty($item['entry']) ? ['entry' => (string) $item['entry']] : ['url' => (string) $item['url']]),
                ...(! empty($item['label']) ? ['label' => (string) $item['label']] : []),
                ...(! empty($item['new_tab']) || ! empty($item['newTab']) ? ['newTab' => true] : []),
                ...(($locales = self::locales($item)) !== [] ? ['locales' => $locales] : []),
                'children' => $this->clean((array) ($item['children'] ?? [])),
            ];
        }

        return $out;
    }

    /** On disk: snake_case keys, no empty children. */
    private function export(array $items): array
    {
        return array_map(fn (array $item) => array_filter([
            'id' => $item['id'],
            'entry' => $item['entry'] ?? null,
            'url' => $item['url'] ?? null,
            'label' => $item['label'] ?? null,
            'new_tab' => $item['newTab'] ?? null,
            'locales' => ($item['locales'] ?? []) ?: null,
            'children' => $item['children'] ? $this->export($item['children']) : null,
        ], static fn ($v) => $v !== null), $items);
    }

    /** @return array<string, array{label?: string, url?: string}> an item's label and link per language (the link only for items that have one of their own) */
    private static function locales(array $item): array
    {
        $out = [];

        foreach ((array) ($item['locales'] ?? []) as $code => $words) {
            if (! is_string($code) || ! preg_match('/^[a-z]{2}(-[A-Z]{2})?$/', $code) || ! is_array($words)) {
                continue;
            }

            $label = trim((string) ($words['label'] ?? ''));
            $url = empty($item['entry']) ? trim((string) ($words['url'] ?? '')) : '';
            $row = [
                ...($label !== '' ? ['label' => mb_substr($label, 0, 120)] : []),
                ...($url !== '' && self::safeUrl($url) ? ['url' => $url] : []),
            ];

            if ($row !== []) {
                $out[$code] = $row;
            }
        }

        return $out;
    }

    public static function safeUrl(string $url): bool
    {
        if (preg_match('/[\s<>"\x00-\x1f]/', $url)) {
            return false;
        }

        return (bool) preg_match('~^(https?://[^/\s]+|/(?!/)|#|\?|mailto:|tel:)~i', $url) || $url === '/';
    }
}
