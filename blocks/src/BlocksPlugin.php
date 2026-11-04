<?php

declare(strict_types=1);

namespace Cms\Blocks;

use Cms\Abilities\Ability;
use Cms\Abilities\AbilityContext;
use Cms\Abilities\AbilityException;
use Cms\Packages\Plugin;
use Cms\Support\Files;

final class BlocksPlugin extends Plugin
{
    public function register(): void
    {
        foreach ([BlockRegistry::class, StyleCompiler::class, BlockRenderer::class, Patterns::class, Templates::class, DataSources::class, Document::class, MarkdownCodec::class] as $service) {
            $this->app->singleton($service);
        }

        $this->cms()->whenAvailable(\Cms\Content\Fields\FieldTypeRegistry::class, static function ($types) {
            $types->register(new BlocksField);
            $types->register(new PropertiesField);
        });
    }

    public function boot(): void
    {
        $registry = $this->app->make(BlockRegistry::class);

        foreach (CoreBlocks::all() as $block) {
            $registry->register([...$block, 'package' => $this->manifest->name]);
        }

        foreach (CoreBlocks::categories() as $category) {
            $registry->category($category['name'], $category['label']);
        }

        $permissions = $this->cms()->permissions();
        $permissions->register('blocks.html', 'Add Custom HTML Blocks', 'Content', trusted: true);

        $this->cms()->admin()->widget('blocks-design', ['label' => 'Components and Templates', 'description' => 'Components and templates changed last.', 'icon' => 'palette', 'column' => 'main', 'order' => 50, 'permission' => 'entries.components.view']);
        $this->collections();
        $this->guardHtmlBlocks();
        $this->cms()->hooks()->addFilter('blocks.data-sources', fn (array $sources) => [...$sources, ...$this->app->make(CmsSources::class)->sources()]);
        $this->frontend();
        $this->abilities();
        $this->agentContext();

        $this->adminModule(import: '@letterncms/blocks');
    }

    /**
     * Templates and components are collections, so they get everything entries
     * have — drafts, publishing, history, comments, the shared block workspace, abilities.
     * A site can redefine them in content/collections/<handle>.yaml.
     */
    private function collections(): void
    {
        $this->cms()->hooks()->addFilter('collections.defaults', static fn (array $defaults) => $defaults + [
            'body' => 'content',
            'fields' => [['handle' => 'content', 'type' => 'blocks', 'label' => 'Content']],
        ]);

        $this->cms()->hooks()->addFilter('collections', static function (array $collections) {
            $collections[Templates::COLLECTION] ??= \Cms\Content\Collections\Collection::fromArray(Templates::COLLECTION, [
                'title' => 'Templates', 'singular' => 'Template', 'icon' => 'layout', 'driver' => 'files', 'structure' => 'list',
                'section' => 'design', 'nav_order' => 20, 'system' => true, 'body' => 'blocks', 'sort' => 'title',
                'description' => 'The frame around each page: header, footer and layout. Default frames every page without one of its own; Not found shows when a page is missing.',
                'fields' => [
                    ['handle' => 'blocks', 'type' => 'blocks', 'label' => 'Template'],
                    ['handle' => 'description', 'type' => 'textarea', 'label' => 'Description', 'section' => 'sidebar'],
                    ['handle' => 'collections', 'type' => 'select', 'label' => 'Offered For', 'multiple' => true, 'dynamic' => 'collections', 'section' => 'sidebar', 'instructions' => 'Leave empty to offer it everywhere.'],
                ],
            ]);
            $collections[Patterns::COLLECTION] ??= \Cms\Content\Collections\Collection::fromArray(Patterns::COLLECTION, [
                'title' => 'Components', 'singular' => 'Component', 'icon' => 'symbol', 'driver' => 'files', 'structure' => 'list',
                'section' => 'design', 'nav_order' => 30, 'system' => true, 'body' => 'blocks', 'sort' => 'title',
                'description' => 'Reusable designs. A synced one (a header, a card) changes everywhere it is used; a section is copied when you insert it.',
                'fields' => [
                    ['handle' => 'blocks', 'type' => 'blocks', 'label' => 'Component'],
                    // With the handle, in the details' first group: one group of rows, labeled alike, then Info.
                    ['handle' => 'synced', 'type' => 'toggle', 'label' => 'Synced', 'default' => true, 'section' => 'sidebar'],
                    ['handle' => 'category', 'type' => 'text', 'label' => 'Category', 'section' => 'sidebar'],
                    ['handle' => 'description', 'type' => 'textarea', 'label' => 'Description', 'section' => 'sidebar'],
                    ['handle' => 'props', 'type' => 'properties', 'label' => 'Properties', 'section' => 'sidebar', 'placeholder' => 'No properties yet.', 'add' => 'Add Property'],
                ],
            ]);

            return $collections;
        });

        $this->cms()->hooks()->addFilter('fields.options.collections', fn (array $options) => array_replace(
            $options,
            array_map(static fn ($c) => $c->title, array_filter($this->cms()->collections()->all(), static fn ($c) => ! $c->system)),
        ));

        foreach (['entry.saved', 'entry.published', 'entry.unpublished', 'entry.deleted'] as $event) {
            $this->cms()->hooks()->on($event, function ($entry) {
                if (in_array($entry->collection ?? null, [Templates::COLLECTION, Patterns::COLLECTION], true)) {
                    $this->app->make(Templates::class)->flush();
                    $this->app->make(Patterns::class)->flush();
                }
            });
        }
    }

