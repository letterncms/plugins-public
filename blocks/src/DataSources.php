<?php

declare(strict_types=1);

namespace Cms\Blocks;

use Closure;
use Cms\Extend\Hooks;

/**
 * Lists a Repeat block can loop over. Plugins add their own through the
 * `blocks.data-sources` filter:
 *
 *   $hooks->addFilter('blocks.data-sources', fn (array $sources) => [...$sources, 'packages' => [
 *       'label' => 'Installed Packages',
 *       'fields' => [['handle' => 'kind', 'type' => 'select', 'options' => ['plugin' => 'Plugins', 'theme' => 'Themes']]],
 *       'item' => ['name' => 'Package name', 'version' => 'Version', 'url' => 'Link'],   // for the binding picker
 *       'resolve' => fn (array $props, Scope $scope) => [...],                          // list of arrays or objects
 *   ]]);
 */
final class DataSources
{
    /** @var array<string, array>|null */
    private ?array $sources = null;

    public function __construct(private readonly Hooks $hooks) {}

    /** @return array<string, array{label: string, fields: list<array>, item: array<string, string>, resolve: Closure}> */
    public function all(): array
    {
        if ($this->sources !== null) {
            return $this->sources;
        }

        $sources = [
            'list' => [
                'label' => 'A list in this scope',
                'description' => 'Any list the page already has: item.children, entry.tags, props.items…',
                'fields' => [['handle' => 'path', 'type' => 'text', 'label' => 'List', 'instructions' => 'e.g. item.children or entry.gallery', 'required' => true]],
                'item' => [],
                'resolve' => static function (array $props, Scope $scope): array {
                    $value = $scope->get((string) ($props['path'] ?? ''));

                    return is_iterable($value) ? array_values(is_array($value) ? $value : iterator_to_array($value)) : [];
                },
            ],
            'numbers' => [
                'label' => 'Numbers',
                'description' => 'Repeat a fixed number of times (placeholders, ratings, marquees).',
                'fields' => [['handle' => 'count', 'type' => 'number', 'label' => 'Times', 'min' => 1, 'max' => 100, 'default' => 3]],
                'item' => ['number' => __('Number (1, 2, 3…)')],
                'resolve' => static fn (array $props) => array_map(static fn ($n) => ['number' => $n], range(1, max(1, min(100, (int) ($props['count'] ?? 3))))),
            ],
        ];

        foreach ((array) $this->hooks->filter('blocks.data-sources', []) as $name => $source) {
            if (is_string($name) && is_array($source) && ($source['resolve'] ?? null) instanceof Closure) {
                $sources[$name] = [
                    'label' => (string) ($source['label'] ?? $name),
                    'description' => (string) ($source['description'] ?? ''),
                    'fields' => array_values((array) ($source['fields'] ?? [])),
                    'item' => (array) ($source['item'] ?? []),
                    'resolve' => $source['resolve'],
                ];
            }
        }

        return $this->sources = $sources;
    }

    public function get(string $name): ?array
    {
        return $this->all()[$name] ?? null;
    }

    /** @return list<mixed> */
    public function resolve(string $name, array $props, Scope $scope): array
    {
        $source = $this->get($name);

        if (! $source) {
            return [];
        }

        $items = ($source['resolve'])($props, $scope);
        $items = is_array($items) ? array_values($items) : [];
        $groupBy = trim((string) ($props['group_by'] ?? ''));

        return $groupBy === '' ? $items : self::grouped($items, $groupBy);
    }

    /**
     * A list as its groups, in the order they first appear: [{label, items}, …]. Items
     * without a value share one group with an empty label.
     *
     * @param  list<mixed>  $items
     * @return list<array{label: string, items: list<mixed>}>
     */
    private static function grouped(array $items, string $path): array
    {
        $groups = [];

        foreach ($items as $item) {
            $label = trim(Scope::text((new Scope(['item' => $item]))->get('item.'.$path)));
            $groups[$label] ??= ['label' => $label, 'items' => []];
            $groups[$label]['items'][] = $item;
        }

        return array_values($groups);
    }

    /** For the editor and agents: everything except the resolvers. */
    public function catalog(): array
    {
        $out = [];

        foreach ($this->all() as $name => $source) {
            $out[] = ['name' => $name, 'label' => $source['label'], 'description' => $source['description'] ?? '', 'fields' => $source['fields'], 'item' => (object) $source['item']];
        }

        return $out;
    }
}
