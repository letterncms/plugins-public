<?php

declare(strict_types=1);

namespace Cms\Seo;

use Illuminate\Support\Facades\Route;
use Cms\Abilities\AbilityContext;
use Cms\Abilities\AbilityException;
use Cms\Content\Collections\Collection;
use Cms\Content\Entries\Entry;
use Cms\Packages\Plugin;

final class SeoPlugin extends Plugin
{
    public const array FIELDS = [
        ['handle' => 'title', 'type' => 'text', 'label' => 'SEO Title', 'max' => 120, 'instructions' => 'Defaults to the entry title.'],
        ['handle' => 'description', 'type' => 'textarea', 'label' => 'Meta Description', 'max' => 320, 'instructions' => '70–160 characters. Defaults to the first paragraph.'],
        ['handle' => 'image', 'type' => 'media', 'fallback' => 'url', 'label' => 'Social Image', 'max' => 1],
        ['handle' => 'canonical', 'type' => 'url', 'component' => 'link', 'files' => false, 'label' => 'Canonical URL', 'placeholder' => 'This Page'],
        ['handle' => 'schema', 'type' => 'select', 'label' => 'Page Type', 'options' => ['WebPage' => 'Web page', 'Article' => 'Article', 'AboutPage' => 'About page', 'ContactPage' => 'Contact page', 'CollectionPage' => 'Collection page', 'FAQPage' => 'FAQ page', 'ProfilePage' => 'Profile page']],
        ['handle' => 'noindex', 'type' => 'toggle', 'label' => 'Hide from Search Engines'],
    ];

    public function register(): void
    {
        foreach ([Meta::class, JsonLd::class, MarkdownTwin::class, SiteFiles::class, Redirects::class, Analyzer::class, Health::class] as $service) {
            $this->app->singleton($service);
        }
    }

    public function boot(): void
    {
        $this->cms()->permissions()->register('seo.manage', __('Manage SEO settings and redirects'), __('Site'));
        $this->addSeoField();
        $this->settingsSchema();
        $this->rendering();
        $this->buildOutput();
        $this->routes();
        $this->abilities();

        $this->cms()->admin()->nav('redirects', ['label' => 'Redirects', 'path' => '/redirects', 'icon' => 'arrow-up-right', 'section' => 'tools', 'order' => 40, 'permission' => 'seo.manage']);
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
                $definition['fields'][] = ['handle' => 'seo', 'type' => 'group', 'label' => 'SEO', 'section' => 'seo', 'localizable' => true, 'fields' => $fields];
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

        $this->cms()->settingsRegistry()->register('seo', __('SEO & AI visibility'), [
            ['handle' => 'title_format', 'type' => 'text', 'label' => 'Title Format', 'default' => '{title} · {site}', 'instructions' => 'Use {title} and {site}.'],
            ['handle' => 'home_title', 'type' => 'text', 'label' => 'Home Page Title', 'instructions' => 'Defaults to the site name.'],
            ['handle' => 'default_image', 'type' => 'media', 'fallback' => 'url', 'label' => 'Default Social Image', 'max' => 1],
            ['handle' => 'org_type', 'type' => 'select', 'label' => 'You Are', 'options' => ['Organization' => __('An organization'), 'LocalBusiness' => __('A local business'), 'Person' => __('A person')], 'default' => 'Organization'],
            ['handle' => 'org_name', 'type' => 'text', 'label' => 'Name'],
            ['handle' => 'org_logo', 'type' => 'media', 'fallback' => 'url', 'label' => 'Logo', 'max' => 1],
            ['handle' => 'same_as', 'type' => 'tags', 'label' => 'Profiles', 'instructions' => 'Your social and directory profiles (LinkedIn, GitHub…).'],
            ['handle' => 'llms_summary', 'type' => 'textarea', 'label' => 'Summary for AI (llms.txt)', 'instructions' => 'One or two sentences on who you are and what the site offers.'],
            ['handle' => 'markdown_twins', 'type' => 'toggle', 'label' => 'Publish Markdown versions of pages (/page.md)', 'default' => true],
            ['handle' => 'blocked_crawlers', 'type' => 'select', 'label' => 'Block These AI Crawlers', 'multiple' => true, 'options' => array_combine(array_keys($crawlers), array_map(static fn ($label, $agent) => "{$agent} — {$label}", $crawlers, array_keys($crawlers))), 'instructions' => 'Blocking search crawlers (OAI-SearchBot, Claude-SearchBot, PerplexityBot) stops AI assistants from citing you.'],
            ['handle' => 'security_contact', 'type' => 'text', 'label' => 'Security Contact', 'instructions' => 'An email address or a page where people report vulnerabilities. Publishes /.well-known/security.txt.'],
            ['handle' => 'discourage_indexing', 'type' => 'toggle', 'label' => 'Discourage All Indexing (Staging Sites)'],
        ], icon: 'seo', description: __('Titles, social cards, structured data, sitemap and AI crawlers.'), package: $this->manifest->name, permission: 'seo.manage', order: 20);
    }