    /** Structural CSS for blocks, and the runtime for pages that use scripts, animation, search or code. */
    private function frontend(): void
    {
        $hooks = $this->cms()->hooks();
        $renderer = fn () => $this->app->make(BlockRenderer::class);

        $hooks->on('render.begin', fn () => $renderer()->begin());

        // Built pages name the runtime by its hashed file; a new one rebuilds them all.
        $hooks->addFilter('site.build.signature', fn (array $parts) => [...$parts, (string) $this->runtime()['entry'], (string) @filemtime($this->path('resources/css/blocks.css'))]);

        $hooks->addFilter('site.head', function (array $head) use ($renderer) {
            $css = (string) file_get_contents($this->path('resources/css/blocks.css'));
            $hash = substr(hash('xxh128', $css), 0, 10);
            $file = public_path("_cms/blocks/blocks-{$hash}.css");

            if (! is_file($file)) {
                foreach (glob(public_path('_cms/blocks/blocks-*.css')) ?: [] as $old) {
                    @unlink($old);
                }

                Files::put($file, $css);
            }

            // After the design stylesheet, before block styles collected while rendering.
            $head[] = '<link rel="stylesheet" href="/_cms/blocks/blocks-'.$hash.'.css">';

            $features = $renderer()->features;

            if (! empty($features['account']) && ! $renderer()->editor) {
                $head[] = \Cms\Users\AccountInBrowser::script();
            }

            // Editor placeholders stay available across rendered document updates.
            if ($renderer()->editor) {
                $head[] = '<style data-cms-editor="placeholders">'.file_get_contents($this->path('resources/css/editor.css')).'</style>';
            }

            return $head;
        }, 5);

        $hooks->addFilter('site.foot', function (array $foot, $context = null) use ($renderer, $hooks) {
            $features = array_keys(array_filter($renderer()->features));

            // Something that moves forever needs a way to stop it (WCAG 2.2.2).
            if (in_array('forever', $features, true) && ! in_array('motion-toggle', $features, true) && ! $renderer()->editor) {
                $hooks->dispatch('render.warning', __('This page has an animation that runs forever and no Motion Toggle block to pause it.'));
            }

            $features = array_values(array_diff($features, ['forever', 'motion-toggle', 'account']));

            if ($features === [] || $renderer()->editor) {
                return $foot;
            }

            $runtime = $this->runtime();

            if ($runtime['entry'] !== null) {
                $foot[] = '<script type="module" src="'.e($runtime['entry']).'" data-features="'.e(implode(' ', $features)).'"></script>';
            }

            return $foot;
        });

        // The canvas speaks the admin's language (placeholders, empty states): the one the
        // editor asks for (`X-Cms-Locale` or `?lang=`), else the user's.
        $restore = null;
        $hooks->on('preview.editor', function (bool $on) use ($renderer, &$restore) {
            $renderer()->editor = $on;
            $translations = $this->app->make(\Cms\Admin\Translations::class);

            if ($on) {
                $request = request();
                $asked = (string) ($request->header('X-Cms-Locale') ?? $request->query('lang', ''));
                $restore ??= $translations->current();
                $translations->apply(isset($translations->languages()[$asked]) ? $asked : $translations->locale($request->user(), $request));
            } elseif ($restore !== null) {
                $translations->apply($restore);
                $restore = null;
            }
        });
    }

