<?php

declare(strict_types=1);

namespace Cms\Blocks\Tests;

use Cms\Abilities\AbilityContext;
use Cms\Admin\Translations;
use Cms\Blocks\BlockRenderer;
use Cms\Blocks\Document;
use Cms\Blocks\Elements;
use Cms\Blocks\Scope;
use Cms\Tests\TestCase;

/** The canvas never shows an empty element as nothing; the published site never shows a placeholder. */
final class PlaceholdersTest extends TestCase
{
    protected function plugins(): array
    {
        return ['letterncms/blocks'];
    }

    protected function collections(): array
    {
        return ['pages' => ['title' => 'Pages', 'route' => '/{slug}', 'body' => 'content', 'fields' => [['handle' => 'content', 'type' => 'blocks']]]];
    }

    private function render(array $nodes, bool $editor, array $scope = []): string
    {
        $renderer = app(BlockRenderer::class);
        $renderer->begin();
        $renderer->editor = $editor;

        try {
            return $renderer->withEditorDocument($editor ? 'test-source' : null, fn () => $renderer->render(app(Document::class)->normalize($nodes), new Scope($scope)));
        } finally {
            $renderer->editor = false;
        }
    }

    public function test_empty_text_media_and_containers_show_placeholders_on_the_canvas_only(): void
    {
        $nodes = [
            ['type' => 'heading', 'props' => ['level' => '2']],
            ['type' => 'paragraph'],
            ['type' => 'button'],
            ['type' => 'image', 'props' => ['aspect' => '4/3']],
            ['type' => 'video'],
            ['type' => 'icon', 'props' => ['icon' => 'not-an-icon']],
            ['type' => 'box'],
        ];

        $canvas = $this->render($nodes, true);

        $this->assertMatchesRegularExpression('/<h2 [^>]*data-cms-placeholder="Heading"><\/h2>/', $canvas);
        $this->assertMatchesRegularExpression('/<p [^>]*data-cms-placeholder="Write something…"><\/p>/', $canvas);
        $this->assertStringContainsString('<span data-cms-prop="label" data-cms-placeholder="Button"></span>', $canvas);
        $this->assertMatchesRegularExpression('/data-cms-type="image"[^>]*data-cms-media><span class="cms-media-placeholder" style="aspect-ratio:4\/3"><svg/', $canvas);
        $this->assertMatchesRegularExpression('/data-cms-type="video"[^>]*data-cms-media>/', $canvas);
        $this->assertMatchesRegularExpression('/data-cms-type="icon"[^>]*data-cms-media[^>]*><svg/', $canvas, 'an unknown icon shows the default one');
        $this->assertMatchesRegularExpression('/data-cms-type="box"[^>]*data-cms-empty><\/div>/', $canvas, 'an empty box is a drop area');
        app(BlockRenderer::class)->editor = true;
        $this->assertStringContainsString('data-cms-editor="placeholders"', implode('', $this->cms()->hooks()->filter('site.head', [])), 'the canvas gets the placeholder styles');
        app(BlockRenderer::class)->editor = false;

        $site = $this->render($nodes, false);

        $this->assertStringNotContainsString('data-cms-', $site);
        $this->assertStringNotContainsString('cms-media-placeholder', $site);
        $this->assertStringNotContainsString('<h2', $site, 'an empty heading is not printed');
        $this->assertStringNotContainsString('<figure', $site);
        $this->assertStringNotContainsString('placeholders', implode('', $this->cms()->hooks()->filter('site.head', [])));
    }

    public function test_the_canvas_and_the_catalog_speak_the_editors_language(): void
    {
        $nl = json_decode((string) file_get_contents(dirname(__DIR__).'/lang/nl.json'), true);
        $hooks = $this->cms()->hooks();

        request()->headers->set('X-Cms-Locale', 'nl');
        $hooks->dispatch('preview.editor', true);

        try {
            $canvas = $this->render([['type' => 'heading', 'props' => ['level' => '2']]], true);
        } finally {
            $hooks->dispatch('preview.editor', false);
            request()->headers->remove('X-Cms-Locale');
        }

        $this->assertStringContainsString('data-cms-placeholder="'.$nl['Heading'].'"', $canvas);
        $this->assertSame(Translations::SOURCE, app(Translations::class)->current(), 'the language is restored after the canvas');

        app(Translations::class)->apply('nl');
        $catalog = $this->cms()->abilities()->execute('blocks/types', [], AbilityContext::forUser($this->admin(), 'rest'));
        $heading = collect($catalog['blocks'])->firstWhere('name', 'heading');
        $section = collect($catalog['blocks'])->firstWhere('name', 'section');

        $this->assertSame($nl['Heading'], $heading['title']);
        $this->assertSame($nl['A title (h1–h6).'], $heading['description']);
        $this->assertSame($nl['Level'], collect($heading['fields'])->firstWhere('handle', 'level')['label']);
        $this->assertSame($nl['Wide'], collect($section['fields'])->firstWhere('handle', 'width')['options']['wide'] ?? null);
        $this->assertSame($nl['Numbers'], collect($catalog['sources'])->firstWhere('name', 'numbers')['label']);
    }

