<?php

declare(strict_types=1);

namespace Cms\Site;

use Illuminate\Support\Facades\Route;
use Cms\Abilities\AbilityException;
use Cms\Modules\Blocks\Anchors;
use Cms\Modules\Blocks\BlocksDocument;
use Cms\Modules\Blocks\Scope;
use Cms\Modules\Blocks\StyleCompiler;
use Cms\Modules\Blocks\Templates;
use Cms\Content\Collections\Collection;
use Cms\Content\Entries\Entry;
use Cms\Contracts\SiteBuilder as SiteBuilderContract;
use Cms\Packages\Plugin;
use Cms\Site\Build\SiteBuilder;
use Cms\Site\Design\Design;
use Cms\Site\Design\DesignCompiler;
use Cms\Site\Design\DesignJson;
use Cms\Site\Design\Fonts;
use Cms\Site\Http\PublicPage;
use Cms\Site\Http\SiteController;
use Cms\Site\Rendering\AugmentedEntry;
use Cms\Site\Rendering\EntryQueryProxy;
use Cms\Site\Rendering\HtmlMinifier;
use Cms\Site\Rendering\PageContext;
use Cms\Site\Rendering\Renderer;
use Cms\Site\Rendering\UrlGenerator;
use Cms\Users\User;

final class SitePlugin extends Plugin
{
    public function register(): void
    {
        require_once __DIR__.'/helpers.php';

        foreach ([Design::class, DesignCompiler::class, Fonts::class, Renderer::class, HtmlMinifier::class, UrlGenerator::class, SiteBuilder::class] as $service) {
            $this->app->singleton($service);
        }

        $this->app->singleton(SiteBuilderContract::class, SiteBuilder::class);
    }

    public function boot(): void
    {
        // An agent's way into the design system: `/adjust-design` in the assistant's chat, a prompt for MCP clients.
        $this->cms()->skills()->register('adjust-design', [
            'label' => 'Adjust the Design',
            'description' => 'Change the design system so the site feels a certain way.',
            'icon' => 'palette',
            'prompt' => 'Change the design system so the site feels the way asked here: {feel}. Read cms://site/design first and change design tokens rather than individual blocks. Say what you changed and why.',
            'arguments' => [['name' => 'feel', 'description' => 'How the site should feel', 'required' => true]],
            'permission' => 'settings.manage',
        ]);

        $this->renderHooks();
        $this->dataSources();
        $this->routes();
        $this->settingsSchema();
        $this->abilities();
        $this->buildTriggers();
        $this->adminUi();
        $this->agentContext();

        $this->cms()->hooks()->on('cms.installed', fn (User $user) => $this->app->make(Starter::class)->seed($user));

        if ($this->app->runningInConsole()) {
            $this->commands([Console\BuildCommand::class, Console\CheckLinksCommand::class, Console\StarterRefreshCommand::class]);
        }
    }

    /** Let other plugins (blocks, seo…) contribute to the page being rendered. */
    private function renderHooks(): void
    {
        $hooks = $this->cms()->hooks();
        $design = fn () => $this->app->make(Design::class);

        $hooks->on('render.style', static fn (string $key, string $css, int $order = 0) => PageContext::current()?->addStyle($key, $css, $order));
        $hooks->on('render.head', static fn (string $key, string $html) => PageContext::current()?->addHead($key, $html));
        $hooks->on('render.foot', static fn (string $key, string $html) => PageContext::current()?->addFoot($key, $html));
        $hooks->on('render.depend', static fn (string $key) => PageContext::depend($key));
        $hooks->addFilter('render.page', static fn (?array $page) => ($context = PageContext::current()) ? ['entry' => $context->entry, 'uri' => $context->uri, 'locale' => $context->locale()] : $page);
        // Mail wears the site's colours, from whichever tokens this design has.
        $hooks->addFilter('email.colors', static function (array $colors) use ($design) {
            $token = static fn (string $path, string $fallback) => is_string($value = $design()->json()->token($path)) && $value !== '' ? $value : $fallback;

            return [...$colors, 'background' => $token('color.background', $colors['background']), 'card' => $token('color.raised', $colors['card']), 'text' => $token('color.ink', $token('color.foreground', $colors['text'])), 'muted' => $token('color.muted', $colors['muted']), 'line' => $token('color.line', $colors['line']), 'button' => $token('color.primary', $colors['button']), 'button_text' => $token('color.primary-foreground', $colors['button_text'])];
        });
        $hooks->on('render.warning', fn (string $message) => $this->app->make(SiteBuilder::class)->warn($message));

        // A fresh page starts a fresh style registry.
        $hooks->on('render.begin', fn () => $this->app->make(StyleCompiler::class)->reset());

        $hooks->addFilter('site.build.extras', fn (array $extras) => $this->cms()->settings('site', 'search_index', true)
            ? [...$extras, 'search' => ['path' => 'search.json', 'render' => fn () => $this->app->make(Search\SearchIndex::class)->json()]]
            : $extras);
        $hooks->addFilter('blocks.breakpoints', static fn (array $bp) => $design()->json()->breakpoints() ?: $bp);
        $hooks->addFilter('blocks.options.button-variants', static function (array $options) use ($design) {
            foreach (array_keys($design()->json()->buttons()) as $variant) {
                $options[$variant] ??= ucfirst(str_replace(['-', '_'], ' ', (string) $variant));
            }

            return $options;
        });

        // Blocks rendered outside a page (Markdown twins, search) still see the site.
        $hooks->addFilter('blocks.scope', fn (array $scope) => $scope + ['site' => $this->app->make(Renderer::class)->site()]);
    }

