<?php

declare(strict_types=1);

namespace Cms\Seo;

use Illuminate\Support\Facades\Route;
use Cms\Abilities\AbilityContext;
use Cms\Abilities\AbilityException;
use Cms\Content\Collections\Collection;
use Cms\Content\Entries\Entry;
use Cms\Modules\Settings\SettingsModule;
use Cms\Packages\Plugin;
use Cms\Support\Schema\Shape;
use Cms\Users\User;

final class SeoPlugin extends Plugin
{
    public const array FIELDS = [
        ['handle' => 'title', 'type' => 'text', 'label' => 'SEO Title', 'max' => 120],
        ['handle' => 'description', 'type' => 'textarea', 'label' => 'Meta Description', 'max' => 320],
        ['handle' => 'image', 'type' => 'media', 'label' => 'Social Image', 'max' => 1],
        ['handle' => 'canonical', 'type' => 'url', 'component' => 'link', 'files' => false, 'label' => 'Canonical URL', 'placeholder' => 'This Page'],
        ['handle' => 'schema', 'type' => 'select', 'label' => 'Page Type', 'options' => ['WebPage' => 'Web Page', 'Article' => 'Article', 'AboutPage' => 'About Page', 'ContactPage' => 'Contact Page', 'CollectionPage' => 'Collection Page', 'FAQPage' => 'FAQ Page', 'ProfilePage' => 'Profile Page']],
        ['handle' => 'noindex', 'type' => 'toggle', 'label' => 'Hide from Search Engines'],
    ];

    public function register(): void
    {
        foreach ([Meta::class, JsonLd::class, MarkdownTwin::class, SiteFiles::class, Redirects::class, Analyzer::class, Health::class] as $service) {
            $this->app->singleton($service);
        }
    }

    /**
     * Redirects are a tab of the SEO settings: one table, saved from the save bar like the settings beside it.
     * New Redirect is in the bar; with none yet, the tab says so and offers it in the table's place.
     */
    private function redirectsTab(): void
    {
        // The one way to make a redirect: in the bar while the tab is on view, and in the table's place while there are none.
        $new = ['label' => __('New Redirect'), 'icon' => 'plus', 'variant' => 'primary', 'ability' => 'redirects/create', 'then' => 'refresh',
            'input' => ['from' => '{values.from}', 'to' => '{values.to}', 'status' => '{values.status}'],
            'form' => ['title' => __('New Redirect'), 'confirm' => __('Create Redirect'), 'values' => ['status' => 301], 'fields' => self::redirectFields(autofocus: true)]];

        $this->cms()->hooks()->addFilter('view.settings', fn (array $view, User $user, array $params) => ($params['namespace'] ?? '') !== 'seo' || ! $user->hasPermission('seo.manage') ? $view : SettingsModule::withTab($view, [
            'type' => 'ui/tab', 'props' => ['id' => 'redirects', 'label' => __('Redirects'), 'actions' => [$new]], 'children' => [$this->app->make(Redirects::class)->all() === []
                ? ['type' => 'ui/empty', 'props' => ['icon' => 'arrow-right', 'title' => __('No redirects yet'), 'action' => array_diff_key($new, ['variant' => true])]]
                : ['type' => 'ui/form', 'props' => [
                    'source' => ['ability' => 'redirects/list'], 'into' => 'redirects', 'width' => 'wide',
                    'fields' => [['handle' => 'redirects', 'type' => 'repeater', 'display' => 'table', 'label' => __('Redirects'), 'add' => false, 'removeLabel' => __('Delete'), 'fields' => self::redirectFields()]],
                    'save' => ['ability' => 'redirects/set', 'as' => '*'],
                ]],
            ],
        ]));
    }

    /** One redirect, as a row of the table and as the dialog that makes the first. */
    private static function redirectFields(bool $autofocus = false): array
    {
        return [
            ['handle' => 'from', 'type' => 'text', 'label' => __('From'), 'mono' => true, 'placeholder' => '/old-url', ...($autofocus ? ['required' => true, 'autofocus' => true] : [])],
            ['handle' => 'to', 'type' => 'text', 'label' => __('To'), 'mono' => true, 'placeholder' => __('/new-url or https://…'), ...($autofocus ? ['required' => true] : [])],
            // Every status a redirect can have, in words first: a redirect made elsewhere (307, 308) reads as one too.
            ['handle' => 'status', 'type' => 'select', 'label' => __('Status'), 'required' => true, 'numeric' => true, 'default' => 301, 'options' => ['301' => __('Permanent (301)'), '302' => __('Temporary (302)'), '307' => __('Temporary (307)'), '308' => __('Permanent (308)')]],
        ];
    }

