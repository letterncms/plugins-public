<?php

declare(strict_types=1);

namespace Cms\Site\Rendering;

use Cms\Admin\Translations;
use Illuminate\Contracts\Support\Htmlable;
use Cms\Blocks\BlockRenderer;
use Cms\Blocks\BlocksDocument;
use Cms\Blocks\Scope;
use Cms\Blocks\Templates;
use Cms\Content\Entries\Entry;
use Cms\Extend\Hooks;
use Cms\Cms;
use Cms\Site\Design\Design;

/**
 * Builds pages. There are no theme files: the HTML document is assembled here,
 * its body is the entry's template (a block document from content/templates)
 * with the entry's own blocks in the template's Page content block.
 *
 *   <html lang dir> head (design CSS, block CSS, plugin head tags) <body> template… </body>
 */
final class Renderer
{
    public function __construct(
        private readonly Cms $cms,
        private readonly Design $design,
        private readonly Templates $templates,
        private readonly BlockRenderer $blocks,
        private readonly Hooks $hooks,
        private readonly HtmlMinifier $minifier,
    ) {}

    /**
     * Render an entry with its template.
     *
     * @return array{html: string, status: int, context: PageContext}
     */
    public function entry(Entry $entry, int $page = 1, bool $preview = false, bool $editor = false, ?array $template = null, ?string $bodyDocument = null, ?string $templateDocument = null): array
    {
        $context = new PageContext($entry, $page, $preview, $editor, $entry->uri ?? '/');
        $template ??= $this->templates->resolve([(string) ($entry->data['template'] ?? ''), $entry->collection()->template], $preview) ?? self::fallback();

        $html = $this->document($context, $template['blocks'], fn () => $this->body($entry), $entry->title, $bodyDocument, $templateDocument);

        return ['html' => $html, 'status' => 200, 'context' => $context];
    }

    /** @return array{html: string, status: int, context: PageContext} */
    public function notFound(string $uri = '/404', bool $preview = false, ?array $template = null): array
    {
        $locale = $this->hooks->filter('site.locale', null, $uri);
        $context = new PageContext(null, 1, $preview, false, $uri, is_string($locale) ? $locale : null);
        $template ??= $this->templates->find('404', $preview) ?? self::fallback404();
        $html = $this->document($context, $template['blocks'], static fn () => '', 'Page not found');

        return ['html' => $html, 'status' => 404, 'context' => $context];
    }

    /**
     * A page for a request that can't go on: the site's `error` or default template around a plain
     * message, with a way back. Never indexed, and it says nothing about the request.
     *
     * @return array{html: string, status: int, context: PageContext}
     */
    public function message(int $status, string $title, string $text, string $uri, string $back = '/', ?string $backLabel = null): array
    {
        $locale = $this->hooks->filter('site.locale', null, $uri);
        $context = new PageContext(null, 1, false, false, $uri, is_string($locale) ? $locale : null);
        $template = $this->templates->resolve(['error', 'default'], false) ?? self::fallback();
        $nodes = [['id' => 'msg', 'type' => 'section', 'props' => ['width' => 'prose'], 'children' => [
            ['id' => 'msgt', 'type' => 'heading', 'props' => ['text' => $title, 'level' => '1']],
            ['id' => 'msgp', 'type' => 'paragraph', 'props' => ['text' => $text]],
            ['id' => 'msgb', 'type' => 'link', 'props' => ['label' => $backLabel ?? __('Go back'), 'url' => $back], 'style' => ['base' => ['display' => 'inline-block', 'marginTop' => '1rem', 'textDecoration' => 'underline']]],
        ]]];
        $html = $this->document($context, $template['blocks'], fn () => $this->blocks->render($nodes), $title);

        return ['html' => $html, 'status' => $status, 'context' => $context];
    }

    /**
     * Render any block document as a page (the builder's canvas for templates
     * and components). $entry provides sample data for templates.
     */
    public function blocks(array $nodes, ?Entry $entry = null, string $title = '', bool $editor = true, ?string $editorDocument = null): string
    {
        $context = new PageContext($entry, 1, true, $editor, $entry?->uri ?? '/');

        return $this->document($context, $nodes, fn () => $entry ? $this->body($entry) : '', $title ?: ($entry?->title ?? ''), templateDocument: $editorDocument);
    }