    /** Lists for the Repeat block: entries, child pages, breadcrumbs and headings. */
    private function dataSources(): void
    {
        $this->cms()->hooks()->addFilter('blocks.data-sources', function (array $sources) {
            $entry = static fn (Scope $scope): ?AugmentedEntry => ($e = $scope->get('entry')) instanceof AugmentedEntry ? $e : null;
            $collections = array_map(static fn ($c) => $c->title, array_filter($this->cms()->collections()->all(), static fn ($c) => $c->routable()));

            $sources['entries'] = [
                'label' => 'Collection Entries',
                'description' => __('Live entries of a collection: posts, projects, team members…'),
                'fields' => [
                    ['handle' => 'collection', 'type' => 'select', 'label' => 'Collection', 'options' => $collections, 'required' => true],
                    ['handle' => 'sort', 'type' => 'select', 'label' => 'Sort Order', 'options' => ['-published_at' => __('Newest First'), 'published_at' => __('Oldest First'), 'title' => __('Title A–Z'), '-title' => __('Title Z–A'), 'position' => __('Manual Order')], 'default' => '-published_at'],
                    ['handle' => 'limit', 'type' => 'number', 'label' => 'How Many', 'min' => 1, 'max' => 200, 'default' => 6],
                    ['handle' => 'offset', 'type' => 'number', 'label' => 'Skip', 'min' => 0, 'max' => 200],
                    ['handle' => 'parent', 'type' => 'select', 'label' => 'Level', 'options' => ['any' => __('All'), 'top' => __('Top Level Only'), 'current' => __('Children of This Page'), 'item' => __('Children of the Outer Item')], 'default' => 'any'],
                    ['handle' => 'hasTag', 'type' => 'text', 'label' => 'Only with Tag'],
                    ['handle' => 'where', 'type' => 'json', 'component' => 'blocks-conditions', 'stack' => true, 'label' => 'Only Entries Where'],
                    ['handle' => 'skipCurrent', 'type' => 'toggle', 'label' => 'Leave Out This Page'],
                    ['handle' => 'paginate', 'type' => 'toggle', 'label' => 'Split into Pages'],
                ],
                'item' => ['title' => __('Title'), 'url' => __('Link'), 'date' => __('Published'), 'updated' => __('Updated'), 'excerpt' => __('A field (any field handle)'), 'children' => __('Child Entries'), 'current' => __('Is this page'), 'active' => __('Is this page or above it')],
                'resolve' => function (array $props, Scope $scope) use ($entry) {
                    $collection = (string) ($props['collection'] ?? '');

                    if ($collection === '' || ! $this->cms()->collections()->find($collection)) {
                        return [];
                    }

                    $locale = PageContext::current()?->locale() ?? $this->cms()->defaultLocale();
                    $query = new EntryQueryProxy($this->cms()->entries()->query()->collection($collection)->locale($locale)->live());
                    $query->orderBy((string) ($props['sort'] ?? '-published_at'));

                    match ($props['parent'] ?? 'any') {
                        'top' => $query->parent(null),
                        'current' => $query->parent($entry($scope)?->id ?? '-'),
                        'item' => $query->parent(($scope->get('item') instanceof AugmentedEntry) ? $scope->get('item')->id : '-'),
                        default => null,
                    };

                    if (! empty($props['hasTag'])) {
                        $query->where('tags', 'contains', (string) $props['hasTag']);
                    }

                    foreach ((array) ($props['where'] ?? []) as $where) {
                        if (is_array($where) && is_string($where['field'] ?? null) && $where['field'] !== '') {
                            $value = $where['value'] ?? null;
                            is_string($value) && Scope::isTemplate($value) && $value = Scope::text($scope->resolve($value));
                            $query->where($where['field'], (string) ($where['op'] ?? '='), $value);
                        }
                    }

                    $limit = max(1, min(200, (int) ($props['limit'] ?? 6)));

                    if (! empty($props['paginate'])) {
                        $page = $query->paginate($limit);
                        PageContext::current() && PageContext::current()->pagination = ['current' => $page['page'], 'total' => $page['pages'], 'prev' => $page['prev'], 'next' => $page['next'], 'count' => $page['total']];
                        $items = $page['items'];
                    } else {
                        $items = $query->offset(max(0, (int) ($props['offset'] ?? 0)))->limit($limit + (! empty($props['skipCurrent']) ? 1 : 0))->get();
                    }

                    if (! empty($props['skipCurrent']) && ($current = $entry($scope))) {
                        $items = array_values(array_filter($items, static fn (AugmentedEntry $e) => ! $e->is($current)));
                    }

                    return empty($props['paginate']) ? array_slice($items, 0, $limit) : $items;
                },
            ];

            $sources['children'] = [
                'label' => 'Child Pages',
                'description' => __('Pages below this page (or below the outer item) in a tree collection.'),
                'fields' => [['handle' => 'of', 'type' => 'select', 'label' => 'Of', 'options' => ['entry' => __('This Page'), 'item' => __('The Outer Item'), 'parent' => __('This Page’s Parent'), 'root' => __('The Top of This Page’s Branch')], 'default' => 'entry']],
                'item' => ['title' => __('Title'), 'url' => __('Link'), 'children' => __('Child Pages'), 'current' => __('Is this page'), 'active' => __('Is this page or above it')],
                'resolve' => static function (array $props, Scope $scope) use ($entry) {
                    $of = match ($props['of'] ?? 'entry') {
                        'item' => $scope->get('item'),
                        'parent' => $entry($scope)?->parent(),
                        'root' => $entry($scope)?->root(),
                        default => $entry($scope),
                    };

                    return $of instanceof AugmentedEntry ? $of->children() : [];
                },
            ];

            $sources['breadcrumbs'] = [
                'label' => 'Breadcrumbs',
                'description' => __('The pages above this one, from the top.'),
                'fields' => [['handle' => 'self', 'type' => 'toggle', 'label' => 'Include This Page']],
                'item' => ['title' => __('Title'), 'url' => __('Link')],
                'resolve' => static function (array $props, Scope $scope) use ($entry) {
                    $current = $entry($scope);

                    return $current ? [...$current->ancestors(), ...(! empty($props['self']) ? [$current] : [])] : [];
                },
            ];

            $sources['headings'] = [
                'label' => 'Headings on This Page',
                'description' => __('For a table of contents: the page’s own headings, in order.'),
                'fields' => [['handle' => 'depth', 'type' => 'select', 'label' => 'Down To', 'options' => ['2' => 'H2', '3' => 'H3', '4' => 'H4'], 'default' => '3']],
                'item' => ['text' => __('Heading Text'), 'id' => __('Anchor (link to #id)'), 'level' => __('Level (2–4)')],
                'resolve' => static function (array $props, Scope $scope) use ($entry) {
                    $current = $entry($scope);
                    $body = $current ? $current->entry->collection()->body : null;
                    $value = $body ? $current->field($body) : null;

                    return $value instanceof BlocksDocument ? array_map(static fn ($h) => [...$h, 'url' => '#'.$h['id']], (new Anchors)->headings($value->nodes, (int) ($props['depth'] ?? 3))) : [];
                },
            ];

            return $sources;
        });

        // Pagination of the last paginated Repeat on the page.
        $this->cms()->hooks()->addFilter('site.scope', static fn (array $scope) => [...$scope, 'pagination' => new Rendering\LiveData(static fn () => PageContext::current()?->pagination ?? [])]);
    }

