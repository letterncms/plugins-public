<?php

declare(strict_types=1);

namespace Cms\Navigation;

use Cms\Abilities\AbilityException;
use Cms\Content\Entries\Entry;
use Cms\Packages\Plugin;

final class NavigationPlugin extends Plugin
{
    public function register(): void
    {
        $this->app->singleton(Menus::class);
        $this->app->singleton(MenuResolver::class);
        require_once __DIR__.'/helpers.php';
    }

    public function boot(): void
    {
        $p = $this->cms()->permissions();
        $p->register('navigation.view', __('View Menus'), __('Navigation'));
        $p->register('navigation.manage', __('Create and Edit Menus'), __('Navigation'));

        $this->abilities();

        // Menus as a list for the Repeat block: {label, url, newTab, current, active, children}.
        $this->cms()->hooks()->addFilter('blocks.data-sources', fn (array $sources) => [...$sources, 'menu' => [
            'label' => 'Menu',
            'description' => __('Links of a navigation menu. Nest a Repeat over item.children for sub-menus.'),
            'fields' => [['handle' => 'menu', 'type' => 'select', 'label' => 'Menu', 'options' => array_map(static fn ($m) => $m['title'] ?? $m['handle'], $this->app->make(Menus::class)->all()), 'required' => true]],
            'item' => ['label' => __('Label'), 'url' => __('Link'), 'newTab' => __('Opens in a new tab'), 'current' => __('Is this page'), 'active' => __('Is this page or above it'), 'children' => __('Sub-menu links')],
            'resolve' => fn (array $props) => cms_menu((string) ($props['menu'] ?? 'main')) ?? [],
        ]]);

        // A deleted entry leaves its menu items behind as dead links; drop them (children move up).
        $this->cms()->hooks()->on('entry.deleted', function (Entry $entry) {
            $menus = $this->app->make(Menus::class);

            if (! in_array($entry->id, $menus->entryIds(), true)) {
                return;
            }

            foreach ($menus->all() as $menu) {
                $items = self::without($menu['items'], $entry->id);

                if ($items !== $menu['items']) {
                    $menus->save($menu['handle'], [...$menu, 'items' => $items]);
                }
            }
        });

        $this->cms()->context()->register('cms://navigation', 'Menus', 'Site menus and their items. Edit with navigation/save; pages show them with a Repeat block: {"source":"menu","menu":"<handle>"}.', fn () => array_values(array_map(fn ($m) => $this->describe($m), $this->app->make(Menus::class)->all())), permission: 'navigation.view');

        $this->cms()->admin()->nav('navigation', ['path' => '/navigation', 'icon' => 'list-tree', 'section' => 'content', 'order' => 72, 'permission' => 'navigation.view']);
        $this->cms()->admin()->command('navigation', ['label' => 'Edit Menus', 'icon' => 'list-tree', 'path' => '/navigation', 'keywords' => ['menu', 'navigation', 'links', 'header', 'footer'], 'permission' => 'navigation.view']);
        $this->cms()->admin()->widget('navigation-menus', ['label' => 'Menus', 'description' => __('The site’s menus and how many links each has.'), 'icon' => 'list-tree', 'column' => 'side', 'order' => 60, 'permission' => 'navigation.view']);
        $this->adminModule(import: '@letterncms/navigation');
    }

