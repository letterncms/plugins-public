<?php

declare(strict_types=1);

namespace Cms\Blocks;

use Cms\Extend\Hooks;
use Cms\Cms;
use Throwable;

/**
 * Every block type available on this site: core blocks, plugin blocks
 * (e.g. forms/form) and theme blocks (themes/<theme>/blocks/<name>/block.json).
 */
final class BlockRegistry
{
    /** @var array<string, BlockType> */
    private array $types = [];

    private bool $sourcesLoaded = false;

    public function __construct(private readonly Cms $cms, private readonly Hooks $hooks) {}

    public function register(array|BlockType $type): BlockType
    {
        $type = $type instanceof BlockType ? $type : BlockType::make($type);

        return $this->types[$type->name] = $type;
    }

    public function get(string $name): ?BlockType
    {
        $this->loadSources();

        return $this->types[$name] ?? null;
    }

    /** @return array<string, BlockType> */
    public function all(): array
    {
        $this->loadSources();

        return $this->types;
    }

    /** Catalog for editors and agents, with theme variants applied. */
    public function catalog(): array
    {
        $variants = $this->hooks->filter('blocks.variants', []);

        return array_values(array_map(function (BlockType $t) use ($variants) {
            $data = $t->toArray();

            if (isset($variants[$t->name])) {
                $data['variants'] = $variants[$t->name];
            }

            return $data;
        }, $this->all()));
    }

    /** Resolve field definitions: fill `dynamic` option lists (collections, patterns…). */
    public function resolveFields(array $fields): array
    {
        foreach ($fields as $i => $field) {
            if (isset($field['dynamic'])) {
                $fields[$i]['options'] = match ($field['dynamic']) {
                    'collections' => array_map(static fn ($c) => $c->title, array_filter($this->cms->collections()->all(), static fn ($c) => $c->routable())),
                    'patterns' => array_map(static fn ($p) => $p['title'], array_filter(app(Patterns::class)->all(), static fn ($p) => $p['synced'])),
                    'sources' => array_column(app(DataSources::class)->catalog(), 'label', 'name'),
                    // The site's design system adds its own looks to these (`buttons` in tokens.json).
                    'button-variants' => (array) $this->hooks->filter('blocks.options.button-variants', ['primary' => 'Primary', 'secondary' => 'Secondary', 'ghost' => 'Ghost', 'link' => 'Text link']),
                    default => (array) $this->hooks->filter('blocks.options.'.$field['dynamic'], []),
                };
                unset($fields[$i]['dynamic']);
            }
        }

        return $fields;
    }

    /** Theme/plugin block folders: each <dir>/<name>/block.json (+ view.blade.php). */
    private function loadSources(): void
    {
        if ($this->sourcesLoaded) {
            return;
        }

        $this->sourcesLoaded = true;

        foreach ((array) $this->hooks->filter('blocks.folders', []) as $source) {
            $dir = is_array($source) ? $source['path'] : (string) $source;
            $namespace = is_array($source) ? ($source['namespace'] ?? null) : null;

            foreach (glob(rtrim($dir, '/').'/*/block.json') ?: [] as $file) {
                try {
                    $data = json_decode((string) file_get_contents($file), true, 64, JSON_THROW_ON_ERROR);
                    $name = (string) ($data['name'] ?? basename(dirname($file)));
                    $view = is_file(dirname($file).'/view.blade.php') ? dirname($file).'/view.blade.php' : null;

                    $this->register([...$data, 'name' => $namespace ? "{$namespace}/{$name}" : $name, 'view' => $view ? 'file:'.$view : ($data['view'] ?? null), 'package' => $data['package'] ?? 'theme']);
                } catch (Throwable $e) {
                    report($e);
                }
            }
        }
    }
}