    private function routes(): void
    {
        Route::get('/search.json', fn () => $this->cms()->settings('site', 'search_index', true)
            ? response($this->app->make(Search\SearchIndex::class)->json(), 200, ['Content-Type' => 'application/json; charset=utf-8', 'Cache-Control' => 'public, max-age=300'])
            : abort(404));

        Route::middleware(['web', 'auth:web', 'cms.admin'])->group(static function (): void {
            Route::get('/__preview/{id}', [SiteController::class, 'preview'])->where('id', '[0-9a-z]{26}');
            Route::post('/__preview', [SiteController::class, 'render']);
            Route::post('/__preview/{id}', [SiteController::class, 'render'])->where('id', '[0-9a-z]{26}');
        });

        // Too many requests from a person on the site: the site's own page and a plain message, not a bare error.
        $this->app->make(\Illuminate\Contracts\Debug\ExceptionHandler::class)->renderable(function (\Symfony\Component\HttpKernel\Exception\HttpExceptionInterface $e, \Illuminate\Http\Request $request) {
            if ($e->getStatusCode() !== 429 || $request->is('api/*', 'admin/*') || $request->expectsJson()) {
                return null;
            }

            try {
                $page = $this->app->make(Renderer::class)->message(
                    429,
                    __('Too many tries'),
                    __('We got too many requests from you in a short time and didn’t act on this one. Wait a minute and try again.'),
                    '/'.ltrim($request->path(), '/'),
                    self::sameSiteReferrer($request),
                );
            } catch (\Throwable) {
                return null; // A broken site still answers with the plain error.
            }

            return response($page['html'], 429, [...$e->getHeaders(), 'Content-Type' => 'text/html; charset=utf-8', 'Cache-Control' => 'no-store, private', 'X-Robots-Tag' => 'noindex']);
        });

        // Everything not handled elsewhere is a site page. Registered last.
        $this->app->booted(static function (): void {
            Route::fallback([SiteController::class, 'show'])->middleware([PublicPage::class, 'web']);
        });
    }

