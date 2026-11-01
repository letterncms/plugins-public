<?php

declare(strict_types=1);

namespace Cms\Media;

use Cms\Blocks\BlockRegistry;
use Cms\Support\Yaml;

/**
 * Where media is used: entries (with their unpublished changes), templates, components,
 * globals, design tokens and settings. It answers what would break: ids and /media/ URLs
 * that point at nothing, records without a file, files without a record, and items
 * nothing uses.
 */
final class MediaReferences
{
    private const string TOKEN = '/media:([0-9a-z]{26})/i';

    /** @var array<string, true> field handles that hold media, at any depth */
    private array $handles = [];

    /** @return list<array{id: string, where: array{kind: string, id: string, title: string, locale?: ?string, path: string}}> */
    public function all(): array
    {
        $this->handles = $this->mediaHandles();
        $found = [];

        foreach (cms()->entries()->query()->get() as $entry) {
            $where = ['kind' => 'entry', 'id' => (string) $entry->id, 'title' => (string) $entry->title, 'locale' => $entry->locale];
            $this->walk($entry->data, $where, '', $found);
            $entry->hasDraft() && $this->walk($entry->working(), $where, '', $found);
        }

        foreach (Yaml::directory(content_path('globals')) as $handle => $data) {
            $this->walk($data, ['kind' => 'global', 'id' => (string) $handle, 'title' => (string) $handle], '', $found);
        }

        $tokens = content_path('design/tokens.json');

        if (is_file($tokens)) {
            $this->walk((array) json_decode((string) file_get_contents($tokens), true), ['kind' => 'design', 'id' => 'tokens', 'title' => 'Design tokens'], '', $found, styles: true);
        }

        return $found;
    }

    /**
     * @return array{items: int, dangling: list<array>, missing_files: list<string>, orphan_files: list<string>, unused: list<string>, parse_errors: list<string>}
     */
    public function report(): array
    {
        $references = $this->all();
        $items = MediaItem::query()->get(['id', 'path'])->keyBy('id');
        $paths = $items->pluck('path')->flip();
        $usedIds = [];
        $usedPaths = [];
        $dangling = [];

        foreach ($references as $reference) {
            $id = $reference['id'];

            if (str_starts_with($id, '/')) {
                $path = ltrim((string) parse_url($id, PHP_URL_PATH), '/');
                $usedPaths[$path] = true;

                if (isset($paths[$path])) {
                    $usedIds[$items->firstWhere('path', $path)->id] = true;
                } elseif (! is_file(public_path($path))) {
                    $dangling[] = $reference;
                }
            } elseif (isset($items[strtolower($id)])) {
                $usedIds[strtolower($id)] = true;
            } else {
                $dangling[] = $reference;
            }
        }

        $orphans = [];
        $root = public_path('media');

        if (is_dir($root)) {
            foreach (new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($root, \FilesystemIterator::SKIP_DOTS)) as $file) {
                $relative = 'media/'.ltrim(str_replace('\\', '/', substr($file->getPathname(), strlen($root))), '/');

                if ($file->isFile() && ! isset($paths[$relative]) && ! isset($usedPaths[$relative])) {
                    $orphans[] = $relative;
                }
            }
        }

        sort($orphans);

        return [
            'items' => $items->count(),
            'dangling' => $dangling,
            'missing_files' => $items->filter(static fn ($item) => ! is_file(public_path($item->path)))->keys()->values()->all(),
            'orphan_files' => $orphans,
            'unused' => $items->keys()->diff(array_keys($usedIds))->values()->all(),
            'parse_errors' => [],
        ];
    }

    /** @return list<array> where one item is used, by id or by its URL */
    public function of(MediaItem $item): array
    {
        return array_values(array_filter($this->all(), static fn (array $r) => strtolower($r['id']) === $item->id || (str_starts_with($r['id'], '/') && ltrim((string) parse_url($r['id'], PHP_URL_PATH), '/') === $item->path)));
    }

    private function walk(mixed $value, array $where, string $path, array &$found, bool $styles = false): void
    {
        if (is_string($value)) {
            $key = (string) substr((string) strrchr('.'.$path, '.'), 1);
            $key = is_numeric($key) ? (string) substr((string) strrchr('.'.substr($path, 0, -strlen($key) - 1), '.'), 1) : $key;

            if (preg_match_all(self::TOKEN, $value, $matches)) {
                foreach ($matches[1] as $id) {
                    $found[] = ['id' => strtolower($id), 'where' => [...$where, 'path' => $path]];
                }
            } elseif (isset($this->handles[$key]) && preg_match('/^[0-9a-z]{26}$/i', $value)) {
                $found[] = ['id' => strtolower($value), 'where' => [...$where, 'path' => $path]];
            } elseif (preg_match('#^/media/[^\s"\'<>]+$#', $value)) {
                $found[] = ['id' => $value, 'where' => [...$where, 'path' => $path]];
            }

            return;
        }

        if (is_array($value)) {
            foreach ($value as $key => $child) {
                $this->walk($child, $where, ltrim($path.'.'.$key, '.'), $found, $styles);
            }
        }
    }

    /** Every handle a media field goes by: in blueprints and in block types. */
    private function mediaHandles(): array
    {
        $handles = ['image' => true, 'poster' => true];

        foreach (cms()->collections()->all() as $collection) {
            $this->collect((array) ($collection->blueprint()->toArray()['fields'] ?? []), $handles);
        }

        // Blocks may be off on this site: blueprints are all there is then.
        if (app()->bound(BlockRegistry::class)) {
            foreach (app(BlockRegistry::class)->all() as $type) {
                foreach ($type->blueprint()->fields() as $field) {
                    $field->type === 'media' && $handles[$field->handle] = true;
                }
            }
        }

        return $handles;
    }

    private function collect(array $fields, array &$handles): void
    {
        foreach ($fields as $field) {
            if (! is_array($field)) {
                continue;
            }

            ($field['type'] ?? null) === 'media' && isset($field['handle']) && $handles[(string) $field['handle']] = true;

            foreach (['fields', 'sets'] as $nested) {
                is_array($field[$nested] ?? null) && $this->collect($field[$nested], $handles);
            }
        }
    }
}
