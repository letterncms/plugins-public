<?php

declare(strict_types=1);

namespace Cms\Navigation;

use Cms\Abilities\AbilityException;
use Cms\Content\Entries\Entry;
use Cms\Modules\Workspace\Answers;
use Cms\Users\User;
use Cms\Packages\Plugin;
use Illuminate\Support\Str;

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
        $this->places();
        $this->answers();
        $this->adminModule(import: '@letterncms/navigation');
    }

    /** The places of Navigation, as data (see Cms\Admin\Views): the menus, and one menu around its tree editor. */
    private function places(): void
    {
        $this->cms()->admin()->component('navigation/menu', ['label' => 'Menu editor', 'description' => 'The links of one menu: pages and links, nested by dragging, with how deep they may go.', 'surface' => false, 'permission' => 'navigation.view',
            'props' => ['type' => 'object', 'properties' => ['handle' => ['type' => 'string']], 'required' => ['handle'], 'additionalProperties' => false]]);

        $rename = static fn (string $handle, string $title) => ['label' => __('Rename'), 'icon' => 'edit', 'ability' => 'navigation/save', 'input' => ['handle' => $handle, 'title' => '{values.title}'],
            'form' => ['title' => __('Rename Menu'), 'confirm' => __('Rename'), 'values' => ['title' => $title], 'fields' => [['handle' => 'title', 'type' => 'text', 'label' => __('Name'), 'required' => true, 'autofocus' => true]]]];
        $delete = static fn (string $handle, string $question, ?string $then = null) => ['label' => __('Delete'), 'icon' => 'trash', 'variant' => $then ? 'ghost' : 'danger', 'ability' => 'navigation/delete', 'input' => ['handle' => $handle],
            'confirm' => ['title' => $question, 'text' => __('Repeat blocks that list this menu will show nothing.'), 'confirm' => __('Delete'), 'danger' => true], ...($then ? ['then' => 'open', 'to' => $then] : [])];

        $this->cms()->views()->register('navigation', '/navigation', static fn () => ['type' => 'ui/page', 'props' => ['title' => __('Navigation'), 'icon' => 'list-tree', 'actions' => [
            ['label' => __('New Menu'), 'icon' => 'plus', 'variant' => 'primary', 'ability' => 'navigation/create', 'input' => ['title' => '{values.title}'], 'then' => 'open', 'to' => '/navigation/{result.handle}',
                'form' => ['title' => __('New Menu'), 'confirm' => __('Create'), 'fields' => [['handle' => 'title', 'type' => 'text', 'label' => __('Name'), 'placeholder' => __('Footer'), 'instructions' => __('For example Main, Footer or Legal.'), 'required' => true, 'autofocus' => true]]]],
        ]], 'children' => [
            ['type' => 'ui/records', 'props' => [
                'id' => 'navigation', 'label' => __('Menus'), 'key' => 'handle',
                'source' => ['ability' => 'navigation/list'],
                'columns' => [
                    ['key' => 'title', 'label' => __('Name'), 'icon' => 'icon'],
                    ['key' => 'handle', 'label' => __('Handle'), 'width' => 'minmax(200px,1fr)', 'format' => 'code'],
                    ['key' => 'links', 'label' => __('Links'), 'width' => '100px', 'align' => 'end'],
                ],
                'open' => ['label' => __('Open'), 'to' => '/navigation/{row.handle}'],
                'actions' => [$rename('{row.handle}', '{row.title}'), $delete('{row.handle}', __('Delete “{row.title}”?'))],
                'empty' => ['icon' => 'list-tree', 'title' => __('No menus yet')],
            ]],
        ]], permission: 'navigation.view');

        $this->cms()->views()->register('menu', '/navigation/:handle', function (User $user, array $params) use ($rename, $delete) {
            $menu = $this->app->make(Menus::class)->find((string) ($params['handle'] ?? ''));

            if ($menu === null) {
                return ['type' => 'ui/page', 'props' => ['title' => __('Not Found'), 'icon' => 'list-tree', 'back' => ['to' => '/navigation', 'label' => __('Navigation')]], 'children' => [
                    ['type' => 'ui/empty', 'props' => ['icon' => 'list-tree', 'title' => __('Not Found'), 'action' => ['label' => __('Back to Navigation'), 'icon' => 'arrow-left', 'to' => '/navigation']]],
                ]];
            }

            return ['type' => 'ui/page', 'props' => ['title' => $menu['title'], 'icon' => 'list-tree', 'width' => 'narrow', 'back' => ['to' => '/navigation', 'label' => __('Navigation')], 'actions' => [
                $rename($menu['handle'], $menu['title']),
                $delete($menu['handle'], __('Delete “:title”?', ['title' => $menu['title']]), '/navigation'),
            ]], 'children' => [['type' => 'navigation/menu', 'props' => ['handle' => $menu['handle']]]]];
        }, permission: 'navigation.view');
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
            'handler' => fn () => array_values(array_map(static fn ($m) => ['handle' => $m['handle'], 'title' => $m['title'], 'icon' => 'list-tree', 'maxDepth' => $m['maxDepth'], 'count' => $count = self::count($m['items']), 'links' => $count === 1 ? __('1 link') : __(':count links', ['count' => $count]), 'publishedAt' => $m['publishedAt'], 'updatedAt' => $m['updatedAt']], $menus()->all())),
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

        $a->register('navigation/create', [
            'label' => 'Create Menu',
            'description' => 'A new, empty menu named title. Its handle is made from the title (main, footer).',
            'permission' => 'navigation.manage',
            'package' => $this->manifest->name,
            'input' => ['type' => 'object', 'properties' => ['title' => ['type' => 'string', 'minLength' => 1, 'maxLength' => 120]], 'required' => ['title']],
            'answer' => static fn (array $menu) => Answers::done(__('Created :name', ['name' => $menu['title']]), null, [
                ['label' => __('Open'), 'icon' => 'arrow-right', 'variant' => 'primary', 'to' => '/navigation/'.$menu['handle']],
                ['label' => __('Delete'), 'icon' => 'trash', 'variant' => 'ghost', 'ability' => 'navigation/delete', 'input' => ['handle' => $menu['handle']], 'success' => __('Menu deleted'),
                    'confirm' => ['title' => Answers::literal(__('Delete “:title”?', ['title' => $menu['title']])), 'confirm' => __('Delete'), 'danger' => true]],
            ]),
            'handler' => function (array $in) use ($menus) {
                $handle = substr(trim((string) preg_replace('/[^a-z0-9]+/', '-', strtolower(Str::ascii($in['title']))), '-'), 0, 64);

                // Said on the name, in the dialog that asked for it.
                if ($handle === '') {
                    throw AbilityException::invalid($error = __('Use letters or numbers in the menu’s name.'), ['title' => $error]);
                }

                if ($menus()->find($handle) !== null) {
                    throw new AbilityException($error = __('A menu called “:handle” already exists.', ['handle' => $handle]), 'conflict', ['title' => $error]);
                }

                return $this->describe($menus()->save($handle, ['title' => trim($in['title']), 'items' => []]));
            },
        ]);

        $a->register('navigation/save', [
            'label' => 'Save Menu',
            'description' => 'Create a menu or replace its items. Items nest through children (up to maxDepth levels). Link pages by entry id so links survive moves; use url for anything else. On an existing menu, what is left out (title, maxDepth, items) stays as it is.',
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
                'required' => ['handle'],
            ],
            'answer' => static fn (array $menu) => Answers::done(__('Saved :name', ['name' => $menu['title']]), null, [
                ['label' => __('Open'), 'icon' => 'arrow-right', 'variant' => 'primary', 'to' => '/navigation/'.$menu['handle']],
            ]),
            'handler' => fn (array $in) => $this->describe($menus()->save($in['handle'], [...array_intersect_key($menus()->find($in['handle']) ?? [], array_flip(['title', 'maxDepth', 'items'])), ...$in])),
        ]);

        $a->register('navigation/add', [
            'label' => 'Add to Menu',
            'description' => 'Add one link at the end of a menu: a page by its entry id (it follows the page when it moves), or any url. Its label is the page’s title unless one is given.',
            'permission' => 'navigation.manage',
            'package' => $this->manifest->name,
            'input' => ['type' => 'object', 'properties' => [
                'menu' => ['type' => 'string', 'pattern' => '^[a-z0-9][a-z0-9_-]{0,63}$', 'description' => 'The menu’s handle (main, footer).'],
                'entry' => ['type' => 'string', 'format' => 'ulid', 'description' => 'Entry id to link to.'],
                'url' => ['type' => 'string', 'maxLength' => 2000, 'description' => 'https://…, /path, #anchor, mailto: or tel:'],
                'label' => ['type' => 'string', 'maxLength' => 200],
            ], 'required' => ['menu']],
            'answer' => static fn (array $menu, array $in) => Answers::done(__('Added to :name', ['name' => $menu['title']]), null, [
                ['label' => __('Open'), 'icon' => 'arrow-right', 'variant' => 'primary', 'to' => '/navigation/'.$menu['handle']],
            ]),
            'handler' => function (array $in) use ($menus) {
                $menu = $menus()->find($in['menu']) ?? throw new AbilityException(__('Menu not found.'), 'not_found', ['menu' => __('Menu not found.')]);
                if (empty($in['entry']) && empty($in['url'])) {
                    throw AbilityException::invalid($error = __('Choose a page or give a link.'), ['url' => $error]);
                }
                $item = array_filter(['entry' => $in['entry'] ?? null, 'url' => empty($in['entry']) ? ($in['url'] ?? null) : null, 'label' => $in['label'] ?? null, 'children' => []], static fn ($v) => $v !== null);

                return $this->describe($menus()->save($in['menu'], [...$menu, 'items' => [...$menu['items'], $item]]));
            },
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

    /**
     * In the workspace, a page just made, saved or published can go in a menu right from its answer.
     */
    private function answers(): void
    {
        $this->cms()->hooks()->addFilter('workspace.answer', function (array $view, string $ability, mixed $result, array $input, User $user): array {
            $menus = $this->app->make(Menus::class)->all();
            if (! in_array($ability, ['entries/create', 'entries/publish', 'entries/duplicate'], true) || ! is_array($result) || empty($result['uri']) || $menus === [] || ! $user->hasPermission('navigation.manage')) {
                return $view;
            }
            $first = array_key_first($menus);
            $add = ['label' => __('Add to Menu'), 'icon' => 'list-tree', 'ability' => 'navigation/add', 'input' => ['menu' => '{values.menu}', 'entry' => $result['id']], 'success' => __('Added to the menu'),
                'form' => ['title' => __('Add to Menu'), 'confirm' => __('Add'), 'values' => ['menu' => $first], 'fields' => [
                    ['handle' => 'menu', 'type' => 'select', 'label' => __('Menu'), 'required' => true, 'display' => 'dropdown', 'options' => array_map(static fn (array $m) => $m['title'], $menus)],
                ]]];

            foreach ($view['children'] ?? [] as $i => $child) {
                if (($child['type'] ?? null) === 'ui/actions') {
                    $items = $child['props']['items'];
                    // Before the way back, after the ways forward.
                    $at = array_key_last($items) !== null && ($items[array_key_last($items)]['variant'] ?? null) === 'ghost' ? array_key_last($items) : count($items);
                    array_splice($items, $at, 0, [$add]);
                    $view['children'][$i]['props']['items'] = array_slice($items, 0, 6);

                    return $view;
                }
            }

            return $view;
        });
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