    public function boot(): void
    {
        $this->cms()->permissions()->register('seo.manage', 'Manage SEO Settings and Redirects', 'Site', roles: ['editor']);
        $this->addSeoField();
        $this->settingsSchema();
        $this->rendering();
        $this->buildOutput();
        $this->routes();
        $this->abilities();
        $this->redirectsTab();

        // What the Audit Log calls each change, once it has happened.
        // Redirects are the old addresses of a site's pages: they go where the pages go.
        $this->cms()->hooks()->addFilter('sites.content', static fn (array $folders): array => [...$folders, 'redirects']);
        $this->cms()->hooks()->addFilter('audit.past', static fn (array $past): array => $past + [
            'redirects/create' => __('Created Redirect'),
            'redirects/set' => __('Saved Redirects'),
        ]);

        $this->cms()->admin()->widget('seo-health', ['label' => 'SEO Health', 'description' => __('How published pages score for search and AI answers, and what to fix first.'), 'icon' => 'seo', 'column' => 'main', 'order' => 45]);
        $this->adminModule();
    }

    /** Every routable collection gets an `seo` group (edited in the SEO panel, not the main form). */
    private function addSeoField(): void
    {
        $fields = self::FIELDS;

        $this->cms()->hooks()->addFilter('collections', static function (array $collections) use ($fields) {
            foreach ($collections as $handle => $collection) {
                /** @var Collection $collection */
                if (! $collection->routable() || $collection->blueprint()->field('seo')) {
                    continue;
                }

                $definition = $collection->toYaml();
                // Left empty, the page type is what Meta falls back to: an article for dated entries, a web page otherwise.
                $own = array_map(static fn (array $f) => $f['handle'] === 'schema' ? [...$f, 'placeholder' => $collection->isDated() ? 'Article' : 'Web Page'] : $f, $fields);
                $definition['fields'][] = ['handle' => 'seo', 'type' => 'group', 'label' => 'SEO', 'section' => 'seo', 'localizable' => true, 'fields' => $own];
                $collections[$handle] = Collection::fromArray($handle, $definition);
            }

            return $collections;
        });

        // Keep the injected field out of collections/save round-trips.
        $this->cms()->hooks()->addFilter('ability.input', static function (array $input, $ability) {
            if ($ability->name === 'collections/save' && isset($input['definition']['fields'])) {
                $input['definition']['fields'] = array_values(array_filter($input['definition']['fields'], static fn ($f) => ! (($f['handle'] ?? null) === 'seo' && ($f['section'] ?? null) === 'seo')));
            }

            return $input;
        });
    }

    private function settingsSchema(): void
    {
        $crawlers = SiteFiles::AI_CRAWLERS;

        $this->cms()->settingsRegistry()->register('seo', fields: [
            ['handle' => 'title_format', 'width' => 'half', 'type' => 'text', 'label' => 'Title Format', 'default' => '{title} · {site}', 'placeholder' => '{title} · {site}'],
            ['handle' => 'home_title', 'width' => 'half', 'type' => 'text', 'label' => 'Home Page Title'],
            ['handle' => 'default_image', 'type' => 'media', 'label' => 'Default Social Image', 'max' => 1],
            ['handle' => 'org_type', 'section' => 'Organization', 'width' => 'half', 'type' => 'select', 'label' => 'Type', 'options' => ['Organization' => __('Organization'), 'LocalBusiness' => __('Local Business'), 'Person' => __('Person')], 'default' => 'Organization', 'required' => true],
            ['handle' => 'org_name', 'section' => 'Organization', 'width' => 'half', 'type' => 'text', 'label' => 'Name'],
            ['handle' => 'org_logo', 'section' => 'Organization', 'type' => 'media', 'label' => 'Logo', 'max' => 1],
            ['handle' => 'same_as', 'section' => 'Organization', 'type' => 'tags', 'label' => 'Profiles', 'placeholder' => 'https://linkedin.com/company/…'],
            ['handle' => 'llms_summary', 'section' => 'AI Crawlers', 'type' => 'textarea', 'label' => 'Summary for AI (llms.txt)'],
            ['handle' => 'markdown_twins', 'section' => 'AI Crawlers', 'type' => 'toggle', 'label' => 'Publish Markdown Versions of Pages', 'default' => true],
            ['handle' => 'blocked_crawlers', 'section' => 'AI Crawlers', 'type' => 'select', 'label' => 'Block These AI Crawlers', 'multiple' => true, 'options' => array_combine(array_keys($crawlers), array_map(static fn ($label, $agent) => "{$agent} — {$label}", $crawlers, array_keys($crawlers))), 'instructions' => 'Blocked search crawlers can’t cite you.'],
            ['handle' => 'security_contact', 'section' => 'Indexing and Security', 'type' => 'text', 'label' => 'Security Contact', 'placeholder' => 'security@example.com'],
            ['handle' => 'discourage_indexing', 'section' => 'Indexing and Security', 'type' => 'toggle', 'label' => 'Discourage All Indexing'],
        ], icon: 'seo', description: __('Titles, social cards, structured data, sitemap and AI crawlers.'), permission: 'seo.manage', order: 20);
    }

