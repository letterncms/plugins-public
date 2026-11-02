<?php

declare(strict_types=1);

namespace Cms\Blocks;

use Illuminate\Support\Str;
use Cms\Support\Html\Sanitizer;
use Cms\Support\Schema\SchemaException;

/**
 * Normalizes a block tree: ids, known types, sanitized inline text,
 * validated props, safe styles, size limits.
 */
final class Document
{
    public const int MAX_NODES = 3000;

    public const int MAX_DEPTH = 16;

    private int $count = 0;

    public function __construct(
        private readonly BlockRegistry $registry,
        private readonly StyleCompiler $styles,
    ) {}

    public static function id(): string
    {
        return 'b'.Str::lower(Str::random(10));
    }

    /**
     * @param  list<mixed>  $nodes
     * @return list<array>
     *
     * @throws SchemaException
     */
    public function normalize(mixed $nodes, bool $strict = false): array
    {
        $this->count = 0;
        $seen = [];

        return $this->nodes(is_array($nodes) ? $nodes : [], 0, $strict, $seen, 'blocks');
    }

    private function nodes(array $nodes, int $depth, bool $strict, array &$seen, string $path): array
    {
        if ($depth > self::MAX_DEPTH) {
            throw new SchemaException([$path => __('Blocks are nested too deeply.')]);
        }

        $out = [];

        foreach (array_values($nodes) as $i => $node) {
            if (! is_array($node) || ! isset($node['type']) || ! is_string($node['type'])) {
                continue;
            }

            if (++$this->count > self::MAX_NODES) {
                throw new SchemaException([$path => __('Too many blocks.')]);
            }

            $type = $this->registry->get($node['type']);
            $id = is_string($node['id'] ?? null) && preg_match('/^[a-z0-9_-]{1,40}$/i', $node['id']) && ! isset($seen[$node['id']]) ? $node['id'] : self::id();
            $seen[$id] = true;

            // Unknown types (e.g. a disabled plugin's block) are kept verbatim so nothing is lost.
            if (! $type) {
                $out[] = ['id' => $id, ...array_diff_key($node, ['id' => 1])];
                continue;
            }

            $props = (array) ($node['props'] ?? []);

            foreach ($type->inline as $prop) {
                if (isset($props[$prop])) {
                    $props[$prop] = is_array($props[$prop])
                        ? array_values(array_map(static fn ($v) => is_string($v) ? Sanitizer::inline($v) : '', $props[$prop]))
                        : Sanitizer::inline((string) $props[$prop]);
                }
            }

            try {
                $props = $type->blueprint()->without($type->unjudged($props))->process($props, partial: ! $strict);
            } catch (SchemaException $e) {
                $errors = [];

                foreach ($e->errors as $key => $message) {
                    $errors["{$path}.{$i}.props.{$key}"] = $message;
                }

                throw new SchemaException($errors);
            }

            $clean = ['id' => $id, 'type' => $type->name, 'props' => $props];

            if (($style = $this->styles->sanitize($node['style'] ?? null)) !== []) {
                $clean['style'] = $style;
            }

            foreach (['anchor', 'class', 'label'] as $key) {
                if (isset($node[$key]) && is_string($node[$key]) && $node[$key] !== '') {
                    $clean[$key] = mb_substr(strip_tags($node[$key]), 0, 80);
                }
            }

            if (($attrs = self::attrs($node['attrs'] ?? null)) !== []) {
                $clean['attrs'] = $attrs;
            }

            if (($bind = self::bind($node['bind'] ?? null, $type)) !== []) {
                $clean['bind'] = $bind;
            }

            if (($logic = Logic::sanitize($node['logic'] ?? null)) !== []) {
                $clean['logic'] = $logic;
            }

            if (($motion = Motion::sanitize($node['motion'] ?? null)) !== []) {
                $clean['motion'] = $motion;
            }

            if (! empty($node['hidden'])) {
                $clean['hidden'] = true;
            }

            if ($type->acceptsChildren()) {
                $children = array_filter((array) ($node['children'] ?? []), fn ($c) => is_array($c) && (! is_array($type->children) || in_array($c['type'] ?? '', $type->children, true)));
                $clean['children'] = $this->nodes(array_values($children), $depth + 1, $strict, $seen, "{$path}.{$i}.children");
            }

            $out[] = $clean;
        }

        return $out;
    }

