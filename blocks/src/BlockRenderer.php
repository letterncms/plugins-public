<?php

declare(strict_types=1);

namespace Cms\Blocks;

use Closure;
use Illuminate\Contracts\Support\Htmlable;
use Illuminate\Contracts\View\Factory;
use Illuminate\Support\HtmlString;
use Cms\Extend\Hooks;
use Cms\Support\Html\Sanitizer;
use Throwable;

/**
 * Renders block documents to HTML.
 *
 * For each node: bindings are resolved against the current Scope, styles become
 * atomic classes, `logic` becomes Alpine attributes and `motion` CSS or, for what
 * waits on the visitor, a description for the runtime. Blocks render through their `render` closure (core blocks) or a
 * view (plugins that prefer Blade). Render closures receive:
 *   block (props, bindings applied) · node · type · content (children HTML) ·
 *   attrs (class, id, data and script attributes for the root element — always print it) ·
 *   editor · scope · renderer · placeholders
 *
 * Editor mode (the canvas) never shows an empty element as nothing: empty text
 * props get `placeholders` (prop => muted text, see Elements::placeholder), empty
 * media a neutral graphic, childless containers a drop area, and a Repeat
 * without data three sample items. None of it reaches the published site.
 */
final class BlockRenderer
{
    private int $depth = 0;

    /** @var array<string, true> patterns being rendered (recursion guard) */
    private array $rendering = [];

    public bool $editor = false;

    /** The one source document whose DOM nodes may be inspected/edited. */
    private ?string $editorDocument = null;

    /** @var array<string, array{document: string, node: string, prop: string, type: string, value: string}> */
    private array $instanceProperties = [];

    /** Exact component property bindings may forward to an editable source instance. */
    public function instanceProperty(string $binding): ?array
    {
        return preg_match('/^props\.([a-z][a-z0-9_]{0,63})$/D', $binding, $match)
            ? ($this->instanceProperties[$match[1]] ?? null)
            : null;
    }

    private function withInstanceProperties(array $properties, callable $render): mixed
    {
        $previous = $this->instanceProperties;
        $this->instanceProperties = $properties;
        try {
            return $render();
        } finally {
            $this->instanceProperties = $previous;
        }
    }

    private ?string $outletEditorDocument = null;

    private bool $outletRendered = false;

    public function withEditorDocument(?string $document, callable $render): mixed
    {
        $previous = $this->editorDocument;
        $this->editorDocument = $document;
        try {
            return $render();
        } finally {
            $this->editorDocument = $previous;
        }
    }

    /** An outlet is a separate source document from the surrounding template. */
    public function withOutletDocument(?string $document, callable $render): mixed
    {
        $previous = [$this->outletEditorDocument, $this->outletRendered];
        $this->outletEditorDocument = $document;
        $this->outletRendered = false;
        try {
            return $render();
        } finally {
            [$this->outletEditorDocument, $this->outletRendered] = $previous;
        }
    }

    public function outletEditorRoot(): ?string
    {
        return $this->editor && ! $this->inOutlet && ! $this->outletRendered ? $this->outletEditorDocument : null;
    }

    /** Basic insertion prototypes use this renderer and contribute their real CSS. */
    public function editorPrototypes(string $document): string
    {
        $html = '';
        foreach (['paragraph', 'heading', 'list', 'quote', 'callout', 'code'] as $name) {
            if (! $this->registry->get($name)) continue;
            $nodes = app(Document::class)->normalize([['id' => '__prototype__', 'type' => $name, 'props' => $name === 'list' ? ['items' => ['']] : []]]);
            $content = $this->withEditorDocument($document, fn () => $this->render($nodes));
            $html .= '<template data-cms-prototype="'.e($name).'">'.$content.'</template>';
        }

        return $html;
    }

    /** Render unpublished changes of components (previews and the canvas). */
    public bool $working = false;

    /** The data documents are rendered with; site rendering sets entry, site and globals. */
    public ?Scope $scope = null;