    private function rendering(): void
    {
        $hooks = $this->cms()->hooks();
        $jsonLd = fn () => $this->app->make(JsonLd::class);

        $hooks->on('render.begin', fn () => $jsonLd()->reset());

        // Bindable: `page.markdown` is the URL of the page's Markdown twin (a "View as Markdown" link).
        $hooks->addFilter('site.scope', fn (array $scope, $page) => $page->entry && $page->entry->uri !== null && $this->cms()->settings('seo', 'markdown_twins', true)
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
                '<meta name="robots" content="'.e($context->preview ? 'noindex, nofollow' : $m['robots']).'">',
                '<meta property="og:type" content="'.$m['type'].'">',
                '<meta property="og:title" content="'.e(Meta::isHome($entry) ? $m['title'] : ($entry->data['seo']['title'] ?? $entry->title)).'">',
                '<meta property="og:description" content="'.e($m['description']).'">',
                '<meta property="og:url" content="'.e($m['canonical']).'">',
                '<meta property="og:site_name" content="'.e((string) config('app.name')).'">',
                '<meta property="og:locale" content="'.e(Meta::ogLocale($entry->locale)).'">',
                '<meta name="twitter:card" content="'.($m['image'] ? 'summary_large_image' : 'summary').'">',
            ];

            if ($m['image']) {
                $tags[] = '<meta property="og:image" content="'.e($m['image']).'">';
            }

            if ($m['type'] === 'article' && $m['published']) {
                $tags[] = '<meta property="article:published_time" content="'.e($m['published']).'">';
                $tags[] = '<meta property="article:modified_time" content="'.e((string) $m['modified']).'">';
            }

            if ($this->cms()->settings('seo', 'markdown_twins', true) && empty($entry->data['seo']['noindex']) && ! $this->cms()->entries()->gate()->isGated($entry)) {
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

        $hooks->addFilter('site.build.extras', fn (array $extras) => [
            ...$extras,
            'sitemap' => ['path' => 'sitemap.xml', 'render' => fn () => $files()->sitemap()],
            ...array_combine(
                array_map(static fn (string $locale) => 'sitemap:'.$locale, $languages = array_keys($files()->languageSitemaps())),
                array_map(fn (string $locale) => ['path' => $files()->languageSitemaps()[$locale], 'render' => fn () => $files()->sitemap($locale)], $languages),
            ),
            'robots' => ['path' => 'robots.txt', 'render' => fn () => $files()->robots()],
            'llms' => ['path' => 'llms.txt', 'render' => fn () => $files()->llms()],
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
            if ($page !== 1 || ! $this->cms()->settings('seo', 'markdown_twins', true)) {
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

        foreach (['settings.saved'] as $event) {
            $hooks->on($event, function (string $namespace) {
                if ($namespace === 'seo') {
                    $builder = $this->app->make(\Cms\Contracts\SiteBuilder::class);
                    $builder->available() && $builder->queue(null, __('SEO settings changed'));
                }
            });
        }
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
            Route::get('/llms.txt', fn () => response($this->app->make(SiteFiles::class)->llms(), 200, ['Content-Type' => 'text/plain; charset=utf-8']));
            Route::get('/.well-known/security.txt', fn () => response($this->app->make(SiteFiles::class)->securityTxt() ?? abort(404), 200, ['Content-Type' => 'text/plain; charset=utf-8']));
            Route::get('/{path}.md', function (string $path) {
                abort_unless($this->cms()->settings('seo', 'markdown_twins', true), 404);
                $entry = $this->cms()->entries()->findByUri($path === 'index' ? '/' : '/'.$path);
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
        $package = $this->manifest->name;

        $a->register('seo/analyze', [
            'label' => 'Check SEO & GEO',
            'description' => 'On-page checks for search engines and AI answers (title, description, headings, alt text, answer-first, questions, links) with a score.',
            'permission' => fn ($user, array $in) => ($e = $this->cms()->entries()->find((string) $in['id'])) && $user?->hasPermission("entries.{$e->collection}.view"),
            'annotations' => ['readOnly' => true],
            'package' => $package,
            'input' => ['type' => 'object', 'properties' => ['id' => ['type' => 'string', 'format' => 'ulid']], 'required' => ['id']],
            'handler' => fn (array $in) => $this->app->make(Analyzer::class)->analyze($this->cms()->entries()->findOrFail($in['id'])),
        ]);

        $a->register('seo/health', [
            'label' => 'SEO Health',
            'description' => 'The on-page checks of seo/analyze across published pages (the 200 changed last; noindex pages left out): average score, how many pages score below 80, the most common issues and the lowest-scoring pages. Refreshed at most every 10 minutes.',
            'permission' => 'admin.access',
            'annotations' => ['readOnly' => true],
            'package' => $package,
            'input' => ['type' => 'object', 'properties' => ['limit' => ['type' => 'integer', 'minimum' => 1, 'maximum' => 20, 'default' => 5]]],
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
            'package' => $package,
            'input' => ['type' => 'object', 'properties' => ['id' => ['type' => 'string', 'format' => 'ulid']], 'required' => ['id']],
            'handler' => fn (array $in) => ['markdown' => $this->app->make(MarkdownTwin::class)->render($this->cms()->entries()->findOrFail($in['id'])->withWorkingCopy())],
        ]);

        $a->register('redirects/list', [
            'label' => 'List Redirects',
            'description' => 'URL redirects (content/redirects.yaml).',
            'permission' => 'seo.manage',
            'annotations' => ['readOnly' => true],
            'package' => $package,
            'handler' => fn () => $this->app->make(Redirects::class)->all(),
        ]);

        $a->register('redirects/save', [
            'label' => 'Save Redirects',
            'description' => 'Replace the redirect list. Each: {from: "/old", to: "/new" or "https://…", status: 301|302}.',
            'permission' => 'seo.manage',
            'package' => $package,
            'input' => ['type' => 'object', 'properties' => ['redirects' => ['type' => 'array', 'items' => ['type' => 'object', 'properties' => [
                'from' => ['type' => 'string'], 'to' => ['type' => 'string'], 'status' => ['type' => 'integer', 'enum' => [301, 302, 307, 308], 'default' => 301],
            ], 'required' => ['from', 'to']]]], 'required' => ['redirects']],
            'handler' => fn (array $in) => $this->app->make(Redirects::class)->save($in['redirects']),
        ]);
    }
}