    /** An entry's blocks as plain text-bearing blocks (components, Repeats and bindings resolved). */
    public function materialize(Entry $entry, array $nodes): array
    {
        $context = new PageContext($entry, 1, false, false, $entry->uri ?? '/');

        return $context->run(fn (PageContext $ctx) => $this->blocks->materialize($nodes, $this->scope($ctx)));
    }

    /** The data documents are rendered with. */
    public function scope(PageContext $context): Scope
    {
        $entry = $context->entry ? new AugmentedEntry($context->entry) : null;

        return new Scope((array) $this->hooks->filter('site.scope', [
            'site' => $this->site(),
            'entry' => $entry,
            'globals' => new LazyGlobals(fn () => $this->globals()),
            'page' => ['uri' => $context->uri, 'number' => $context->page, 'preview' => $context->preview],
        ], $context));
    }

    public function site(): array
    {
        return [
            'name' => config('app.name'),
            'url' => rtrim((string) $this->cms->site('url', config('app.url')), '/'),
            'locale' => $this->cms->defaultLocale(),
            'locales' => $this->cms->locales(),
            'description' => $this->cms->site('description'),
            'year' => date('Y'),
        ];
    }

    public function globals(): array
    {
        PageContext::depend('globals');
        $locale = PageContext::current()?->locale();
        $out = [];

        foreach ($this->cms->globals()->all() as $handle => $set) {
            $out[$handle] = $set->blueprint()->augment($set->values($locale));
        }

        return $out;
    }

    /** The entry's own content: its body field (blocks, Markdown or rich text). */
    private function body(Entry $entry): string
    {
        $field = $entry->collection()->body;
        $value = $field ? (new AugmentedEntry($entry))->field($field) : null;

        $html = match (true) {
            $value instanceof BlocksDocument => $this->blocks->render($value->nodes),
            $value instanceof Htmlable => $value->toHtml(),
            is_string($value) => $value,
            default => '',
        };

        // A teaser ends by saying how to read the rest.
        return $entry->gated === 'teaser' ? $html.$this->hooks->filter('account.teaser-notice', '', $entry) : $html;
    }

    private function document(PageContext $context, array $nodes, \Closure $outlet, string $title, ?string $bodyDocument = null, ?string $templateDocument = null): string
    {
        $this->hooks->dispatch('preview.editor', $context->editor);
        $this->hooks->dispatch('render.begin', $context);
        // Visitors read what the code says (a skip link, a form's messages) in the page's language; the canvas stays in the admin's.
        $translations = $this->cms->app()->make(Translations::class);
        $language = $translations->current();
        $context->editor || $translations->apply($context->locale());

        try {
            $body = $context->run(function (PageContext $ctx) use ($nodes, $outlet, $bodyDocument, $templateDocument) {
                // Which template a page gets can change with any template.
                PageContext::depend('collection:'.Templates::COLLECTION);
                $previous = [$this->blocks->scope, $this->blocks->outlet, $this->blocks->working];
                $this->blocks->scope = $this->scope($ctx);
                $this->blocks->outlet = $outlet;
                $this->blocks->working = $ctx->preview;

                try {
                    $html = $this->blocks->withOutletDocument($bodyDocument, fn () => $this->blocks->withEditorDocument($templateDocument, fn () => $this->blocks->render($nodes)));
                    if ($ctx->editor && $templateDocument !== null) {
                        $html = '<div data-cms-editor-root="'.e($templateDocument).'" style="display:contents">'.($html !== '' ? $html : '<div class="cms-placeholder" data-cms-editor-empty-root>'.e(__('Content')).'</div>').'</div>';
                    }
                    $source = $bodyDocument ?? $templateDocument;
                    if ($ctx->editor && $source !== null) $html .= $this->blocks->editorPrototypes($source);

                    return $html;
                } finally {
                    [$this->blocks->scope, $this->blocks->outlet, $this->blocks->working] = $previous;
                }
            });

            return $this->finish($context, $body, $title);
        } finally {
            $translations->apply($language);
            $this->hooks->dispatch('preview.editor', false);
        }
    }