    private function rendering(): void
    {
        $hooks = $this->cms()->hooks();
        $jsonLd = fn () => $this->app->make(JsonLd::class);

        $hooks->on('render.begin', fn () => $jsonLd()->reset());

        // Bindable: `page.markdown` is the URL of the page's Markdown twin (a "View as Markdown" link).
        $hooks->addFilter('site.scope', fn (array $scope, $page) => $page->entry && $page->entry->uri !== null && $this->cms()->setting('seo', 'markdown_twins', true)
            ? [...$scope, 'page' => [...(array) ($scope['page'] ?? []), 'markdown' => $page->entry->uri === '/' ? '/index.md' : $page->entry->uri.'.md']]
            : $scope);
        $hooks->on('render.structured', fn (string $key, array $data) => $jsonLd()->add($key, $data));

        $hooks->addFilter('site.head', function (array $head, $context) use ($jsonLd) {
            $entry = $context->entry ?? null;

            if (! $entry instanceof Entry) {
                return $head;
            }

            $m = $this->app->make(Meta::class)->forEntry($entry);
            $tags = [
                '<meta name="description" content="'.e($m['description']).'">',
                '<link rel="canonical" href="'.e($m['canonical']).'">',
                '<meta property="og:type" content="'.$m['type'].'">',
                '<meta property="og:title" content="'.e(Meta::isHome($entry) ? $m['title'] : ($entry->data['seo']['title'] ?? $entry->title)).'">',
                '<meta property="og:description" content="'.e($m['description']).'">',
                '<meta property="og:url" content="'.e($m['canonical']).'">',
                '<meta property="og:site_name" content="'.e((string) config('app.name')).'">',
                '<meta property="og:locale" content="'.e(Meta::ogLocale($entry->locale)).'">',
                '<meta name="twitter:card" content="'.($m['image'] ? 'summary_large_image' : 'summary').'">',
            ];

            // A preview already says noindex, from the core.
            if (! $context->preview) {
                $tags[] = '<meta name="robots" content="'.e($m['robots']).'">';
            }

            if ($m['image']) {
                $tags[] = '<meta property="og:image" content="'.e($m['image']).'">';
            }

            if ($m['type'] === 'article' && $m['published']) {
                $tags[] = '<meta property="article:published_time" content="'.e($m['published']).'">';
                $tags[] = '<meta property="article:modified_time" content="'.e((string) $m['modified']).'">';
            }

            if ($this->cms()->setting('seo', 'markdown_twins', true) && empty($entry->data['seo']['noindex']) && ! $this->cms()->entries()->gate()->isGated($entry)) {
                $tags[] = '<link rel="alternate" type="text/markdown" href="'.e($entry->uri === '/' ? '/index.md' : $entry->uri.'.md').'">';
            }

            foreach ($this->cms()->hooks()->filter('seo.alternates', [], $entry) as $alt) {
                $tags[] = '<link rel="alternate" hreflang="'.e($alt['locale']).'" href="'.e((string) $this->app->make(Meta::class)->absolute($alt['uri'])).'">';

                if ($alt['locale'] !== 'x-default' && $alt['locale'] !== $entry->locale) {
                    $tags[] = '<meta property="og:locale:alternate" content="'.e(Meta::ogLocale($alt['locale'])).'">';
                }
            }

            $tags[] = '<script type="application/ld+json">'.json_encode($jsonLd()->graph($entry), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_HEX_TAG).'</script>';

            return [...$head, ...$tags];
        });

        // The SEO title wins over whatever <title> the theme printed.
        $hooks->addFilter('site.html', function (string $html, $context) {
            $entry = $context->entry ?? null;

            if (! $entry instanceof Entry) {
                return $html;
            }

            $title = e($this->app->make(Meta::class)->forEntry($entry)['title']);

            return preg_replace('#<title>.*?</title>#s', "<title>{$title}</title>", $html, 1) ?? $html;
        });
    }