    /** Where the visitor came from, only when that's this site: a Referer is theirs to set. */
    private static function sameSiteReferrer(\Illuminate\Http\Request $request): string
    {
        $referer = (string) $request->headers->get('referer', '');
        $path = parse_url($referer, PHP_URL_PATH);

        return is_string($path) && parse_url($referer, PHP_URL_HOST) === $request->getHost() && str_starts_with($path, '/') && ! str_starts_with($path, '//') ? $path : '/';
    }

    /** Publishing is part of what the site is: its fields are on the General settings page, after the core's. */
    private function settingsSchema(): void
    {
        $this->cms()->hooks()->addFilter('settings.fields', static function (array $fields, string $namespace) {
            if ($namespace !== 'site') {
                return $fields;
            }

            return [...$fields,
                ['handle' => 'favicon', 'type' => 'media', 'fallback' => 'url', 'label' => 'Favicon', 'max' => 1, 'instructions' => 'SVG or PNG, square.'],
                ['handle' => 'static', 'section' => 'Publishing', 'type' => 'toggle', 'label' => 'Build a Static Site', 'default' => true],
                ['handle' => 'auto_build', 'section' => 'Publishing', 'type' => 'toggle', 'label' => 'Rebuild Automatically on Publish', 'default' => true],
                ['handle' => 'search_index', 'section' => 'Publishing', 'type' => 'toggle', 'label' => 'Publish a Search Index', 'default' => true, 'instructions' => 'For the Search block.'],
            ];
        });
    }