    /** Renders the Page content block (set by the site renderer for the page being built). */
    public ?Closure $outlet = null;

    private bool $inOutlet = false;

    /** @var array<string, bool> runtime features used on this page (logic, motion, ajax, highlight, search…) */
    public array $features = [];

    /** The panel Tabs is rendering: its ids and whether it shows (see Interactive::tabs). */
    public ?array $tabPanel = null;

    /** @var array<string, array<string, array{label: string, count: int}>> the options around the List Filter being rendered */
    public array $filterFacets = [];

    private int $ids = 0;

    /** An id unique on this page and the same on every build, for a block used more than once. */
    public function nextId(string $prefix): string
    {
        return 'cms-'.$prefix.(++$this->ids);
    }

    /**
     * What a List Filter can offer: the values of the Repeat's Filter By fields, with counts.
     *
     * @return array<string, array<string, array{label: string, count: int}>>
     */
    public function facets(array $repeat): array
    {
        $props = (array) ($repeat['props'] ?? []);
        $scope = $this->currentScope();
        $as = (string) ($props['as'] ?? 'item') ?: 'item';
        $out = [];

        foreach (array_slice($this->sources->resolve((string) ($props['source'] ?? 'list'), $props, $scope), 0, 1000) as $item) {
            $inner = $scope->with([$as => $item]);

            foreach (Interactive::paths($props['filter_by'] ?? '') as $path) {
                $key = Interactive::handle($path);

                foreach ($this->values($inner, $path) as $value) {
                    $slug = Interactive::slug($value);
                    $out[$key][$slug]['label'] = $value;
                    $out[$key][$slug]['count'] = ($out[$key][$slug]['count'] ?? 0) + 1;
                }
            }
        }

        foreach ($out as &$options) {
            uasort($options, static fn (array $a, array $b) => strnatcasecmp($a['label'], $b['label']));
        }

        return $out;
    }

    /** A field's values as text: a list gives several. */
    private function values(Scope $scope, string $path): array
    {
        $value = $scope->resolve($path);
        $list = is_array($value) ? $value : [$value];

        return array_values(array_filter(array_map(static fn ($v) => trim(Scope::text($v)), $list), static fn (string $v) => $v !== ''));
    }

    /** A filtered Repeat's item: what the runtime compares, around the item's own markup. */
    private function filterable(string $html, array $props, Scope $scope): string
    {
        $attrs = '';

        foreach (Interactive::paths($props['filter_by'] ?? '') as $path) {
            $attrs .= ' data-cms-f-'.Interactive::handle($path).'="'.e(implode(' ', array_map(Interactive::slug(...), $this->values($scope, $path)))).'"';
        }

        foreach (Interactive::paths($props['sort_by'] ?? '') as $path) {
            $attrs .= ' data-cms-s-'.Interactive::handle($path).'="'.e(implode(' ', $this->values($scope, $path))).'"';
        }

        $text = implode(' ', array_map(fn (string $path) => implode(' ', $this->values($scope, $path)), Interactive::paths($props['search_in'] ?? '')));
        $text = mb_substr(mb_strtolower((string) preg_replace('/\p{M}/u', '', (string) \Normalizer::normalize(strip_tags($text), \Normalizer::FORM_D))), 0, 200);
        $tag = in_array($props['tag'] ?? 'div', ['ul', 'ol'], true) ? 'li' : 'div';

        return "<{$tag} data-cms-fi{$attrs} data-cms-q=\"".e($text)."\" style=\"display:contents\">{$html}</{$tag}>";
    }

    /** @var array<string, true> scroll timelines of this page, which share one `timeline-scope` */
    private array $timelines = [];

    /** @var array<string, true> node ids other nodes point at (they get data-cms / id attributes) */
    private array $referenced = [];

    private ?Anchors $anchors = null;

    /** @var list<array<string, mixed>> script state of the elements being rendered (the canvas shows initial states) */
    private array $logicData = [];

    /** @var list<array<string, string>> property labels of the components being rendered (placeholders for props.*) */
    private array $propLabels = [];