    /**
     * The site runtime from dist/runtime (Vite manifest): its entry script.
     *
     * @return array{entry: ?string}
     */
    public function runtime(): array
    {
        static $cache = null;

        if ($cache !== null) {
            return $cache;
        }

        $manifest = $this->path('dist/runtime/manifest.json');
        $data = is_file($manifest) ? json_decode((string) file_get_contents($manifest), true) : null;

        if (! is_array($data)) {
            return $cache = ['entry' => null];
        }

        $entry = null;

        foreach ($data as $chunk) {
            if (! empty($chunk['isEntry'])) {
                $entry = $this->asset('runtime/'.$chunk['file']);
            }
        }

        return $cache = ['entry' => $entry];
    }

    /** Only people with blocks.html may add or change Custom HTML blocks. */
    private function guardHtmlBlocks(): void
    {
        $this->cms()->hooks()->addFilter('ability.input', function (array $input, Ability $ability, AbilityContext $ctx) {
            if ($ctx->system || ! in_array($ability->name, ['entries/create', 'entries/update', 'patterns/save', 'globals/update'], true)) {
                return $input;
            }

            if ($ctx->user?->hasPermission('blocks.html')) {
                return $input;
            }

            $incoming = $this->htmlBlocks($input);

            if ($incoming === []) {
                return $input;
            }

            $existing = match ($ability->name) {
                'entries/update' => ($entry = $this->cms()->entries()->find((string) ($input['id'] ?? ''))) ? $this->htmlBlocks($entry->working()) : [],
                'patterns/save' => $this->htmlBlocks($this->app->make(Patterns::class)->all(true)[(string) ($input['handle'] ?? '')] ?? []),
                default => [],
            };

            if (array_diff($incoming, $existing) !== []) {
                throw AbilityException::forbidden('You are not allowed to add or change Custom HTML blocks.');
            }

            return $input;
        });
    }

    /** @return list<string> */
    private function htmlBlocks(array $data): array
    {
        $found = [];

        $walk = static function ($value) use (&$walk, &$found): void {
            if (! is_array($value)) {
                return;
            }

            if (($value['type'] ?? null) === 'html' && isset($value['props']['html'])) {
                $found[] = hash('sha256', (string) $value['props']['html']);
            }

            foreach ($value as $child) {
                $walk($child);
            }
        };
        $walk($data);

        return $found;
    }

    /** The block catalog in the user's language: titles, descriptions and prop fields (agents read it in English). */
    private function translatedBlocks(): array
    {
        $t = $this->app->make(\Cms\Admin\Translations::class);

        return array_map(static fn (array $block) => [
            ...$block,
            'title' => $t->label($block['title']),
            'description' => $t->label($block['description'] ?? ''),
            'fields' => $t->fields($block['fields'] ?? []),
        ], $this->app->make(BlockRegistry::class)->catalog());
    }

    /** Block categories in the inserter's order and the user's language. */
    private function translatedCategories(): array
    {
        $t = $this->app->make(\Cms\Admin\Translations::class);

        return array_map(static fn (array $category) => [...$category, 'label' => $t->label($category['label'])], $this->app->make(BlockRegistry::class)->categories());
    }