    /** Attributes people may set on any element (no event handlers, styles, ids or links — those have their own settings). */
    public const string ATTRIBUTE = '/^(aria-[a-z-]{2,30}|data-(?!cms(?:-|$))[a-z0-9-]{1,40}|role|title|tabindex|target|rel|lang|hreflang|dir|loading|inert|translate|draggable|spellcheck|autocomplete|name|type|for|placeholder|required|width|height|open)$/';

    /** @return array<string, string> */
    public static function attrs(mixed $attrs): array
    {
        $out = [];

        foreach (is_array($attrs) ? array_slice($attrs, 0, 30, true) : [] as $name => $value) {
            if (is_string($name) && preg_match(self::ATTRIBUTE, strtolower($name)) && (is_scalar($value))) {
                $out[strtolower($name)] = mb_substr(strip_tags((string) $value), 0, 300);
            }
        }

        return $out;
    }

    /**
     * Bindings: prop (or visible, hidden, state:<name>, attr:<name>, style:<property>) => data path.
     *
     * @return array<string, string>
     */
    public static function bind(mixed $bind, ?BlockType $type): array
    {
        $out = [];
        $props = $type ? array_map(static fn ($f) => $f->handle, $type->blueprint()->fields()) : [];

        foreach (is_array($bind) ? array_slice($bind, 0, 30, true) : [] as $key => $path) {
            if (! is_string($key) || ! is_string($path) || ! Scope::valid($path = trim($path))) {
                continue;
            }

            $ok = in_array($key, ['visible', 'hidden', 'text', ...$props], true)
                || preg_match('/^state:[a-z][a-z0-9-]{0,30}$/', $key)
                || ($type?->name === 'pattern' && preg_match('/^values\.[a-z_][a-z0-9_]{0,40}$/', $key))
                || (str_starts_with($key, 'attr:') && (preg_match(self::ATTRIBUTE, substr($key, 5)) || in_array(substr($key, 5), ['href', 'src', 'alt', 'id'], true)))
                || (str_starts_with($key, 'style:') && StyleCompiler::group(substr($key, 6)) !== null);

            if ($ok) {
                $out[$key] = $path;
            }
        }

        return $out;
    }

    /** Plain text of a tree (search, excerpts, word counts). */
    /** Props that hold settings, not words a reader sees. */
    private const array NON_TEXT = [
        'id', 'type', 'url', 'link', 'href', 'src', 'variant', 'size', 'level', 'language', 'layout', 'width', 'height', 'tag', 'align',
        'justify', 'direction', 'display', 'gap', 'anchor', 'class', 'ratio', 'fit', 'form', 'media', 'image', 'video', 'entries', 'collection',
        'pattern', 'tone', 'columns', 'stack', 'wrap', 'target', 'icon', 'position', 'autoplay', 'loop', 'muted', 'controls', 'html',
    ];

    /** The words of a document (headings, paragraphs, captions…) as one plain string. */
    public function text(array $nodes): string
    {
        $parts = [];

        $walk = static function (array $nodes) use (&$walk, &$parts): void {
            foreach ($nodes as $node) {
                if (! is_array($node) || ($node['hidden'] ?? false)) {
                    continue;
                }

                foreach ((array) ($node['props'] ?? []) as $key => $value) {
                    if (in_array($key, self::NON_TEXT, true)) {
                        continue;
                    }

                    $value = is_array($value) ? $value : [$value];
                    array_walk_recursive($value, static function ($v) use (&$parts): void {
                        if (is_string($v) && ! preg_match('~^(token:|https?://|/|#|var\()~', $v)) {
                            $parts[] = Sanitizer::text($v);
                        }
                    });
                }

                $walk((array) ($node['children'] ?? []));
            }
        };

        $walk($nodes);

        return trim(preg_replace('/\s+/', ' ', implode(' ', $parts)) ?? '');
    }
}