    /** Editor: the depth of sample Repeat items shown faded (0 = none). */
    private int $ghost = 0;

    public function __construct(
        private readonly BlockRegistry $registry,
        private readonly StyleCompiler $styles,
        private readonly Factory $views,
        private readonly Hooks $hooks,
        private readonly Patterns $patterns,
        private readonly DataSources $sources,
    ) {}

    /** A fresh page: forget features and references collected for the previous one. */
    public function begin(): void
    {
        $this->features = [];
        $this->referenced = [];
        $this->timelines = [];
        $this->ids = 0;
    }

    /** @param list<array> $nodes A whole document (entry body, template, pattern). */
    public function render(array $nodes, ?Scope $scope = null): string
    {
        $outer = $this->depth === 0;
        $outer && $this->anchors = new Anchors;
        $previous = $this->scope;
        $this->scope = $scope ?? $this->scope ?? new Scope((array) $this->hooks->filter('blocks.scope', []));
        $this->collectReferences($nodes);

        try {
            return $this->children($nodes);
        } finally {
            $this->scope = $previous;
            $outer && $this->anchors = null;
        }
    }

    /** Render a list of sibling nodes in the current scope. */
    public function children(array $nodes, ?Scope $scope = null): string
    {
        $previous = $this->scope;
        $scope && $this->scope = $scope;
        $html = '';

        try {
            foreach ($nodes as $node) {
                if (is_array($node)) {
                    $html .= $this->node($node);
                }
            }
        } finally {
            $this->scope = $previous;
        }

        return $html;
    }

    public function currentScope(): Scope
    {
        return $this->scope ??= new Scope((array) $this->hooks->filter('blocks.scope', []));
    }

    public function node(array $node): string
    {
        $type = $this->registry->get((string) ($node['type'] ?? ''));

        if (! $type || $this->depth > 32 || ($node['hidden'] ?? false)) {
            return '';
        }

        $scope = $this->currentScope();
        $bind = (array) ($node['bind'] ?? []);
        $conditional = false;

        if ((isset($bind['visible']) && ! Scope::truthy($scope->resolve($bind['visible']))) || (isset($bind['hidden']) && Scope::truthy($scope->resolve($bind['hidden'])))) {
            if (! $this->editor) {
                return '';
            }

            $conditional = true;
        }

        $this->depth++;

        try {
            if ($type->name === 'pattern') {
                return $this->pattern($node);
            }

            $props = array_replace($type->blueprint()->defaults(), $type->defaults, array_filter((array) ($node['props'] ?? []), static fn ($v) => $v !== null));
            [$props, $extra] = $this->bindings($type, $props, $bind, $scope);
            $state = (array) ($node['logic']['data'] ?? []);
            $state !== [] && $this->logicData[] = $state;

            try {
                $children = $type->acceptsChildren() && ! $type->defer ? $this->children((array) ($node['children'] ?? [])) : '';
            } finally {
                $state !== [] && array_pop($this->logicData);
            }
            $props = $type->blueprint()->augment($props);

            if ($type->name === 'heading' && empty($node['anchor'])) {
                $props['anchor'] = ($this->anchors ??= new Anchors)->for(['props' => $props] + $node);
            }

            $style = (array) ($node['style'] ?? []);

            if ($type->derive) {
                $style = self::merge(($type->derive)($props), $style);
            }

            $motion = $this->editor ? null : $this->motion($node);

            $classes = trim(implode(' ', array_filter([
                'cms-'.str_replace('/', '-', $type->name),
                $this->styles->classes($style),
                $this->sanitizeClass((string) ($node['class'] ?? '')),
                $extra['class'],
                implode(' ', $motion['classes'] ?? []),
            ])));

            $data = [
                'block' => $props,
                'node' => $node,
                'type' => $type,
                'content' => new HtmlString($children),
                'attrs' => new HtmlString($this->attributes($node, $type, $classes, $extra, $conditional, $motion)),
                'classes' => $classes,
                'editor' => $this->editor,
                'editable' => $this->editor && $this->editorDocument !== null,
                'scope' => $scope,
                'renderer' => $this,
                'placeholders' => $this->editor ? $this->placeholders($type, $props, $bind) : [],
            ];

            $data = $this->hooks->filter('blocks.render.data', $data, $type);

            $html = $type->render ? (string) ($type->render)($data) : $this->view($type, $data);

            // Words animate from spans in the HTML; text a script writes has no words to find.
            return ($motion['split'] ?? null) !== null && empty($node['logic']['text']) ? TextSplit::html($html, $motion['split']['mask']) : $html;
        } catch (Throwable $e) {
            report($e);

            return $this->editor ? '<div '.$this->editorAttributes($node, $type->name).' style="padding:1rem;border:1px dashed #dc2626;color:#dc2626;font:13px system-ui">Block “'.e($type->title).'” failed: '.e($e->getMessage()).'</div>' : '';
        } finally {
            $this->depth--;
        }
    }