    private function abilities(): void
    {
        $abilities = $this->cms()->abilities();
        $builder = fn () => $this->app->make(SiteBuilder::class);
        $design = fn () => $this->app->make(Design::class);
        $package = $this->manifest->name;

        $abilities->register('site/build', [
            'label' => 'Publish Site',
            'description' => 'Rebuild the static site (all pages). Work continues in the background; poll site/build-status.',
            'permission' => 'site.build',
            'package' => $package,
            'handler' => function () use ($builder) {
                $builder()->queue(null, __('Manual Rebuild'));

                return $builder()->process(2000);
            },
        ]);

        $abilities->register('site/build-status', [
            'label' => 'Build Status',
            'description' => 'Progress of the static build. Calling it also advances queued work.',
            'permission' => 'admin.access',
            'annotations' => ['readOnly' => true],
            'package' => $package,
            'handler' => fn () => $builder()->process(1500),
        ]);

        $abilities->register('design/get', [
            'label' => 'Design System',
            'description' => 'The site’s design system (content/design): tokens (use as token:group.name in styles), fonts, element styles, prose styles, button variants, page transition, breakpoints, custom CSS and JS, and the templates entries can use.',
            'permission' => 'admin.access',
            'annotations' => ['readOnly' => true],
            'package' => $package,
            'handler' => function () use ($design) {
                $json = $design()->json();

                return [
                    'name' => $json->name(),
                    'tokens' => array_values(array_map(static fn ($k, $v) => ['path' => $k, ...$v], array_keys($json->tokens()), $json->tokens())),
                    'fonts' => $json->fonts(),
                    'fontFiles' => $design()->fontFiles(),
                    'styles' => (object) $json->styles(),
                    'prose' => (object) $json->prose(),
                    'buttons' => (object) $json->buttons(),
                    'transition' => $json->transition(),
                    'breakpoints' => $json->breakpoints(),
                    'templates' => $this->app->make(Templates::class)->options(),
                    'stylesheet' => $design()->stylesheet(),
                    'css' => $design()->customCss(),
                    'js' => $design()->customJs(),
                    'elements' => DesignCompiler::ELEMENTS,
                ];
            },
        ]);

        $abilities->register('design/update', [
            'label' => 'Update Design System',
            'description' => 'Change tokens (e.g. {"color.primary": "#0f766e"}; values may be CSS or {"min","max"} for fluid sizes), dark-mode values (null clears), renames, element styles, prose styles, button variants, fonts or the page transition. Sections you pass replace the stored ones. The site rebuilds.',
            'permission' => 'settings.manage',
            'package' => $package,
            'input' => ['type' => 'object', 'properties' => [
                'tokens' => ['type' => 'object', 'description' => 'path => value'],
                'dark' => ['type' => 'object', 'description' => 'path => dark-mode value'],
                'remove' => ['type' => 'array', 'items' => ['type' => 'string']],
                'rename' => ['type' => 'object', 'description' => 'old path => new path, within a group; references follow'],
                'styles' => ['type' => 'object', 'description' => 'Element styles: {"h1": {"fontSize": "token:text.5xl"}}'],
                'prose' => ['type' => 'object'],
                'buttons' => ['type' => 'object', 'description' => '{"primary": {"base": {...}, "hover": {...}}}'],
                'fonts' => ['type' => 'array'],
                'transition' => ['type' => 'object', 'properties' => ['type' => ['type' => 'string', 'enum' => ['none', 'fade', 'slide', 'scale']], 'duration' => ['type' => 'number']]],
            ]],
            'handler' => function (array $in) use ($design) {
                $json = $design()->json();
                $json->apply($in);
                $json->save();
                $design()->forget();
                $this->cms()->hooks()->dispatch('design.saved');

                return ['saved' => true, 'stylesheet' => $design()->stylesheet()];
            },
        ]);

        $abilities->register('design/preview', [
            'label' => 'Preview Design System',
            'description' => 'The stylesheet the site would get with these changes (the design/update input, plus css), without saving. For live previews.',
            'permission' => 'settings.manage',
            'annotations' => ['readOnly' => true],
            'package' => $package,
            'input' => ['type' => 'object', 'properties' => [
                'tokens' => ['type' => 'object'],
                'dark' => ['type' => 'object'],
                'remove' => ['type' => 'array', 'items' => ['type' => 'string']],
                'rename' => ['type' => 'object'],
                'styles' => ['type' => 'object'],
                'prose' => ['type' => 'object'],
                'buttons' => ['type' => 'object'],
                'transition' => ['type' => 'object'],
                'css' => ['type' => 'string', 'maxLength' => 200000],
            ]],
            'handler' => function (array $in) use ($design) {
                $json = DesignJson::fromArray($design()->json()->toArray());
                $json->apply($in);

                return ['css' => $design()->compile($json, isset($in['css']) ? (string) $in['css'] : $design()->customCss())];
            },
        ]);

        $abilities->register('design/code', [
            'label' => 'Custom CSS and JavaScript',
            'description' => 'Replace the site’s custom.css or custom.js (content/design). CSS is appended after everything else; JS loads as an ES module on every page and may register Alpine components (document.addEventListener("cms:alpine", (e) => e.detail.data("name", () => ({…})))) that elements use through logic.component.',
            'permission' => 'site.code',
            'package' => $package,
            'input' => ['type' => 'object', 'properties' => ['css' => ['type' => 'string', 'maxLength' => 200000], 'js' => ['type' => 'string', 'maxLength' => 200000]]],
            'handler' => function (array $in) use ($design) {
                foreach (['css', 'js'] as $kind) {
                    if (isset($in[$kind])) {
                        $design()->saveCode($kind, (string) $in[$kind]);
                    }
                }

                $this->cms()->hooks()->dispatch('design.saved');

                return ['saved' => array_keys(array_intersect_key($in, ['css' => 1, 'js' => 1]))];
            },
        ]);

        $abilities->register('design/fonts-import', [
            'label' => 'Add a Google Font',
            'description' => 'Download a Google Fonts family (latin, variable weights when available) into content/design/fonts so it is self-hosted, and add it to the design system. Then use it in a font token.',
            'permission' => 'settings.manage',
            'package' => $package,
            'input' => ['type' => 'object', 'properties' => [
                'family' => ['type' => 'string', 'minLength' => 2, 'maxLength' => 60],
                'italic' => ['type' => 'boolean', 'default' => false],
            ], 'required' => ['family']],
            'handler' => function (array $in) use ($design) {
                $fonts = $this->app->make(Fonts::class)->import((string) $in['family'], (bool) ($in['italic'] ?? false));
                $design()->forget();
                $this->cms()->hooks()->dispatch('design.saved');

                return ['fonts' => $fonts];
            },
        ]);

        $abilities->register('design/fonts-upload', [
            'label' => 'Upload a Font',
            'description' => 'Add a .woff2 file (base64) to content/design/fonts and the design system.',
            'permission' => 'settings.manage',
            'package' => $package,
            'input' => ['type' => 'object', 'properties' => [
                'family' => ['type' => 'string', 'minLength' => 1, 'maxLength' => 60],
                'name' => ['type' => 'string', 'maxLength' => 120],
                'data' => ['type' => 'string', 'maxLength' => 4000000],
                'weight' => ['type' => 'string', 'default' => '400'],
                'style' => ['type' => 'string', 'enum' => ['normal', 'italic'], 'default' => 'normal'],
            ], 'required' => ['family', 'data']],
            'handler' => function (array $in) use ($design) {
                $font = $this->app->make(Fonts::class)->upload((string) $in['family'], (string) ($in['name'] ?? $in['family']), (string) $in['data'], (string) ($in['weight'] ?? '400'), (string) ($in['style'] ?? 'normal'));
                $design()->forget();
                $this->cms()->hooks()->dispatch('design.saved');

                return $font;
            },
        ]);

        $abilities->register('site/preview-url', [
            'label' => 'Preview URL',
            'description' => 'URL that previews an entry\'s working copy (drafts included) for signed-in users.',
            'permission' => 'admin.access',
            'annotations' => ['readOnly' => true],
            'package' => $package,
            'input' => ['type' => 'object', 'properties' => ['id' => ['type' => 'string', 'format' => 'ulid']], 'required' => ['id']],
            'handler' => fn (array $in) => ['url' => url('/__preview/'.strtolower($in['id']))],
        ]);

        $this->cms()->permissions()->register('site.build', __('Publish the Site'), __('Site'));
        $this->cms()->permissions()->register('site.code', __('Edit Custom CSS and JavaScript'), __('Design'), trusted: true);

        // Collections and entries choose from the site's templates.
        $this->cms()->hooks()->addFilter('fields.options.templates', fn (array $options, ?string $collection = null) => array_replace($options, $this->app->make(Templates::class)->options($collection)));
        $this->cms()->hooks()->addFilter('fields.definition.templates', function (array $field, array $context): array {
            if (($field['type'] ?? null) !== 'select' || ! empty($field['multiple'])) {
                return $field;
            }
            $collection = $context['collection'] ?? null;

            return array_replace($field, $this->templatePicker($collection instanceof Collection ? $collection : null, inheritCollection: true));
        });
        $this->cms()->hooks()->addFilter('collections.settings', function (array $fields, ?Collection $collection = null) {
            if ($collection?->system && ! $collection->routable()) {
                return $fields;
            }
            // Keep publishing settings available when URLs are enabled later.
            return [...$fields, [
                'handle' => 'template', 'type' => 'select', 'label' => 'Template', 'width' => 'half',
                ...$this->templatePicker($collection),
            ]];
        });
    }