    private function finish(PageContext $context, string $body, string $title): string
    {
        $entry = $context->entry;
        $locale = $context->locale();
        $dir = function_exists('cms_text_direction') ? cms_text_direction($locale) : 'ltr';
        $name = (string) config('app.name');
        $design = $this->design->json();

        $head = [
            '<meta charset="utf-8">',
            '<meta name="viewport" content="width=device-width, initial-scale=1">',
            '<title>'.e($title !== '' && $entry?->uri !== '/' ? "{$title} · {$name}" : $name).'</title>',
        ];

        if ($design->hasDark()) {
            // A saved light/dark choice applies before the first paint.
            $head[] = '<script>try{var t=localStorage.getItem("cms-theme");if(t)document.documentElement.dataset.theme=t}catch(e){}</script>';
        }

        foreach ($this->design->preloads() as $href) {
            $head[] = '<link rel="preload" href="'.e($href).'" as="font" type="font/woff2" crossorigin>';
        }

        if ($favicon = \Cms\Blocks\Media::url(cms()->settings('site', 'favicon'))) {
            $head[] = '<link rel="icon" href="'.e($favicon).'">';
        } else {
            // Without one, every browser asks for /favicon.ico and gets a 404.
            $head[] = '<link rel="icon" href="data:,">';
        }

        $head[] = '<link rel="stylesheet" href="'.e($this->design->stylesheet()).'">';

        if ($context->preview) {
            $head[] = '<meta name="robots" content="noindex, nofollow">';
        }

        $head = [...$head, ...array_values($context->head)];
        $head = $this->hooks->filter('site.head', $head, $context);

        if ($context->styles !== []) {
            $head[] = '<style>'.$context->css().'</style>';
        }

        $foot = array_values($context->foot);

        if (! $context->editor && ($script = $this->design->script())) {
            $foot[] = '<script type="module" src="'.e($script).'"></script>';
        }

        $foot = $this->hooks->filter('site.foot', $foot, $context);
        $skip = ! $context->editor && str_contains($body, 'id="main"') ? '<a class="cms-skip" href="#main">'.e(__('Skip to content')).'</a>' : '';
        $alpine = ! $context->editor && (! empty($this->blocks->features['logic']) || ! empty($this->blocks->features['ajax'])) ? ' x-data' : '';

        $html = '<!doctype html><html lang="'.e($locale).'" dir="'.e($dir).'"><head>'.implode("\n", $head).'</head><body'.$alpine.'>'.$skip.$body.implode("\n", $foot).'</body></html>';
        $html = $this->hooks->filter('site.html', $html, $context);

        return $context->editor || config('app.debug') && $context->preview ? $html : $this->minifier->minify($html);
    }

    /** Used until a site has a `default` template: the content, nothing else. */
    public static function fallback(): array
    {
        return ['handle' => 'default', 'title' => 'Default', 'blocks' => [
            ['id' => 'tmain', 'type' => 'box', 'props' => ['tag' => 'main'], 'anchor' => 'main', 'children' => [['id' => 'toutlet', 'type' => 'outlet', 'props' => []]]],
        ]];
    }

    public static function fallback404(): array
    {
        return ['handle' => '404', 'title' => 'Not Found', 'blocks' => [
            ['id' => 't404', 'type' => 'section', 'props' => ['width' => 'prose'], 'anchor' => 'main', 'style' => ['base' => ['textAlign' => 'center']], 'children' => [
                ['id' => 't404c', 'type' => 'paragraph', 'props' => ['text' => '404'], 'style' => ['base' => ['color' => 'token:color.muted']]],
                ['id' => 't404h', 'type' => 'heading', 'props' => ['text' => 'This page does not exist', 'level' => '1']],
                ['id' => 't404l', 'type' => 'link', 'props' => ['label' => 'Go to the Home Page', 'url' => '/']],
            ]],
        ]];
    }
}