    /** Compile a node's animations and hand their CSS to the page. */
    private function motion(array $node): ?array
    {
        if (empty($node['motion'])) {
            return null;
        }

        $compiled = Motion::compile((array) $node['motion'], $this->styles->breakpoints());

        foreach ($compiled['rules'] as $key => $css) {
            $this->hooks->dispatch('render.style', $key, '@layer utilities{'.$css.'}', 5000);
        }

        foreach ($compiled['timelines'] as $name) {
            $this->timelines[$name] = true;
        }

        if ($this->timelines !== []) {
            $this->hooks->dispatch('render.style', 'cms-timeline-scope', ':root{timeline-scope:'.implode(',', array_keys($this->timelines)).'}', 4999);
        }

        $compiled['forever'] && $this->uses('forever');
        $compiled['runtime'] !== [] && $this->uses('motion');

        return $compiled;
    }

    /** Render the items of a data source with the node's children as the item template. */
    public function repeat(array $node, string $source, array $props): array
    {
        $scope = $this->currentScope();
        $items = $this->sources->resolve($source, $props, $scope);
        $limit = $this->editor ? 60 : 1000;
        $html = [];
        $children = (array) ($node['children'] ?? []);

        // The canvas shows a list without data as three sample items, so its design stays visible.
        $sample = $items === [] && $this->editor && $children !== [];
        $sample && $items = [[], [], []];
        $count = min(count($items), $limit);

        foreach (array_slice($items, 0, $limit) as $i => $item) {
            $ghost = $this->ghost;
            $sample && $i > 0 && $this->ghost = $this->depth + 1;

            try {
                $inner = $scope->with([
                    (string) ($props['as'] ?? 'item') ?: 'item' => $item,
                    'index' => $i + 1,
                    'first' => $i === 0,
                    'last' => $i === $count - 1,
                ]);
                $item = $this->withInstanceProperties([], fn () => $this->withEditorDocument(null, fn () => $this->children($children, $inner)));
                $html[] = ! $this->editor && (! empty($props['filter_by']) || ! empty($props['search_in']) || ! empty($props['sort_by'])) ? $this->filterable($item, $props, $inner) : $item;
            } finally {
                $this->ghost = $ghost;
            }
        }

        return $html;
    }

    /** Headings get # links (set while rendering Page content that asks for them). */
    public bool $headingLinks = false;