    public function test_text_with_content_has_no_placeholder(): void
    {
        $canvas = $this->render([['type' => 'heading', 'props' => ['text' => 'Hello', 'level' => '2']], ['type' => 'paragraph', 'props' => ['text' => '&nbsp;']]], true);

        $this->assertMatchesRegularExpression('/<h2 [^>]*data-cms-type="heading"[^>]*>Hello<\/h2>/', $canvas);
        $this->assertStringNotContainsString('data-cms-placeholder="Heading"', $canvas);
        $this->assertStringContainsString('data-cms-placeholder="Write something…"', $canvas, 'markup without text counts as empty');
    }

    public function test_unbound_data_shows_what_it_is_bound_to(): void
    {
        $this->cms()->content()->create('components', ['title' => 'Card', 'slug' => 'card', 'status' => 'published', 'data' => [
            'synced' => true,
            'props' => [
                ['handle' => 'title', 'type' => 'text', 'label' => 'Card title'],
                ['handle' => 'link', 'type' => 'url', 'label' => 'Link'],
            ],
            'blocks' => [
                ['type' => 'heading', 'props' => ['level' => '3'], 'bind' => ['text' => 'props.title']],
                ['type' => 'button', 'props' => ['label' => 'More'], 'bind' => ['url' => 'props.link']],
            ],
        ]]);

        $canvas = $this->render([
            ['type' => 'pattern', 'props' => ['ref' => 'card']],
            ['type' => 'paragraph', 'bind' => ['text' => 'entry.cover_caption']],
        ], true, ['entry' => []]);

        $this->assertStringContainsString('data-cms-placeholder="Card title"', $canvas, 'a component property shows its label');
        $this->assertMatchesRegularExpression('/<a [^>]*class="[^"]*cms-button[^"]*"[^>]*href="#"/', $canvas, 'a link property without a value links to # on the canvas');
        $this->assertStringContainsString('data-cms-placeholder="Cover caption"', $canvas, 'other data shows its name');

        $site = $this->render([['type' => 'pattern', 'props' => ['ref' => 'card']]], false);
        $this->assertStringNotContainsString('href="#"', $site);
        $this->assertStringNotContainsString('<h3', $site);
    }

    public function test_a_repeat_without_data_shows_three_sample_items(): void
    {
        $nodes = [['type' => 'repeat', 'props' => ['source' => 'list', 'path' => 'entry.nothing'], 'children' => [
            ['type' => 'heading', 'props' => ['level' => '3'], 'bind' => ['text' => 'item.title']],
        ]]];

        $canvas = $this->render($nodes, true, ['entry' => []]);

        $this->assertSame(3, substr_count($canvas, '<h3 '));
        $this->assertSame(0, substr_count($canvas, 'data-cms-type="heading"'), 'generated repeat instances are not editable source nodes');
        $this->assertSame(3, substr_count($canvas, 'data-cms-placeholder="Title"'));
        $this->assertSame(2, substr_count($canvas, 'data-cms-ghost'), 'items after the first are faded');
        $this->assertStringNotContainsString('<h3', $this->render($nodes, false, ['entry' => []]));

        $empty = $this->render([['type' => 'repeat', 'props' => ['source' => 'list']]], true);
        $this->assertMatchesRegularExpression('/data-cms-type="repeat"[^>]*data-cms-empty><\/div>/', $empty, 'without children it is a drop area');
    }

    public function test_links_to_pages_follow_the_page(): void
    {
        $page = $this->cms()->content()->create('pages', ['title' => 'About', 'slug' => 'about', 'status' => 'published']);

        $this->assertSame($page->uri, Elements::url('entry:'.$page->id));
        $this->assertSame($page->uri.'#team', Elements::url('entry:'.$page->id.'#team'));
        $this->assertNull(Elements::url('entry:missing'));
        $this->assertNull(Elements::url('javascript:alert(1)'));

        $html = $this->render([['type' => 'button', 'props' => ['label' => 'About us', 'url' => 'entry:'.$page->id]]], false);
        $this->assertStringContainsString('href="'.$page->uri.'"', $html);

        $this->cms()->content()->update($page->id, ['slug' => 'company'], publish: true);
        $moved = $this->cms()->entries()->find($page->id);
        $this->assertNotSame($page->uri, $moved->uri);
        $this->assertStringContainsString('href="'.$moved->uri.'"', $this->render([['type' => 'button', 'props' => ['label' => 'About us', 'url' => 'entry:'.$page->id]]], false));

        $draft = $this->cms()->content()->create('pages', ['title' => 'Soon', 'slug' => 'soon']);
        $this->assertNull(Elements::url('entry:'.$draft->id), 'the site does not link to drafts');
    }
}
