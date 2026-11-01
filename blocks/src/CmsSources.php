<?php

declare(strict_types=1);

namespace Cms\Blocks;

use Cms\Abilities\AbilityContext;
use Cms\Cms;
use Cms\Packages\PackageManifest;
use Cms\Packages\PackageRepository;

/**
 * The CMS describes itself: Repeat sources over this installation's abilities,
 * blocks, field types and plugins. Reference pages and "built with" facts are
 * designs over these lists — no site needs a plugin of its own to show them,
 * and the docs can never drift from the code.
 */
final class CmsSources
{
    private const array TRANSPORTS = ['rest' => 'REST', 'mcp' => 'MCP', 'assistant' => 'Assistant', 'cli' => 'CLI'];

    public function __construct(private readonly Cms $cms, private readonly BlockRegistry $blocks, private readonly PackageRepository $packages) {}

    /** @return array<string, array> For the `blocks.data-sources` filter. */
    public function sources(): array
    {
        return [
            'cms-facts' => [
                'label' => '{brand}: facts',
                'description' => 'Counts from this installation: abilities, block types, field types, built-in plugins.',
                'item' => ['number' => __('The count'), 'label' => __('What Is Counted'), 'note' => __('A short note')],
                'resolve' => fn () => $this->facts(),
            ],
            'cms-packages' => [
                'label' => '{brand}: built-in plugins',
                'description' => 'Every built-in plugin of this installation, A–Z.',
                'item' => ['name' => __('Package name'), 'label' => __('Label'), 'description' => __('Description'), 'version' => __('Version')],
                'resolve' => fn () => $this->builtIn(),
            ],
            'cms-modules' => [
                'label' => '{brand}: modules',
                'description' => 'The modules the admin is made of, A–Z; required ones cannot be switched off.',
                'item' => ['name' => __('Package name'), 'label' => __('Label'), 'description' => __('Description'), 'version' => __('Version'), 'required' => __('Cannot be switched off')],
                'resolve' => fn () => $this->modules(),
            ],
            'cms-abilities' => [
                'label' => '{brand}: abilities',
                'description' => 'Every ability of this installation.',
                'fields' => [['handle' => 'limit', 'type' => 'number', 'label' => 'How Many', 'min' => 1, 'max' => 400, 'default' => 40]],
                'item' => ['name' => __('Name, e.g. entries/publish'), 'label' => __('Label')],
                'resolve' => fn (array $props) => array_slice($this->abilities(), 0, max(1, (int) ($props['limit'] ?? 40))),
            ],
            'cms-ability-groups' => [
                'label' => '{brand}: abilities by namespace',
                'description' => 'Every ability grouped by namespace. Each group has abilities; each ability a table of its inputs.',
                'item' => ['name' => __('Namespace'), 'id' => __('Anchor'), 'count' => __('How Many'), 'abilities' => __('Abilities (list: name, id, description, pills, table)')],
                'resolve' => fn () => $this->abilityGroups(),
            ],
            'cms-block-categories' => [
                'label' => '{brand}: blocks by category',
                'description' => 'Every block type, grouped by category.',
                'item' => ['name' => __('Category'), 'id' => __('Anchor'), 'blocks' => __('Blocks (list: name, title, id, description, pills, table, styles)')],
                'resolve' => fn () => $this->blockCategories(),
            ],
            'cms-field-types' => [
                'label' => '{brand}: field types',
                'description' => 'Every field type with its options.',
                'item' => ['key' => __('Key'), 'label' => __('Name'), 'id' => __('Anchor'), 'description' => __('Description'), 'table' => __('Options as table rows')],
                'resolve' => fn () => $this->fieldTypes(),
            ],
        ];
    }

    /** @return list<array{number: int, label: string, note: string}> */
    private function facts(): array
    {
        return [
            ['number' => count($this->cms->abilities()->all()), 'label' => 'abilities', 'note' => 'every action, one API'],
            ['number' => count($this->blocks->all()), 'label' => 'block types', 'note' => 'primitives and content blocks'],
            ['number' => count($this->cms->fields()->all()), 'label' => 'field types', 'note' => 'to model any content'],
            ['number' => count($this->builtIn()), 'label' => 'first-party plugins', 'note' => 'each one replaceable'],
        ];
    }

    /** @return list<array{name: string, label: string, description: string, version: string}> */
    private function builtIn(): array
    {
        $out = array_map(
            static fn (PackageManifest $p) => ['name' => $p->name, 'label' => $p->label, 'description' => __($p->description), 'version' => $p->version],
            array_values(array_filter($this->packages->plugins(), static fn (PackageManifest $p) => $p->firstParty() && ! $p->core)),
        );
        usort($out, static fn ($a, $b) => strcmp($a['name'], $b['name']));

        return $out;
    }

    /** @return list<array{name: string, label: string, description: string, version: string, required: bool}> */
    private function modules(): array
    {
        $out = array_map(
            static fn (PackageManifest $p) => ['name' => $p->name, 'label' => $p->label, 'description' => $p->description, 'version' => $p->version, 'required' => $p->required],
            array_values(array_filter($this->packages->plugins(), static fn (PackageManifest $p) => $p->core)),
        );
        usort($out, static fn ($a, $b) => strcmp($a['name'], $b['name']));

        return $out;
    }