    /**
     * A document as plain blocks: components inlined with their property values,
     * Repeats expanded per item, bindings resolved — for Markdown twins, search
     * and agents, which read text rather than render it.
     *
     * @param  list<array>  $nodes
     * @return list<array>
     */
    public function materialize(array $nodes, ?Scope $scope = null, int $depth = 0): array
    {
        $scope ??= $this->currentScope();
        $out = [];

        foreach ($nodes as $node) {
            $type = is_array($node) ? $this->registry->get((string) ($node['type'] ?? '')) : null;

            if (! $type || ($node['hidden'] ?? false) || $depth > 24) {
                continue;
            }

            $bind = (array) ($node['bind'] ?? []);

            if ((isset($bind['visible']) && ! Scope::truthy($scope->resolve($bind['visible']))) || (isset($bind['hidden']) && Scope::truthy($scope->resolve($bind['hidden'])))) {
                continue;
            }

            if ($type->name === 'pattern') {
                $ref = (string) ($node['props']['ref'] ?? '');
                $pattern = $this->patterns->find($ref, $this->working);

                if ($pattern && ! isset($this->rendering[$ref])) {
                    $this->rendering[$ref] = true;
                    $values = $this->patterns->values($pattern, (array) ($node['props']['values'] ?? []), $scope, $bind);
                    array_push($out, ...$this->materialize($pattern['blocks'], $scope->with(['props' => $values]), $depth + 1));
                    unset($this->rendering[$ref]);
                }

                continue;
            }

            if ($type->name === 'repeat') {
                $props = (array) ($node['props'] ?? []);
                $items = array_slice($this->sources->resolve((string) ($props['source'] ?? 'list'), $props, $scope), 0, 200);

                foreach ($items as $i => $item) {
                    array_push($out, ...$this->materialize((array) ($node['children'] ?? []), $scope->with([(string) ($props['as'] ?? 'item') ?: 'item' => $item, 'index' => $i + 1, 'first' => $i === 0, 'last' => $i === count($items) - 1]), $depth + 1));
                }

                continue;
            }

            [$props] = $this->bindings($type, array_replace($type->defaults, (array) ($node['props'] ?? [])), $bind, $scope);

            // Links to pages by reference read as their URL.
            foreach ($type->blueprint()->fields() as $field) {
                if ($field->type === 'url' && is_string($props[$field->handle] ?? null) && str_starts_with($props[$field->handle], 'entry:')) {
                    $props[$field->handle] = Elements::url($props[$field->handle]) ?? '';
                }
            }
            $clean = ['type' => $type->name, 'props' => $props] + array_intersect_key($node, array_flip(['id', 'anchor', 'label']));

            if ($type->acceptsChildren()) {
                $clean['children'] = $this->materialize((array) ($node['children'] ?? []), $scope, $depth + 1);
            }

            $out[] = $clean;
        }

        return $out;
    }

    /** Render the Page content block. */
    public function outlet(bool $headingLinks = false): string
    {
        // Page content inside page content (a page that places the block itself) would never end.
        if (! $this->outlet || $this->inOutlet) {
            return '';
        }

        $previous = $this->headingLinks;
        $this->headingLinks = $headingLinks;
        $this->inOutlet = true;
        $document = $this->outletRendered ? null : $this->outletEditorDocument;
        $this->outletRendered = true;

        try {
            return (string) $this->withInstanceProperties([], fn () => $this->withEditorDocument($document, fn () => ($this->outlet)()));
        } finally {
            $this->headingLinks = $previous;
            $this->inOutlet = false;
        }
    }

    /** Mark a runtime feature as used on this page. */
    public function uses(string $feature): void
    {
        $this->features[$feature] = true;
    }

    /**
     * Apply bindings: props get values from the scope; visible/hidden were handled
     * already; state:, attr: and style: bindings become classes and attributes.
     *
     * @return array{0: array, 1: array{class: string, attrs: array<string, string>, style: list<string>}}
     */
    private function bindings(BlockType $type, array $props, array $bind, Scope $scope): array
    {
        $extra = ['class' => '', 'attrs' => [], 'style' => []];

        foreach ($bind as $key => $path) {
            if ($key === 'visible' || $key === 'hidden') {
                continue;
            }

            $raw = str_ends_with($path, '|raw');
            $value = $scope->resolve($path);

            if (str_starts_with($key, 'state:')) {
                Scope::truthy($value) && $extra['class'] .= ' is-'.substr($key, 6);
            } elseif (str_starts_with($key, 'attr:')) {
                $name = substr($key, 5);
                $text = is_object($value) && isset($value->url) && in_array($name, ['href', 'src'], true) ? (string) $value->url : ($value === true && str_starts_with($name, 'aria-') ? 'true' : Scope::text($value));
                // Data can hold anything; a javascript: link from a field must not reach the page.
                in_array($name, ['href', 'src'], true) && $text = Elements::url($text) ?? '';
                $text !== '' && $extra['attrs'][$name] = $text;
            } elseif (str_starts_with($key, 'style:')) {
                $property = substr($key, 6);
                $css = $this->styleValue($property, $value);
                $css !== null && $extra['style'][] = StyleCompiler::PROPERTIES[StyleCompiler::ALIASES[$property] ?? $property][0].':'.$css;
            } else {
                $field = $type->blueprint()->field($key);
                $props[$key] = $this->propValue($field?->type ?? 'text', $value, $raw, in_array($key, $type->inline, true));
            }
        }

        return [$props, $extra];
    }