    private function buildOutput(): void
    {
        $hooks = $this->cms()->hooks();
        $files = fn () => $this->app->make(SiteFiles::class);

        // llms.txt is the site's own file: SEO says what it knows about the pages, it does not write a second one.
        $hooks->addFilter('site.llms', fn (array $document) => $files()->llms($document));

        $hooks->addFilter('site.build.extras', fn (array $extras) => [
            ...$extras,
            'sitemap' => ['path' => 'sitemap.xml', 'render' => fn () => $files()->sitemap()],
            ...array_combine(
                array_map(static fn (string $locale) => 'sitemap:'.$locale, $languages = array_keys($files()->languageSitemaps())),
                array_map(fn (string $locale) => ['path' => $files()->languageSitemaps()[$locale], 'render' => fn () => $files()->sitemap($locale)], $languages),
            ),
            'robots' => ['path' => 'robots.txt', 'render' => fn () => $files()->robots()],
            ...($files()->securityTxt() === null ? [] : ['security' => ['path' => '.well-known/security.txt', 'render' => fn () => $files()->securityTxt()]]),
            ...array_combine(
                array_map(static fn (string $path) => 'feed:'.$path, array_keys($feeds = $this->app->make(Feeds::class)->all())),
                array_map(fn (string $path, array $feed) => ['path' => $path, 'render' => fn () => $this->app->make(Feeds::class)->render($feed['collection'], $feed['locale'])], array_keys($feeds), $feeds),
            ),
        ]);

        // Readers find a feed from its collection's pages and the page that lists them.
        $hooks->addFilter('site.head', function (array $head, $context = null) {
            foreach ($this->app->make(Feeds::class)->all() as $path => $feed) {
                $entry = $context->entry ?? null;
                $here = $entry && $entry->collection === $feed['collection'] && $entry->locale === $feed['locale'];

                if ($here || ($context->uri ?? null) === $feed['listing']) {
                    $title = $this->cms()->collections()->find($feed['collection'])?->title ?? '';
                    $head[] = '<link rel="alternate" type="application/rss+xml" title="'.e($title).'" href="/'.e($path).'">';
                }
            }

            return $head;
        });

        // Markdown twin next to each built page.
        $hooks->on('site.page.built', function (string $uri, Entry $entry, string $file, int $page) {
            if ($page !== 1 || ! $this->cms()->setting('seo', 'markdown_twins', true)) {
                return;
            }

            $builder = $this->app->make(\Cms\Contracts\SiteBuilder::class);

            if (method_exists($builder, 'write')) {
                $builder->write($uri === '/' ? 'index.md' : trim($uri, '/').'.md', $this->app->make(MarkdownTwin::class)->render($entry));
            }
        });

        $hooks->on('site.page.removed', function (string $uri) {
            $builder = $this->app->make(\Cms\Contracts\SiteBuilder::class);

            if (method_exists($builder, 'deleteFile')) {
                $builder->deleteFile($uri === '/' ? 'index.md' : trim($uri, '/').'.md');
            }
        });

        // Static redirect stubs, rewritten whenever redirects change or the site is built.
        $writeRedirects = function () {
            $builder = $this->app->make(\Cms\Contracts\SiteBuilder::class);

            if (! method_exists($builder, 'write')) {
                return;
            }

            foreach ($this->app->make(Redirects::class)->all() as $redirect) {
                $path = trim($redirect['from'], '/');

                if ($path !== '' && ! $this->cms()->entries()->findByUri($redirect['from'])) {
                    $builder->write($path.'/index.html', $this->app->make(Redirects::class)->html($redirect));
                }
            }
        };

        $hooks->on('site.built', $writeRedirects);
        $hooks->on('redirects.saved', $writeRedirects);

        // Moving a live page keeps old links working.
        $hooks->on('entry.uri-changed', fn (Entry $entry, string $from, string $to) => $this->app->make(Redirects::class)->moved($from, $to));

        // Every SEO setting ends up in the built files (head tags, JSON-LD, robots.txt, llms.txt): changing one rebuilds the site.
        $hooks->addFilter('site.build.signature', fn (array $parts) => [...$parts, 'seo' => md5((string) json_encode($this->cms()->setting('seo')))]);
    }

