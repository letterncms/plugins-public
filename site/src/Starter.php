<?php

declare(strict_types=1);

namespace Cms\Site;

use Illuminate\Support\Facades\DB;
use Cms\Abilities\AbilityContext;
use Cms\Content\Collections\CollectionRepository;
use Cms\Cms;
use Cms\Support\Files;
use Cms\Support\Yaml;
use Cms\Users\User;

/**
 * A new site starts from this plugin's starter kit, so the first sign-in shows
 * a working site instead of an empty one. It lives in `starter/`:
 *
 *   starter/content/      copied into the site's content/ (design, collections, globals…)
 *   starter/entries.yaml  entries: { <collection>: [{ title, slug, data, children }] }
 *                         menus:   { <handle>: [{ page: <slug> } | { url, label }] }
 *
 * Nothing happens when the site already has collections.
 */
final class Starter
{
    public function __construct(
        private readonly Cms $cms,
        private readonly CollectionRepository $collections,
    ) {}

    /** @return int entries created */
    public function seed(?User $user = null): int
    {
        return $this->import($user, replace: false);
    }

    /** Explicit development refresh: replace only the active kit's content, never accounts or configuration. */
    public function refresh(?User $user = null): int
    {
        return $this->cms->content()->write(fn () => $this->import($user, replace: true));
    }

    private function import(?User $user, bool $replace): int
    {
        $dir = dirname(__DIR__).'/starter';

        if ((! $replace && glob(content_path('collections').'/*.yaml'))) {
            return 0;
        }

        $seed = is_file($dir.'/entries.yaml') ? Yaml::read($dir.'/entries.yaml') : [];

        if ($replace) {
            $handles = array_unique([
                ...array_keys((array) ($seed['entries'] ?? [])),
                ...array_map(static fn ($file) => basename($file, '.yaml'), glob($dir.'/content/collections/*.yaml') ?: []),
                ...array_map(basename(...), glob($dir.'/content/collections/*', GLOB_ONLYDIR) ?: []),
            ]);
            foreach ($handles as $handle) {
                if ($this->collections->find($handle)) {
                    foreach ($this->cms->entries()->query()->collection($handle)->get() as $entry) {
                        $this->cms->content()->delete($entry->id, $user);
                    }
                }
                DB::table('revisions')->where('collection', $handle)->delete();
                $this->collections->delete($handle);
            }
        }

        if (is_dir($dir.'/content')) {
            Files::copyDirectory($dir.'/content', content_path());
            $this->collections->flush();
            $this->cms->entries()->flush();
        }

        $ids = [];

        $create = function (array $items, string $collection, ?string $parent) use (&$create, &$ids, $user): void {
            foreach ($items as $item) {
                $entry = $this->cms->content()->create($collection, [
                    'title' => (string) $item['title'],
                    'slug' => $item['slug'] ?? null,
                    'data' => (array) ($item['data'] ?? []),
                    'status' => 'published',
                    'parentId' => $parent,
                ], $user);
                $ids[$entry->slug] = $entry->id;
                $create((array) ($item['children'] ?? []), $collection, $entry->id);
            }
        };

        foreach ((array) ($seed['entries'] ?? []) as $collection => $items) {
            $create((array) $items, (string) $collection, null);
        }

        if ($this->cms->abilities()->has('navigation/save')) {
            foreach ((array) ($seed['menus'] ?? []) as $handle => $items) {
                $items = array_values(array_filter(array_map(static fn (array $i) => isset($i['page'])
                    ? (isset($ids[$i['page']]) ? array_filter(['entry' => $ids[$i['page']], 'label' => $i['label'] ?? null]) : null)
                    : $i, (array) $items)));
                $this->cms->abilities()->execute('navigation/save', ['handle' => (string) $handle, 'items' => $items], AbilityContext::system('install'));
            }
        }

        return count($ids);
    }
}