    /**
     * Editor only: what each empty text prop shows instead — the label of the data
     * it is bound to (a component property, "Title" for entry.title), else the
     * block's own placeholder.
     *
     * @return array<string, string>
     */
    private function placeholders(BlockType $type, array $props, array $bind): array
    {
        $out = [];

        foreach ($type->placeholders() as $prop => $text) {
            if (self::blank($props[$prop] ?? null)) {
                $out[$prop] = (isset($bind[$prop]) ? $this->bindingLabel((string) $bind[$prop]) : null) ?? __($text);
            }
        }

        return $out;
    }

    /** A readable name for bound data: a component property's label, or the path's last part (entry.cover_image → Cover image). */
    private function bindingLabel(string $path): ?string
    {
        if (Scope::isTemplate($path)) {
            return null;
        }

        $path = explode('|', $path)[0];

        if (str_starts_with($path, 'props.') && ($label = end($this->propLabels)[substr($path, 6)] ?? null)) {
            return $label;
        }

        $last = substr((string) strrchr('.'.$path, '.'), 1);

        return $last === '' ? null : __(ucfirst(str_replace(['_', '-'], ' ', $last)));
    }

    /** Nothing to see: no text (tags and spaces aside) and no media. */
    public static function blank(mixed $value): bool
    {
        return match (true) {
            $value === null => true,
            is_array($value) => $value === [],
            is_string($value) => trim(str_replace(['&nbsp;', "\u{a0}"], ' ', strip_tags($value, ['img', 'svg', 'video', 'iframe']))) === '',
            default => false,
        };
    }

    private function propValue(string $fieldType, mixed $value, bool $raw, bool $inline): mixed
    {
        if ($fieldType === 'media') {
            $one = is_array($value) ? ($value[0] ?? null) : $value;

            return match (true) {
                is_object($one) && isset($one->id) => (string) $one->id,
                is_string($one) => $one,
                default => null,
            };
        }

        if (in_array($fieldType, ['url', 'link'], true)) {
            return is_object($value) && isset($value->url) ? (string) $value->url : Scope::text($value);
        }

        if (in_array($fieldType, ['toggle', 'number', 'select', 'json', 'repeater', 'tags', 'entries'], true)) {
            return is_object($value) && method_exists($value, 'jsonSerialize') ? $value->jsonSerialize() : $value;
        }

        if ($raw) {
            return $value instanceof Htmlable ? $value->toHtml() : Sanitizer::clean(Scope::text($value));
        }

        $text = Scope::text($value);

        return $inline || $fieldType === 'richtext' ? nl2br(e($text), false) : $text;
    }

    private function styleValue(string $property, mixed $value): ?string
    {
        if (in_array($property, ['backgroundImage', 'maskImage'], true)) {
            $one = is_array($value) ? ($value[0] ?? null) : $value;
            $url = is_object($one) && isset($one->url) ? (string) $one->url : (is_string($one) ? Media::url($one) : null);

            return $url ? 'url("'.str_replace(['"', '\\', "\n"], '', $url).'")' : null;
        }

        return $this->styles->value($property, is_scalar($value) ? $value : Scope::text($value));
    }