    private function routes(): void
    {
        // Dynamic fallbacks for sites that are not (yet) built.
        Route::middleware('web')->group(function () {
            Route::get('/sitemap.xml', fn () => response($this->app->make(SiteFiles::class)->sitemap(), 200, ['Content-Type' => 'application/xml; charset=utf-8']));
            Route::get('/sitemap-{locale}.xml', function (string $locale) {
                abort_unless(isset($this->app->make(SiteFiles::class)->languageSitemaps()[$locale]), 404);

                return response($this->app->make(SiteFiles::class)->sitemap($locale), 200, ['Content-Type' => 'application/xml; charset=utf-8']);
            })->where('locale', '[a-zA-Z-]+');
            Route::get('/{feed}', function (string $feed) {
                $found = $this->app->make(Feeds::class)->all()[$feed] ?? abort(404);

                return response($this->app->make(Feeds::class)->render($found['collection'], $found['locale']), 200, ['Content-Type' => 'application/rss+xml; charset=utf-8']);
            })->where('feed', '.*feed\.xml');
            Route::get('/robots.txt', fn () => response($this->app->make(SiteFiles::class)->robots(), 200, ['Content-Type' => 'text/plain; charset=utf-8']));
            Route::get('/.well-known/security.txt', fn () => response($this->app->make(SiteFiles::class)->securityTxt() ?? abort(404), 200, ['Content-Type' => 'text/plain; charset=utf-8']));
            Route::get('/{path}.md', function (string $path) {
                abort_unless($this->cms()->setting('seo', 'markdown_twins', true), 404);
                $entry = $this->app->make(MarkdownTwin::class)->at($path === 'index' ? '/' : '/'.$path);
                abort_if(! $entry || ! $entry->isLive() || ! empty($entry->data['seo']['noindex']) || $this->cms()->entries()->gate()->isGated($entry), 404);

                return response($this->app->make(MarkdownTwin::class)->render($entry), 200, ['Content-Type' => 'text/markdown; charset=utf-8']);
            })->where('path', '[A-Za-z0-9/_-]+');
        });

        // Redirects before the site renders a 404.
        $this->app['router']->pushMiddlewareToGroup('web', Http\RedirectMiddleware::class);
    }

