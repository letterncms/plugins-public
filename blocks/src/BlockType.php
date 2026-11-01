<?php

declare(strict_types=1);

namespace Cms\Blocks;

use Closure;
use InvalidArgumentException;
use Cms\Content\Fields\Blueprint;

/**
 * A kind of block. Declarative so themes can add blocks with a block.json and a
 * Blade view, and agents can read the catalog.
 */
final class BlockType
{
    /** Inspector sections a block offers (see StyleCompiler::PROPERTIES for the properties in each). */
    public const array STYLE_GROUPS = ['layout', 'position', 'size', 'spacing', 'typography', 'fill', 'border', 'effects', 'transform', 'transition'];

    private ?Blueprint $blueprint = null;

    /**
     * @param  list<array>  $fields  Prop fields (blueprint format)
     * @param  bool|list<string>  $children  Accepts child blocks (true = any, list = allowed types)
     * @param  list<string>  $styles  Style sections shown in the inspector (empty = all)
     * @param  list<string>  $inline  Props edited inline as rich text (inline HTML)
     * @param  list<array>  $template  Initial children when inserted
     * @param  array<string, string>  $placeholders  What empty text props show on the canvas (prop => text); inline props default to their label
     */
    public function __construct(
        public readonly string $name,
        public readonly string $title,
        public readonly string $icon = 'square',
        public readonly string $category = 'design',
        public readonly string $description = '',
        public readonly array $keywords = [],
        public readonly array $fields = [],
        public readonly bool|array $children = false,
        public readonly array $styles = [],
        public readonly array $inline = [],
        public readonly array $defaults = [],
        public readonly array $template = [],
        public readonly ?string $parent = null,
        public readonly ?string $view = null,
        public readonly ?Closure $render = null,
        public readonly ?string $package = null,
        public readonly array $variants = [],
        public readonly array $example = [],
        public readonly bool $defer = false,
        public readonly ?Closure $derive = null,
        public readonly string $html = 'div',
        public readonly array $placeholders = [],
    ) {
        if (! preg_match('#^([a-z0-9-]+/)?[a-z0-9-]+$#', $name)) {
            throw new InvalidArgumentException("Invalid block name {$name}.");
        }
    }

    public static function make(array $d): self
    {
        return new self(
            name: (string) $d['name'],
            title: (string) ($d['title'] ?? ucfirst((string) $d['name'])),
            icon: (string) ($d['icon'] ?? 'square'),
            category: (string) ($d['category'] ?? 'design'),
            description: (string) ($d['description'] ?? ''),
            keywords: array_values((array) ($d['keywords'] ?? [])),
            fields: array_values((array) ($d['fields'] ?? [])),
            children: is_array($d['children'] ?? null) ? array_values($d['children']) : (bool) ($d['children'] ?? false),
            styles: array_values(array_intersect((array) ($d['styles'] ?? self::STYLE_GROUPS), self::STYLE_GROUPS)),
            inline: array_values((array) ($d['inline'] ?? [])),
            defaults: (array) ($d['defaults'] ?? []),
            template: array_values((array) ($d['template'] ?? [])),
            parent: isset($d['parent']) ? (string) $d['parent'] : null,
            view: isset($d['view']) ? (string) $d['view'] : null,
            render: ($d['render'] ?? null) instanceof Closure ? $d['render'] : null,
            package: isset($d['package']) ? (string) $d['package'] : null,
            variants: (array) ($d['variants'] ?? []),
            example: (array) ($d['example'] ?? []),
            defer: (bool) ($d['defer'] ?? false),
            derive: ($d['derive'] ?? null) instanceof Closure ? $d['derive'] : null,
            html: (string) ($d['html'] ?? 'div'),
            placeholders: array_map('strval', (array) ($d['placeholders'] ?? [])),
        );
    }

    public function blueprint(): Blueprint
    {
        // Dynamic options belong to the current content catalog, which can change in a batch.
        if (array_any($this->fields, static fn ($field) => is_array($field) && isset($field['dynamic']))) {
            return Blueprint::make(app(BlockRegistry::class)->resolveFields($this->fields));
        }

        return $this->blueprint ??= Blueprint::make(app(BlockRegistry::class)->resolveFields($this->fields));
    }

    /**
     * Names from a dynamic list can't be judged right now: what they name may belong
     * to a plugin that is off (the `menu` source without navigation). They stay as
     * they are, so an entry still saves and works again when the plugin comes back.
     *
     * @return list<string> handles
     */
    public function unjudged(array $props): array
    {
        $out = [];

        foreach ($this->fields as $field) {
            if (is_array($field) && isset($field['dynamic']) && is_string($value = $props[$field['handle'] ?? ''] ?? null) && $value !== '') {
                $out[] = $field['handle'];
            }
        }

        return $out;
    }

    /**
     * Placeholders for empty text on the canvas, prop => English text: the block's
     * own, else every inline prop's field label.
     *
     * @return array<string, string>
     */
    public function placeholders(): array
    {
        if ($this->placeholders !== []) {
            return $this->placeholders;
        }

        $out = [];

        foreach ($this->inline as $prop) {
            $out[$prop] = (string) ($this->blueprint()->field($prop)?->label ?? ucfirst($prop));
        }

        return $out;
    }

    public function acceptsChildren(): bool
    {
        return $this->children !== false;
    }

    public function allows(string $child): bool
    {
        return $this->children === true || (is_array($this->children) && in_array($child, $this->children, true));
    }

    public function toArray(): array
    {
        return array_filter([
            'name' => $this->name,
            'title' => $this->title,
            'icon' => $this->icon,
            'category' => $this->category,
            'description' => $this->description,
            'keywords' => $this->keywords,
            'fields' => $this->blueprint()->toArray(),
            'children' => $this->children,
            'styles' => $this->styles,
            'inline' => $this->inline,
            'defaults' => $this->defaults ?: (object) [],
            'template' => $this->template,
            'parent' => $this->parent,
            'variants' => $this->variants ?: null,
            'example' => $this->example ?: null,
            'package' => $this->package,
            'html' => $this->html,
            'placeholders' => $this->placeholders() ?: null,
        ], static fn ($v) => $v !== null && $v !== []);
    }
}