    /** @return list<array{name: string, label: string}> */
    private function abilities(): array
    {
        $out = [];

        foreach ($this->cms->abilities()->all() as $name => $ability) {
            $out[] = ['name' => (string) $name, 'label' => (string) ($ability->label ?? $name)];
        }

        return $out;
    }

    private function abilityGroups(): array
    {
        $groups = [];

        foreach ($this->cms->abilities()->all() as $ability) {
            $hidden = array_values(array_map(static fn ($k) => self::TRANSPORTS[$k], array_filter(array_keys(self::TRANSPORTS), static fn ($k) => ! $ability->exposedTo($k))));
            $groups[explode('/', $ability->name)[0]][] = [
                'name' => $ability->name,
                'id' => str_replace('/', '-', $ability->name),
                'description' => $ability->description ?: $ability->label,
                'pills' => array_values(array_filter([
                    $ability->isReadOnly() ? 'read-only' : null,
                    $ability->isDestructive() ? 'destructive' : null,
                    is_string($ability->permission) ? 'needs '.$ability->permission : null,
                    $hidden ? 'not via '.implode(', ', $hidden) : null,
                ])),
                'table' => $this->schemaTable((array) $ability->input),
            ];
        }

        ksort($groups);

        return array_values(array_map(static fn ($name, $abilities) => ['name' => $name, 'id' => 'ns-'.$name, 'count' => count($abilities), 'abilities' => $abilities], array_keys($groups), $groups));
    }

    /** A JSON schema's properties as table rows (first row is the header). */

    /** A JSON schema's properties as table rows (first row is the header). */
    private function schemaTable(array $schema): array
    {
        $properties = (array) ($schema['properties'] ?? []);

        if ($properties === []) {
            return [];
        }

        $required = (array) ($schema['required'] ?? []);
        $rows = [['Input', 'Type', 'Notes']];

        foreach ($properties as $name => $p) {
            $p = (array) $p;
            $type = $p['type'] ?? (isset($p['enum']) ? 'string' : 'any');
            $type = is_array($type) ? implode(' | ', $type) : (string) $type;

            if ($type === 'array' && isset($p['items']['type'])) {
                $type = (is_array($p['items']['type']) ? implode('|', $p['items']['type']) : $p['items']['type']).'[]';
            }

            $notes = e((string) ($p['description'] ?? ''));
            isset($p['enum']) && $notes .= ' One of '.e(implode(', ', array_map(static fn ($v) => is_scalar($v) ? (string) $v : (string) json_encode($v), $p['enum']))).'.';
            array_key_exists('default', $p) && $notes .= ' Default '.e(is_scalar($p['default']) ? var_export($p['default'], true) : (string) json_encode($p['default'])).'.';

            $rows[] = ['<code>'.e((string) $name).'</code>'.(in_array($name, $required, true) ? ' <strong>required</strong>' : ''), '<code>'.e($type).'</code>', trim($notes)];
        }

        return $rows;
    }

    private function blockCategories(): array
    {
        $types = $this->blocks->all();
        ksort($types);
        $groups = [];

        foreach ($types as $type) {
            $rows = [['Prop', 'Field', 'Notes']];

            foreach ($type->fields as $field) {
                $options = ! empty($field['options']) && is_array($field['options'])
                    ? ' Options: '.implode(', ', array_is_list($field['options']) ? array_map('strval', $field['options']) : array_map('strval', array_keys($field['options']))).'.'
                    : '';
                $rows[] = ['<code>'.e((string) $field['handle']).'</code>', '<code>'.e((string) ($field['type'] ?? 'text')).'</code>', e(trim(($field['label'] ?? '').(! empty($field['instructions']) ? ' — '.$field['instructions'] : '').$options))];
            }

            $groups[$type->category][] = [
                'name' => $type->name,
                'title' => $type->title,
                'id' => 'block-'.str_replace('/', '-', $type->name),
                'description' => $type->description,
                'pills' => array_values(array_filter([
                    $type->acceptsChildren() ? (is_array($type->children) ? 'contains '.implode(', ', $type->children) : 'contains blocks') : null,
                    $type->parent ? 'inside '.$type->parent : null,
                ])),
                'table' => count($rows) > 1 ? $rows : [],
            ];
        }

        return array_values(array_map(static fn ($category, $blocks) => ['name' => ucfirst($category), 'id' => 'cat-'.$category, 'blocks' => $blocks], array_keys($groups), $groups));
    }

    private function fieldTypes(): array
    {
        $types = $this->cms->abilities()->execute('fields/types', [], AbilityContext::system('internal'));

        return array_values(array_map(static function (array $t) {
            $rows = [['Option', 'Type', 'Notes']];

            foreach ((array) ($t['config'] ?? []) as $option) {
                $rows[] = ['<code>'.e((string) $option['handle']).'</code>', '<code>'.e((string) ($option['type'] ?? 'text')).'</code>', e(trim(($option['label'] ?? '').(! empty($option['instructions']) ? ' — '.$option['instructions'] : '')))];
            }

            return ['key' => $t['key'], 'label' => $t['label'], 'id' => 'field-'.$t['key'], 'description' => (string) ($t['description'] ?? ''), 'table' => count($rows) > 1 ? $rows : []];
        }, $types));
    }
}
