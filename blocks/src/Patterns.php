<?php

declare(strict_types=1);

namespace Cms\Blocks;

use Cms\Content\Entries\Entry;
use Cms\Content\Fields\Blueprint;
use Cms\Extend\Hooks;
use Cms\Cms;

/**
 * Components and sections are entries of the `components` collection
 * (content/collections/components/*.md), so they have drafts, publishing,
 * history and comments like any entry. Plugins can add read-only ones through
 * the `blocks.pattern-sources` filter (folders of <handle>.json).
 *
 * A synced pattern is a **component**: inserted as a reference, it updates
 * everywhere, and its `props` (fields) let each instance set its own values —
 * blocks inside bind to them as `props.<handle>`. An unsynced pattern is a
 * section that is copied into the document.
 */
final class Patterns
{
    public const string COLLECTION = 'components';

    /** @var array<string, array<string, array>> */
    private array $cache = [];

    public function __construct(private readonly Cms $cms, private readonly Hooks $hooks) {}

    /** @return array<string, array{handle: string, id: ?string, title: string, description: string, category: string, synced: bool, props: list<array>, blocks: list<array>, source: string, editable: bool}> */
    public function all(bool $working = false): array
    {
        $key = $working ? 'working' : 'live';

        if (isset($this->cache[$key])) {
            return $this->cache[$key];
        }

        $out = [];

        foreach ((array) $this->hooks->filter('blocks.pattern-sources', []) as $source) {
            foreach (glob(rtrim((string) $source['path'], '/').'/*.json') ?: [] as $file) {
                $data = json_decode((string) file_get_contents($file), true);

                if (is_array($data)) {
                    $handle = basename($file, '.json');
                    $out[$handle] = self::shape($handle, null, $data, (string) ($source['source'] ?? 'plugin'), false);
                }
            }
        }

        if ($this->cms->collections()->find(self::COLLECTION)) {
            $query = $this->cms->entries()->query()->collection(self::COLLECTION);

            foreach (($working ? $query : $query->live())->get() as $entry) {
                $entry = $working ? $entry->withWorkingCopy() : $entry;
                $out[$entry->slug] = self::shape($entry->slug, $entry->id, ['title' => $entry->title, ...$entry->data], 'site', true);
            }
        }

        ksort($out);

        return $this->cache[$key] = $out;
    }

    /** A pattern; with $working, its unpublished changes. Rendering one makes the page depend on it. */
    public function find(string $handle, bool $working = false): ?array
    {
        $pattern = $this->all($working)[$handle] ?? null;

        if ($pattern && $pattern['id']) {
            $this->hooks->dispatch('render.depend', 'entry:'.$pattern['id']);
        }

        return $pattern;
    }

    /**
     * Property values for one instance: the component's defaults, the instance's
     * own values, and values bound to data (`bind: {"values.title": "item.title"}`).
     * On the canvas ($editor) a link without a value is "#", so linked elements look
     * as they will; empty text and images show the renderer's placeholders.
     */
    public function values(array $pattern, array $values, Scope $scope, array $bind = [], bool $editor = false): array
    {
        $fields = (array) ($pattern['props'] ?? []);

        if ($fields === []) {
            return [];
        }

        $out = array_replace(Blueprint::make($fields)->defaults(), array_intersect_key($values, array_flip(array_column($fields, 'handle'))));

        foreach ($bind as $key => $path) {
            if (str_starts_with($key, 'values.')) {
                $out[substr($key, 7)] = $scope->resolve($path);
            }
        }

        if ($editor) {
            foreach ($fields as $field) {
                $handle = (string) ($field['handle'] ?? '');

                if (in_array($field['type'] ?? '', ['url', 'link'], true) && BlockRenderer::blank($out[$handle] ?? null)) {
                    $out[$handle] = '#';
                }
            }
        }

        return $out;
    }

    public function flush(): void
    {
        $this->cache = [];
    }

    public static function shape(string $handle, ?string $id, array $data, string $source, bool $editable): array
    {
        $synced = (bool) ($data['synced'] ?? false);

        return [
            'handle' => $handle,
            'id' => $id,
            'title' => (string) ($data['title'] ?? $handle),
            'description' => (string) ($data['description'] ?? ''),
            'category' => (string) (($data['category'] ?? '') ?: ($synced ? 'Components' : 'Sections')),
            'synced' => $synced,
            'props' => array_values(array_filter((array) ($data['props'] ?? []), 'is_array')),
            'blocks' => array_values((array) ($data['blocks'] ?? [])),
            'source' => $source,
            'editable' => $editable,
        ];
    }
}