    /** Data sources for Repeat in the user's language: names, descriptions, settings and item labels. */
    private function translatedSources(): array
    {
        $t = $this->app->make(\Cms\Admin\Translations::class);

        return array_map(static fn (array $source) => [
            ...$source,
            'label' => $t->label($source['label']),
            'description' => $t->label($source['description']),
            'fields' => $t->fields($source['fields']),
            'item' => (object) array_map($t->label(...), (array) $source['item']),
        ], $this->app->make(DataSources::class)->catalog());
    }

    private function abilities(): void
    {
        $a = $this->cms()->abilities();
        $package = $this->manifest->name;
        $patterns = fn () => $this->app->make(Patterns::class);
        $templates = fn () => $this->app->make(Templates::class);
        $document = fn () => $this->app->make(Document::class);
        $handle = ['type' => 'string', 'pattern' => '^[a-z0-9][a-z0-9_-]{0,63}$'];

        $a->register('blocks/types', [
            'label' => 'Block Catalog',
            'description' => 'Every block type (props, children, examples), every style property and variant, data sources for Repeat, and the schema of scripts (logic) and animations (motion). Compose pages from these.',
            'permission' => 'admin.access',
            'annotations' => ['readOnly' => true, 'trustedOutput' => true],
            'package' => $package,
            'handler' => fn () => [
                'blocks' => $this->translatedBlocks(),
                'categories' => $this->translatedCategories(),
                'styles' => array_map(static fn ($p) => ['property' => $p[0], 'group' => $p[1]], StyleCompiler::PROPERTIES),
                'aliases' => StyleCompiler::ALIASES,
                'states' => [...array_keys(StyleCompiler::STATES), 'is-<name>', 'in-<name>'],
                'breakpoints' => $this->app->make(StyleCompiler::class)->breakpoints(),
                'sources' => $this->translatedSources(),
                'motion' => ['triggers' => Motion::TRIGGERS, 'properties' => Motion::PROPERTIES, 'eases' => array_keys(Motion::EASES), 'screens' => Motion::SCREENS],
                'logic' => ['events' => Logic::EVENTS, 'mods' => Logic::MODS, 'actions' => Logic::ACTIONS, 'transitions' => Logic::TRANSITIONS, 'merges' => Logic::MERGES],
            ],
        ]);

        $a->register('blocks/icons', [
            'label' => 'Find Icons',
            'description' => 'Search the Phosphor icon set for the Icon and Button blocks. Append -bold, -fill or -light to a name for other weights.',
            'permission' => 'admin.access',
            'annotations' => ['readOnly' => true, 'trustedOutput' => true],
            'package' => $package,
            'input' => ['type' => 'object', 'properties' => ['query' => ['type' => 'string', 'maxLength' => 60], 'limit' => ['type' => 'integer', 'minimum' => 1, 'maximum' => 400, 'default' => 120]]],
            'handler' => static fn (array $in) => Icons::search((string) ($in['query'] ?? ''), (int) ($in['limit'] ?? 120)),
        ]);

        $a->register('blocks/from-markdown', [
            'label' => 'Markdown to Blocks',
            'description' => 'Convert Block Markdown (Markdown plus ::: directives for other blocks) into a block tree.',
            'permission' => 'admin.access',
            'annotations' => ['readOnly' => true, 'trustedOutput' => true],
            'package' => $package,
            'input' => ['type' => 'object', 'properties' => ['markdown' => ['type' => 'string', 'maxLength' => 500000]], 'required' => ['markdown']],
            'handler' => fn (array $in) => $document()->normalize($this->app->make(MarkdownCodec::class)->decode($in['markdown'])),
        ]);

        $a->register('blocks/to-markdown', [
            'label' => 'Blocks to Markdown',
            'description' => 'Convert a block tree to Block Markdown.',
            'permission' => 'admin.access',
            'annotations' => ['readOnly' => true, 'trustedOutput' => true],
            'package' => $package,
            'input' => ['type' => 'object', 'properties' => ['blocks' => ['type' => 'array']], 'required' => ['blocks']],
            'handler' => fn (array $in) => ['markdown' => $this->app->make(MarkdownCodec::class)->encode($in['blocks'])],
        ]);

        // ── Components and templates (read helpers; edit them with the entries abilities) ──
        $a->register('patterns/list', [
            'label' => 'List Components and Sections',
            'description' => 'Entries of the components collection (plus plugin patterns). Synced ones are components: instances update everywhere and take property values. Others are sections copied on insert. Create and change them with entries/create and entries/update in the "components" collection.',
            'permission' => 'admin.access',
            'annotations' => ['readOnly' => true],
            'package' => $package,
            'input' => ['type' => 'object', 'properties' => ['working' => ['type' => 'boolean', 'default' => false]]],
            'handler' => fn (array $in) => array_values($patterns()->all((bool) ($in['working'] ?? false))),
        ]);

        $a->register('patterns/save', [
            'label' => 'Save as Component or Section',
            'description' => 'Create or replace an entry of the components collection by handle (slug). synced: true makes it a component. Saved as a draft of an existing one unless publish is true.',
            'permission' => fn (?\Cms\Users\User $user) => (bool) $user?->hasPermission('entries.'.Patterns::COLLECTION.'.create'),
            'package' => $package,
            'input' => ['type' => 'object', 'properties' => [
                'handle' => $handle,
                'title' => ['type' => 'string', 'minLength' => 1, 'maxLength' => 120],
                'description' => ['type' => 'string', 'maxLength' => 500],
                'category' => ['type' => 'string', 'maxLength' => 60],
                'synced' => ['type' => 'boolean', 'default' => false],
                'props' => ['type' => 'array'],
                'blocks' => ['type' => 'array'],
                'publish' => ['type' => 'boolean', 'default' => true],
            ], 'required' => ['handle', 'title', 'blocks']],
            'handler' => function (array $in, \Cms\Abilities\AbilityContext $ctx) use ($patterns) {
                $data = array_filter(['blocks' => $in['blocks'], 'synced' => (bool) ($in['synced'] ?? false), 'category' => $in['category'] ?? null, 'description' => $in['description'] ?? null, 'props' => $in['props'] ?? null], static fn ($v) => $v !== null);
                $existing = $patterns()->all(true)[$in['handle']]['id'] ?? null;
                $content = $this->cms()->content();
                $entry = $existing
                    ? $content->update($existing, ['title' => $in['title'], 'data' => $data], $ctx->user, (bool) ($in['publish'] ?? true))
                    : $content->create(Patterns::COLLECTION, ['title' => $in['title'], 'slug' => $in['handle'], 'data' => $data, 'status' => ($in['publish'] ?? true) ? 'published' : 'draft'], $ctx->user);
                $patterns()->flush();

                return ['id' => $entry->id, ...($patterns()->find($in['handle'], true) ?? [])];
            },
        ]);

        $a->register('templates/list', [
            'label' => 'List Templates',
            'description' => 'Entries of the templates collection: block documents that frame pages (header, Page content, footer…). `default` frames anything without its own, `404` renders missing pages. Edit them with entries/update; publishing rebuilds the site.',
            'permission' => 'admin.access',
            'annotations' => ['readOnly' => true],
            'package' => $package,
            'input' => ['type' => 'object', 'properties' => ['working' => ['type' => 'boolean', 'default' => false]]],
            'handler' => fn (array $in) => array_values($templates()->all((bool) ($in['working'] ?? false))),
        ]);
    }