    private function abilities(): void
    {
        $a = $this->cms()->abilities();
        $menus = fn () => $this->app->make(Menus::class);
        $item = [
            'type' => 'object',
            'properties' => [
                'id' => ['type' => 'string', 'description' => 'Keep ids of existing items; omit for new ones.'],
                'entry' => ['type' => 'string', 'description' => 'Entry id to link to (follows the page when it moves).'],
                'locales' => ['type' => 'object', 'description' => 'Per language code: {label, url}. What the item says in that language, and for a link with no entry where it goes.'],
                'url' => ['type' => 'string', 'description' => 'External or custom link: https://…, /path, #anchor, mailto:, tel:'],
                'label' => ['type' => 'string', 'description' => 'Link text. Optional for entries (defaults to the entry title).'],
                'newTab' => ['type' => 'boolean'],
                'children' => ['type' => 'array', 'items' => ['type' => 'object']],
            ],
        ];

        $a->register('navigation/list', [
            'label' => 'List Menus',
            'description' => 'Menus with their item counts.',
            'permission' => 'navigation.view',
            'annotations' => ['readOnly' => true],
            'package' => $this->manifest->name,
            'handler' => fn () => array_values(array_map(static fn ($m) => ['handle' => $m['handle'], 'title' => $m['title'], 'maxDepth' => $m['maxDepth'], 'count' => self::count($m['items']), 'publishedAt' => $m['publishedAt'], 'updatedAt' => $m['updatedAt']], $menus()->all())),
        ]);

        $a->register('navigation/get', [
            'label' => 'Get Menu',
            'description' => 'A menu with its items; entry items include the linked entry’s title, URI and status.',
            'permission' => 'navigation.view',
            'annotations' => ['readOnly' => true],
            'package' => $this->manifest->name,
            'input' => ['type' => 'object', 'properties' => ['handle' => ['type' => 'string']], 'required' => ['handle']],
            'handler' => fn (array $in) => $this->describe($menus()->find($in['handle']) ?? throw new AbilityException(__('Menu not found.'), 'not_found')),
        ]);

        $a->register('navigation/save', [
            'label' => 'Save Menu',
            'description' => 'Create a menu or replace its items. Items nest through children (up to maxDepth levels). Link pages by entry id so links survive moves; use url for anything else.',
            'permission' => 'navigation.manage',
            'annotations' => ['idempotent' => true],
            'package' => $this->manifest->name,
            'input' => [
                'type' => 'object',
                'properties' => [
                    'handle' => ['type' => 'string', 'pattern' => '^[a-z0-9][a-z0-9_-]{0,63}$', 'description' => 'e.g. main, footer'],
                    'title' => ['type' => 'string'],
                    'maxDepth' => ['type' => 'integer', 'minimum' => 1, 'maximum' => 5],
                    'items' => ['type' => 'array', 'items' => $item],
                ],
                'required' => ['handle', 'items'],
            ],
            'handler' => fn (array $in) => $this->describe($menus()->save($in['handle'], $in)),
        ]);

        $a->register('navigation/delete', [
            'label' => 'Delete Menu',
            'description' => 'Delete a menu. Templates that render it show nothing in its place.',
            'permission' => 'navigation.manage',
            'annotations' => ['destructive' => true],
            'package' => $this->manifest->name,
            'input' => ['type' => 'object', 'properties' => ['handle' => ['type' => 'string']], 'required' => ['handle']],
            'handler' => fn (array $in) => ['deleted' => $menus()->delete($in['handle'])],
        ]);
    }

    /** Menu with entry details for editors and agents. */
    private function describe(array $menu): array
    {
        $ids = [];
        array_walk_recursive($menu['items'], static function ($value, $key) use (&$ids) {
            $key === 'entry' && $ids[] = $value;
        });
        $entries = [];
        foreach ($this->cms()->entries()->findMany($ids) as $entry) {
            $entries[$entry->id] = $entry;
        }

        $walk = static function (array $items) use (&$walk, $entries): array {
            return array_map(static function (array $item) use (&$walk, $entries) {
                if (isset($item['entry'])) {
                    $entry = $entries[$item['entry']] ?? null;
                    $item['target'] = $entry
                        ? ['title' => $entry->title, 'uri' => $entry->uri, 'status' => $entry->displayStatus(), 'collection' => $entry->collection]
                        : ['title' => null, 'uri' => null, 'status' => 'missing', 'collection' => null];
                }
                $item['children'] = $walk($item['children']);

                return $item;
            }, $items);
        };

        return [...$menu, 'items' => $walk($menu['items'])];
    }

    private static function without(array $items, string $entryId): array
    {
        $out = [];

        foreach ($items as $item) {
            $children = self::without($item['children'], $entryId);

            if (($item['entry'] ?? null) === $entryId) {
                array_push($out, ...$children);
            } else {
                $out[] = [...$item, 'children' => $children];
            }
        }

        return $out;
    }

    private static function count(array $items): int
    {
        return array_sum(array_map(static fn ($i) => 1 + self::count($i['children']), $items));
    }
}