    private function attributes(array $node, BlockType $type, string $classes, array $extra, bool $conditional, ?array $compiled): string
    {
        $id = (string) ($node['id'] ?? '');
        $attrs = ['class' => $classes];

        if (! empty($node['anchor']) && preg_match('/^[a-z][a-z0-9-]*$/', (string) $node['anchor'])) {
            $attrs['id'] = (string) $node['anchor'];
        }

        foreach ((array) ($node['attrs'] ?? []) as $name => $value) {
            $attrs[$name] = (string) $value;
        }

        foreach ($extra['attrs'] as $name => $value) {
            $attrs[$name] = $value;
        }

        if ($extra['style'] !== []) {
            $attrs['style'] = implode(';', $extra['style']);
        }

        $logic = (array) ($node['logic'] ?? []);
        $referenced = isset($this->referenced[$id]);

        if ($logic !== []) {
            foreach (Logic::attributes($logic, $id) as $name => $value) {
                if ($this->editor && in_array($name, ['x-cloak', 'x-show'], true)) {
                    continue;
                }

                $attrs[$name] = $value;
            }

            if (Logic::active($logic)) {
                $this->uses('logic');
            }

            if (isset($logic['target']) || isset($logic['merge']) || str_contains((string) json_encode($logic['on'] ?? []), '"fetch"')) {
                $this->uses('ajax');
            }

            // The canvas shows elements as they first appear: hidden until their condition holds.
            if (isset($logic['show']) && $this->editor && Expression::evaluate($logic['show'], array_merge(...$this->logicData, ...[(array) ($logic['data'] ?? [])])) === false) {
                $conditional = true;
            }
        }

        if (($compiled['runtime'] ?? []) !== []) {
            $attrs['data-cms-motion'] = (string) json_encode($compiled['runtime'], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        }

        if ($id !== '' && ($logic !== [] || ($compiled['runtime'] ?? []) !== [] || $referenced)) {
            $attrs['data-cms'] = $id;
        }

        if ($referenced && ! isset($attrs['id'])) {
            $attrs['id'] = 'cms-'.$id;
        }

        foreach (['data-cms-node', 'data-cms-document', 'data-cms-editor-root', 'data-cms-type', 'data-cms-prop', 'data-cms-index', 'data-cms-entry-field', 'data-cms-instance-document', 'data-cms-instance-node', 'data-cms-instance-prop', 'data-cms-instance-type', 'data-cms-instance-format', 'data-cms-instance-value'] as $managed) {
            unset($attrs[$managed]);
        }
        if ($this->editor && $this->editorDocument !== null && $id !== '') {
            $attrs['data-cms-document'] = $this->editorDocument;
            $attrs['data-cms-node'] = $id;
            $attrs['data-cms-type'] = $type->name;
            // A container with nothing inside is a drop area ("Drop elements here").
            $type->acceptsChildren() && empty($node['children']) && $attrs['data-cms-empty'] = '';
        }

        // Also in a header or footer around the page being edited, which nobody can select there.
        $this->editor && $conditional && $attrs['data-cms-conditional'] = 'hidden';

        if ($this->editor && $this->ghost === $this->depth) {
            $attrs['data-cms-ghost'] = '';
        }

        $html = [];

        foreach ($attrs as $name => $value) {
            $html[] = $value === '' && $name !== 'class' && ! str_starts_with($name, 'aria-') && $name !== 'alt' ? $name : $name.'="'.e($value).'"';
        }

        return implode(' ', $html);
    }

    private function collectReferences(array $nodes): void
    {
        foreach ($nodes as $node) {
            if (! is_array($node)) {
                continue;
            }

            foreach ([...Logic::references((array) ($node['logic'] ?? [])), ...Motion::references((array) ($node['motion'] ?? []))] as $id) {
                $this->referenced[$id] = true;
            }

            if (! empty($node['children'])) {
                $this->collectReferences((array) $node['children']);
            }
        }
    }

    private function view(BlockType $type, array $data): string
    {
        if ($type->view === null) {
            return '';
        }

        if (str_starts_with($type->view, 'file:')) {
            return $this->views->file(substr($type->view, 5), $data)->render();
        }

        return $this->views->exists($type->view) ? $this->views->make($type->view, $data)->render() : '';
    }

    /** A component: its blocks rendered with its properties in scope as `props`. */
    private function pattern(array $node): string
    {
        $ref = (string) ($node['props']['ref'] ?? '');
        $pattern = $this->patterns->find($ref, $this->editor || $this->working);

        if (! $pattern || isset($this->rendering[$ref])) {
            return $this->editor ? '<div '.$this->editorAttributes($node, 'pattern').' class="cms-placeholder">'.e(__('Missing component “:name”', ['name' => $ref])).'</div>' : '';
        }

        $this->rendering[$ref] = true;
        $this->propLabels[] = array_column(array_filter((array) ($pattern['props'] ?? []), 'is_array'), 'label', 'handle');

        try {
            $values = $this->patterns->values($pattern, (array) ($node['props']['values'] ?? []), $this->currentScope(), (array) ($node['bind'] ?? []), $this->editor);
            $properties = $this->instanceEditingProperties($node, $pattern, $values);
            $html = $this->withInstanceProperties($properties, fn () => $this->withEditorDocument(null, fn () => $this->render($pattern['blocks'], $this->currentScope()->with(['props' => $values]))));
        } finally {
            unset($this->rendering[$ref]);
            array_pop($this->propLabels);
        }

        if (! $this->editor) {
            return $html;
        }

        $attrs = $this->editorAttributes($node, 'pattern');

        return $html === ''
            ? '<div '.$attrs.' data-cms-ref="'.e($ref).'" class="cms-placeholder">'.e(__('Empty component')).'</div>'
            : '<div '.$attrs.' data-cms-ref="'.e($ref).'" style="display:contents">'.$html.'</div>';
    }

    /** Map declared scalar properties to the source instance, never to shared definition nodes. */
    private function instanceEditingProperties(array $node, array $pattern, array $values): array
    {
        if (! $this->editor) return [];
        $properties = [];
        foreach ($pattern['props'] as $field) {
            $handle = $field['handle'] ?? null;
            $type = $field['type'] ?? null;
            if (! is_string($handle) || ! preg_match('/^[a-z][a-z0-9_]{0,63}$/D', $handle) || ! in_array($type, ['text', 'textarea', 'richtext'], true)) continue;
            if (isset($values[$handle]) && ! is_string($values[$handle])) continue;
            $binding = $node['bind']['values.'.$handle] ?? null;
            if ($this->editorDocument !== null && ! empty($node['id']) && $binding === null) {
                $properties[$handle] = ['document' => $this->editorDocument, 'node' => (string) $node['id'], 'prop' => $handle, 'type' => $type, 'value' => (string) ($values[$handle] ?? '')];
            } elseif (is_string($binding) && ($source = $this->instanceProperty($binding))) {
                // A nested component can forward an exact property without changing its meaning.
                $properties[$handle] = $source;
            }
        }

        return $properties;
    }

    private function editorAttributes(array $node, string $type): string
    {
        if (! $this->editor || $this->editorDocument === null || empty($node['id'])) return '';

        return 'data-cms-document="'.e($this->editorDocument).'" data-cms-node="'.e((string) $node['id']).'" data-cms-type="'.e($type).'"';
    }

    private function sanitizeClass(string $class): string
    {
        return implode(' ', array_filter(preg_split('/\s+/', $class) ?: [], static fn ($c) => preg_match('/^[a-zA-Z_][\w-]{0,40}$/', $c)));
    }

    /** Merge style maps variant by variant (later wins). */
    public static function merge(array $a, array $b): array
    {
        foreach ($b as $variant => $declarations) {
            $a[$variant] = [...(array) ($a[$variant] ?? []), ...(array) $declarations];
        }

        return $a;
    }
}