    private function agentContext(): void
    {
        $this->cms()->context()->register('cms://blocks', 'Blocks guide', 'How to compose pages, templates and components: node model, styles, bindings, Repeat, scripts and animations, with worked examples.', fn () => [
            'rules' => [
                'Everything visitors see is blocks: entries (their blocks field), templates (entries of the templates collection — they frame pages and contain one `outlet` block) and components (entries of the components collection — header, footer, cards). There are no theme files to edit.',
                'A node: {type, props, style?, attrs?, bind?, logic?, motion?, children?, label?}. Omit ids — they are generated. Name important layers with `label`.',
                'Compose layouts from `box` (any HTML tag; flex or grid via style) and text primitives (`heading`, `paragraph` with a tag, `link`, `button`, `icon`, `image`). Use `section` for page-width bands in content.',
                'style keys are CSS properties in camelCase (gridTemplateColumns, backdropFilter…). Prefer design tokens: token:space.8, token:color.primary, token:color.primary/20 (alpha), token:text.xl, token:radius.lg — read cms://site/design. Plain CSS values work too (clamp(), %, vw, gradients); media:<id> becomes url().',
                'Variants: base (all screens), tablet, mobile (desktop-first) or sm/md/lg/xl (min-width); states hover, focus-visible, active, before, after, first, last, group-hover (inside an element with logic.behaviors.group), dark; custom states is-<name> (set by logic.states, behaviors or bind state:<name>) and in-<name> (an ancestor has is-<name>). Combine: "mobile:hover".',
                'Text props are inline HTML: <strong>, <em>, <a href>, <code>, <mark>, <br>. Style <em>/<strong> inside headings through element styles in the design system.',
                'bind connects props to data: {"text": "entry.title"}, {"url": "item.url"}, {"image": "entry.cover"}, {"visible": "entry.excerpt"}, {"state:active": "item.active"}, {"attr:aria-label": "item.label"}, {"style:backgroundImage": "item.image"}. Filters: |date:M j, Y |excerpt:120 |upper |default:Text |count |not.',
                'Scope: site.*, entry.* (title, url, date, any field, parent, prev, next, children), globals.<set>.<field>, page.uri, item/index/first/last inside a Repeat, props.* inside a component, pagination.* after a paginated Repeat.',
                'Repeat renders its children once per item of a data source (see blocks/types sources): entries {collection, sort, limit, hasTag, parent, paginate}, menu {menu}, children, headings, breadcrumbs, languages, list {path: "item.children"}. Nest Repeats for sub-menus.',
                'Components: entries of the components collection with synced: true and props (fields). Instances: {"type":"pattern","props":{"ref":"card","values":{"title":"Fast"}}}; inside the component bind to props.title. Bind instance values to data with bind {"values.title": "item.title"}.',
                'logic compiles to Alpine.js (CSP build): data (state), on (events → actions: set, toggle, add, if, wait, play, scroll, copy, go, theme, focus, dispatch, fetch, lock, run), show + transition, states, text, attrs, model, target/merge (load links and forms into blocks), behaviors (scroll: is-scrolled/is-hidden on scroll, spy: scrollspy for in-page links, group: hover group, marquee, carousel: step through the first child with the buttons after it).',
                'motion compiles to CSS (load, scroll, loop) or Web Animations (appear, hover, press, event): [{trigger: {type: appear|scroll|hover|press|load|loop|event, start, end, once, node}, on: [desktop|tablet|mobile], steps: [{target: self|children|words|#<node id>, mask, from, to, duration, delay, ease, stagger, at}], repeat, yoyo}]. start and end read "<top|center|bottom> <top|center|bottom|N%>". load caps its delay at 0.2s and its stagger at 0.3s so it cannot hold back the page. repeat -1 needs a motion-toggle block on the page. Properties: opacity, x, y, xPercent, yPercent, scale, rotate, rotateX/Y, skewX/Y, perspective, blur, clipPath, colors, borderRadius. Use event triggers with a logic play action for menus and modals.',
                'Templates and components are entries of the "templates" and "components" collections (body field: blocks). entries/update saves a draft; entries/publish (or publish: true) makes it live and rebuilds the pages that use it.',
                'Prefer existing components and sections (patterns/list) to keep sites consistent. You can send entry data for a blocks field as Block Markdown (a string).',
            ],
            'blocks' => $this->app->make(BlockRegistry::class)->catalog(),
            'examples' => [
                'navigation from a menu' => ['type' => 'box', 'label' => 'Nav', 'props' => ['tag' => 'nav'], 'attrs' => ['aria-label' => 'Main'], 'style' => ['base' => ['display' => 'flex', 'gap' => 'token:space.6']], 'children' => [
                    ['type' => 'repeat', 'props' => ['source' => 'menu', 'menu' => 'main', 'tag' => 'ul'], 'style' => ['base' => ['display' => 'flex', 'gap' => 'token:space.6', 'listStyle' => 'none', 'margin' => '0', 'padding' => '0']], 'children' => [
                        ['type' => 'box', 'props' => ['tag' => 'li'], 'children' => [
                            ['type' => 'link', 'bind' => ['label' => 'item.label', 'url' => 'item.url', 'state:active' => 'item.active'], 'style' => ['base' => ['color' => 'token:color.muted', 'textDecoration' => 'none'], 'hover' => ['color' => 'token:color.foreground'], 'is-active' => ['color' => 'token:color.foreground']]],
                        ]],
                    ]],
                ]],
                'blog list' => ['type' => 'repeat', 'props' => ['source' => 'entries', 'collection' => 'posts', 'sort' => '-published_at', 'limit' => 6], 'style' => ['base' => ['display' => 'grid', 'gridTemplateColumns' => 'repeat(3,1fr)', 'gap' => 'token:space.8'], 'mobile' => ['gridTemplateColumns' => '1fr']], 'children' => [
                    ['type' => 'box', 'props' => ['tag' => 'article'], 'bind' => ['link' => 'item.url'], 'children' => [
                        ['type' => 'image', 'bind' => ['image' => 'item.cover', 'visible' => 'item.cover'], 'props' => ['aspect' => '3/2']],
                        ['type' => 'paragraph', 'props' => ['tag' => 'time'], 'bind' => ['text' => 'item.date|date:M j, Y']],
                        ['type' => 'heading', 'props' => ['level' => '3'], 'bind' => ['text' => 'item.title']],
                    ]],
                ]],
                'mobile menu toggle' => ['type' => 'box', 'logic' => ['data' => ['open' => false], 'states' => ['open' => 'open'], 'on' => [['event' => 'keydown', 'key' => 'escape', 'mods' => ['window'], 'actions' => [['do' => 'set', 'var' => 'open', 'value' => 'false']]]]], 'children' => [
                    ['type' => 'box', 'props' => ['tag' => 'button'], 'attrs' => ['aria-label' => 'Menu'], 'logic' => ['attrs' => ['aria-expanded' => 'open'], 'on' => [['event' => 'click', 'actions' => [['do' => 'toggle', 'var' => 'open']]]]], 'children' => [['type' => 'icon', 'props' => ['icon' => 'list']]]],
                    ['type' => 'box', 'logic' => ['show' => 'open', 'transition' => 'fade'], 'children' => []],
                ]],
                'animated hero heading' => ['type' => 'heading', 'props' => ['text' => 'Design that moves', 'level' => '1'], 'motion' => [['trigger' => ['type' => 'load'], 'steps' => [['target' => 'words', 'from' => ['opacity' => 0, 'y' => 40], 'duration' => 0.9, 'ease' => 'power3.out', 'stagger' => 0.06]]]]],
                'parallax image' => ['type' => 'image', 'motion' => [['trigger' => ['type' => 'scroll', 'start' => 'top bottom', 'end' => 'bottom top'], 'steps' => [['from' => ['yPercent' => -10], 'to' => ['yPercent' => 10], 'ease' => 'none']]]]],
                'template' => ['title' => 'Post', 'collections' => ['posts'], 'blocks' => [['type' => 'pattern', 'props' => ['ref' => 'header']], ['type' => 'box', 'props' => ['tag' => 'main'], 'children' => [['type' => 'heading', 'props' => ['level' => '1'], 'bind' => ['text' => 'entry.title']], ['type' => 'outlet']]], ['type' => 'pattern', 'props' => ['ref' => 'footer']]]],
            ],
            'markdown' => "## Why Cms\n\nFast pages by default.\n\n::: buttons\n:::: button {\"props\":{\"label\":\"Start\",\"url\":\"/start\"}}\n::::\n:::",
        ]);
    }
}