    /** Collection settings inherit the site default; entry fields inherit their collection. */
    private function templatePicker(?Collection $collection, bool $inheritCollection = false): array
    {
        $templates = $this->app->make(Templates::class);
        $fallback = $templates->resolve($inheritCollection && $collection ? [$collection->template] : [], true);

        return [
            'component' => 'site-template',
            'options' => $templates->options($collection?->handle),
            'placeholder' => $fallback['title'] ?? __('Content Only'),
            'fallbackHandle' => $fallback['handle'] ?? null,
            'defaultHandle' => '',
        ];
    }

    private function buildTriggers(): void
    {
        $hooks = $this->cms()->hooks();
        $builder = fn () => $this->app->make(SiteBuilder::class);
        $auto = fn () => (bool) $this->cms()->settings('site', 'auto_build', true);

        $hooks->on('entry.published', function (Entry $entry) use ($builder, $auto) {
            $builder()->forgetEntry($entry);
            $auto() && $builder()->queueForEntry($entry);
        });

        $hooks->on('entry.saved', function (Entry $entry, $user, string $action) use ($builder, $auto) {
            // Structural changes (moves, slug changes) of live entries affect URLs immediately.
            if ($entry->isLive() && in_array($action, ['create', 'save'], true) && $entry->draft === null) {
                $builder()->forgetEntry($entry);
                $auto() && $builder()->queueForEntry($entry);
            }
        });

        $hooks->on('entry.structure-changed', function (Entry $entry) use ($builder, $auto) {
            $builder()->forgetEntry($entry);
            $auto() && $builder()->queue(null, __('Content structure changed'));
        });

        foreach (['entry.unpublished', 'entry.deleted'] as $event) {
            $hooks->on($event, function (Entry $entry) use ($builder, $auto) {
                $builder()->forgetEntry($entry);
                $auto() && $builder()->queueForEntry($entry, __('Removed “:title”', ['title' => $entry->title]));
            });
        }

        foreach (['media.updated', 'media.deleted'] as $event) {
            $hooks->on($event, fn ($item) => $auto() && $builder()->queueForMedia((string) $item->id));
        }

        foreach (['globals.saved', 'design.saved', 'collection.saved', 'collection.deleted', 'entries.reordered', 'navigation.saved'] as $event) {
            $hooks->on($event, fn () => $auto() && $builder()->queue(null, __('Site-Wide Change')));
        }

        // The site's name, URL and language are on every page; the admin's own settings are on none.
        $hooks->on('settings.saved', fn (string $namespace) => $namespace === 'site' && $auto() && $builder()->queue(null, __('Site-Wide Change')));

        // Advance queued builds after the response is sent — no worker or cron needed.
        $this->app->terminating(function () use ($builder) {
            if (! $this->app->runningInConsole() && $builder()->hasWork()) {
                if (function_exists('fastcgi_finish_request')) {
                    fastcgi_finish_request();
                }

                $builder()->process(4000);
            }
        });
    }

    private function adminUi(): void
    {
        $admin = $this->cms()->admin();
        $admin->nav('design', ['label' => 'Design System', 'path' => '/design', 'icon' => 'palette', 'section' => 'design', 'order' => 5, 'permission' => 'settings.manage']);
        $admin->widget('site-status', ['label' => 'Site', 'description' => __('Whether the live site is up to date, with a button to publish it.'), 'icon' => 'rocket', 'column' => 'side', 'enabled' => true, 'order' => 10]);
        $admin->command('publish-site', ['label' => 'Publish Site', 'icon' => 'rocket', 'ability' => 'site/build', 'permission' => 'site.build', 'keywords' => ['build', 'deploy', 'static']]);

        $this->adminModule();
    }

    private function agentContext(): void
    {
        $this->cms()->context()->register(
            'cms://site/design',
            'Design System',
            'The site’s design tokens (use as token:group.name), fonts, element styles, button variants, page transition and breakpoints. Compose with these instead of raw values.',
            fn () => $this->app->make(Design::class)->json()->toArray()
        );
    }
}