    private function abilities(): void
    {
        $a = $this->cms()->abilities();

        // What they answer with.
        $redirect = ['from' => Shape::STRING, 'to' => Shape::STRING, 'publishedAt' => Shape::STRING_OR_NULL, 'updatedAt' => Shape::STRING_OR_NULL, 'status' => Shape::enum(301, 302, 307, 308)];

        // An act that changes nothing, like the core's content/search: it works the checks out for the entry as it is now, draft included.
        $a->register('seo/analyze', [
            'label' => 'Check SEO',
            'description' => 'On-page checks for search engines and AI answers (title, description, headings, alt text, answer-first, questions, links) with a score.',
            'permission' => fn ($user, array $in) => ($e = $this->cms()->entries()->find((string) $in['id'])) && $user?->hasPermission("entries.{$e->collection}.view"),
            'annotations' => ['readOnly' => true],
            'input' => ['type' => 'object', 'properties' => ['id' => ['type' => 'string', 'format' => 'ulid']], 'required' => ['id']],
            'output' => Shape::of([
                'score' => Shape::INTEGER,
                'checks' => [['id' => Shape::STRING, 'status' => Shape::enum('pass', 'warn', 'fail'), 'label' => Shape::STRING, 'message' => Shape::STRING]],
                'meta' => [
                    'title' => Shape::STRING, 'description' => Shape::STRING, 'image' => Shape::STRING_OR_NULL, 'canonical' => Shape::STRING, 'robots' => Shape::STRING,
                    'type' => Shape::enum('article', 'website'), 'schema' => Shape::STRING, 'published' => Shape::STRING_OR_NULL, 'modified' => Shape::STRING_OR_NULL,
                ],
            ]),
            'handler' => fn (array $in) => $this->app->make(Analyzer::class)->analyze($this->cms()->entries()->findOrFail($in['id'])),
        ]);

        $a->register('seo/health', [
            'label' => 'SEO Health',
            'description' => 'The on-page checks of seo/analyze across published pages (the 200 changed last; noindex pages left out): average score, how many pages score below 80, the most common issues and the lowest-scoring pages. Refreshed at most every 10 minutes.',
            'permission' => 'admin.access',
            'annotations' => ['readOnly' => true],
            'input' => ['type' => 'object', 'properties' => ['limit' => ['type' => 'integer', 'minimum' => 1, 'maximum' => 20, 'default' => 5]]],
            'output' => Shape::of([
                'pages' => Shape::INTEGER, 'score' => Shape::INTEGER_OR_NULL, 'attention' => Shape::INTEGER, 'good' => Shape::INTEGER,
                'issues' => [['id' => Shape::STRING, 'label' => Shape::STRING, 'pages' => Shape::INTEGER, 'failing' => Shape::INTEGER]],
                'worst' => [[
                    'id' => Shape::STRING, 'collection' => Shape::STRING, 'locale' => Shape::STRING, 'title' => Shape::STRING, 'uri' => Shape::STRING_OR_NULL,
                    'score' => Shape::INTEGER, 'issues' => Shape::INTEGER, 'top' => Shape::STRING_OR_NULL,
                ]],
            ]),
            'handler' => function (array $in, AbilityContext $ctx) {
                $health = $this->app->make(Health::class);
                $pages = array_values(array_filter($health->pages(), static fn (array $p) => $ctx->system || ($ctx->user?->hasPermission("entries.{$p['collection']}.view") ?? false)));

                return $health->summary($pages, $in['limit']);
            },
        ]);

        // The health is cached; saving or deleting an entry checks again on the next look.
        foreach (['entry.saved', 'entry.deleted'] as $event) {
            $this->cms()->hooks()->on($event, fn () => $this->app->make(Health::class)->forget());
        }

        $a->register('seo/markdown', [
            'label' => 'Page as Markdown',
            'description' => 'The clean Markdown version of a live page that AI crawlers receive.',
            'permission' => 'admin.access',
            'annotations' => ['readOnly' => true],
            'input' => ['type' => 'object', 'properties' => ['id' => ['type' => 'string', 'format' => 'ulid']], 'required' => ['id']],
            'output' => Shape::of(['markdown' => Shape::STRING]),
            'handler' => fn (array $in) => ['markdown' => $this->app->make(MarkdownTwin::class)->render($this->cms()->entries()->findOrFail($in['id'])->withWorkingCopy())],
        ]);

        $a->register('redirects/list', [
            'label' => 'List Redirects',
            'description' => 'URL redirects (content/redirects.yaml).',
            'permission' => 'seo.manage',
            'annotations' => ['readOnly' => true],
            'output' => Shape::of([$redirect]),
            'handler' => fn () => $this->app->make(Redirects::class)->all(),
        ]);

        $a->register('redirects/create', [
            'label' => 'Create Redirect',
            'description' => 'Add one redirect to the list: from a path on this site ("/old") to a path or an address ("/new", "https://…"). Refused when `from` already redirects: redirects/set replaces the list.',
            'permission' => 'seo.manage',
            'input' => ['type' => 'object', 'properties' => [
                'from' => ['type' => 'string', 'minLength' => 1, 'maxLength' => 2000], 'to' => ['type' => 'string', 'minLength' => 1, 'maxLength' => 2000], 'status' => ['type' => 'integer', 'enum' => [301, 302, 307, 308], 'default' => 301],
            ], 'required' => ['from', 'to']],
            'output' => Shape::of($redirect),
            'handler' => fn (array $in) => $this->app->make(Redirects::class)->add($in['from'], $in['to'], $in['status']),
        ]);

        // The list is one value, put in place whole: `save` is for definitions kept by a handle.
        $a->register('redirects/set', [
            'label' => 'Set Redirects',
            'description' => 'Replace the redirect list: what is left out is gone. Returns the list as it is now. Each: {from: "/old", to: "/new" or "https://…", status: 301|302}.',
            'permission' => 'seo.manage',
            'input' => ['type' => 'object', 'properties' => ['redirects' => ['type' => 'array', 'items' => ['type' => 'object', 'properties' => [
                'from' => ['type' => 'string', 'default' => ''], 'to' => ['type' => 'string', 'default' => ''], 'status' => ['type' => 'integer', 'enum' => [301, 302, 307, 308], 'default' => 301],
            ]]]], 'required' => ['redirects']],
            'output' => Shape::of([$redirect]),
            // Rows left empty are no redirects: a table's new row that was never filled in is dropped, not refused.
            'handler' => fn (array $in) => $this->app->make(Redirects::class)->save(array_values(array_filter($in['redirects'], static fn (array $r) => trim($r['from']) !== '' && trim($r['to']) !== ''))),
        ]);
    }
}
