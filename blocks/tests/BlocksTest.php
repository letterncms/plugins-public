<?php

declare(strict_types=1);

namespace Cms\Blocks\Tests;

use Cms\Abilities\AbilityContext;
use Cms\Abilities\AbilityException;
use Cms\Blocks\BlockRenderer;
use Cms\Blocks\Document;
use Cms\Blocks\MarkdownCodec;
use Cms\Blocks\StyleCompiler;
use Cms\Tests\TestCase;

final class BlocksTest extends TestCase
{
    public function test_a_block_keeps_a_short_id_so_the_editor_finds_it_on_the_canvas(): void
    {
        $nodes = $this->app->make(\Cms\Blocks\Document::class)->normalize([['id' => 'dl', 'type' => 'paragraph', 'props' => ['text' => 'Hi']]]);

        $this->assertSame('dl', $nodes[0]['id']);
    }

    public function test_creation_options_provide_a_usable_explicit_blocks_body(): void
    {
        $admin = $this->admin();
        $ctx = AbilityContext::forUser($admin, 'admin');
        $options = $this->cms()->abilities()->execute('collections/options', [], $ctx);
        $this->assertSame('content', $options['defaults']['body']);
        $this->assertSame('blocks', $options['defaults']['fields'][0]['type']);
        $this->cms()->abilities()->execute('collections/save', [
            'handle' => 'articles', 'createOnly' => true,
            'definition' => [...$options['defaults'], 'title' => 'Articles'],
        ], $ctx);
        $collection = $this->cms()->collections()->findOrFail('articles');
        $this->assertSame('blocks', $collection->blueprint()->field($collection->body)->type);
        $entry = $this->cms()->content()->create('articles', [
            'title' => 'A shared document', 'data' => ['content' => [['type' => 'paragraph', 'props' => ['text' => 'Hello']]]],
        ], $admin);
        $this->assertSame('Hello', $entry->data['content'][0]['props']['text']);
    }

    protected function plugins(): array
    {
        return ['letterncms/blocks'];
    }

    protected function collections(): array
    {
        return [
            'pages' => ['title' => 'Pages', 'route' => '/{slug}', 'body' => 'content', 'fields' => [['handle' => 'content', 'type' => 'blocks']]],
            'docs' => ['title' => 'Docs', 'driver' => 'files', 'route' => '/docs/{slug}', 'body' => 'content', 'fields' => [['handle' => 'content', 'type' => 'blocks']]],
        ];
    }

    public function test_cms_describes_itself_through_repeat_sources(): void
    {
        $sources = app(\Cms\Blocks\DataSources::class);
        $scope = new \Cms\Blocks\Scope([]);

        $groups = $sources->resolve('cms-ability-groups', [], $scope);
        $this->assertContains('entries', array_column($groups, 'name'));
        $this->assertNotEmpty($sources->resolve('cms-block-categories', [], $scope));
        $this->assertSame('abilities', $sources->resolve('cms-facts', [], $scope)[0]['label']);
        $this->assertCount(3, $sources->resolve('cms-abilities', ['limit' => 3], $scope));
    }

    public function test_markdown_round_trip_keeps_structure(): void
    {
        $codec = app(MarkdownCodec::class);
        $markdown = "## Hello\n\nSome **bold** and [a link](/x).\n\n- One\n- Two\n\n::: section {\"props\":{\"width\":\"wide\"},\"style\":{\"base\":{\"paddingY\":\"token:space.16\"}}}\n### Inside\n\n:::: buttons\n::::: button {\"props\":{\"label\":\"Go\",\"url\":\"/go\"}}\n:::::\n::::\n:::\n\n```php\necho 1;\n```";

        $nodes = app(Document::class)->normalize($codec->decode($markdown));

        $this->assertSame(['heading', 'paragraph', 'list', 'section', 'code'], array_column($nodes, 'type'));
        $this->assertSame('Some <strong>bold</strong> and <a href="/x">a link</a>.', $nodes[1]['props']['text']);
        $this->assertSame('wide', $nodes[3]['props']['width']);
        $this->assertSame('token:space.16', $nodes[3]['style']['base']['paddingBlock'], 'shorthand keys are stored as CSS properties');
        $this->assertSame('button', $nodes[3]['children'][1]['children'][0]['type']);
        $this->assertSame('php', $nodes[4]['props']['language']);

        $again = app(Document::class)->normalize($codec->decode($codec->encode($nodes)));
        $this->assertSame(array_column($nodes, 'type'), array_column($again, 'type'));
        $this->assertSame($nodes[3]['children'][1]['children'][0]['props']['label'], $again[3]['children'][1]['children'][0]['props']['label']);
    }

    public function test_document_sanitizes_text_and_styles(): void
    {
        $nodes = app(Document::class)->normalize([
            ['type' => 'paragraph', 'props' => ['text' => 'Hi <script>alert(1)</script><em>there</em><img src=x onerror=alert(1)>']],
            ['type' => 'section', 'style' => ['base' => ['paddingY' => 'token:space.8', 'color' => 'red;}body{display:none', 'nonsense' => '1px'], 'evil' => ['color' => 'red']], 'children' => [
                ['type' => 'columns', 'children' => [['type' => 'paragraph', 'props' => ['text' => 'not a column']], ['type' => 'column']]],
            ]],
            ['type' => 'from-a-removed-plugin', 'props' => ['keep' => 'me']],
        ]);

        $this->assertSame('Hi <em>there</em>', $nodes[0]['props']['text']);
        $this->assertSame(['base' => ['paddingBlock' => 'token:space.8']], $nodes[1]['style']);
        $this->assertSame(['column'], array_column($nodes[1]['children'][0]['children'], 'type'));
        $this->assertSame('me', $nodes[2]['props']['keep']);
        $this->assertMatchesRegularExpression('/^b[a-z0-9]{10}$/', $nodes[0]['id']);
    }

    public function test_styles_compile_to_atomic_responsive_css(): void
    {
        $css = [];
        $this->cms()->hooks()->on('render.style', function ($class, $rule) use (&$css) {
            $css[$class] = $rule;
        });

        $compiler = app(StyleCompiler::class);
        $a = $compiler->classes(['base' => ['paddingTop' => 'token:space.8'], 'mobile' => ['paddingTop' => 'token:space.4'], 'hover' => ['color' => 'token:color.primary/50']]);
        $b = $compiler->classes(['base' => ['paddingTop' => 'token:space.8']]);

        $this->assertCount(3, explode(' ', $a));
        $this->assertSame(explode(' ', $a)[0], $b, 'identical declarations share a class');
        $all = implode('', $css);
        $this->assertStringContainsString('padding-top:var(--space-8)', $all);
        $this->assertStringContainsString('@media (max-width:767.98px)', $all);
        $this->assertStringContainsString(':hover{color:color-mix(in srgb, var(--color-primary) 50%, transparent)}', $all);
    }

    public function test_renders_blocks_with_theme_independent_markup(): void
    {
        $html = app(BlockRenderer::class)->render(app(Document::class)->normalize([
            ['type' => 'heading', 'props' => ['text' => 'Title', 'level' => '1']],
            ['type' => 'columns', 'props' => ['layout' => '1-2'], 'children' => [
                ['type' => 'column', 'children' => [['type' => 'paragraph', 'props' => ['text' => 'Left']]]],
                ['type' => 'column', 'children' => [['type' => 'button', 'props' => ['label' => 'Go', 'url' => '/go']]]],
            ]],
            ['type' => 'video', 'props' => ['url' => 'https://www.youtube.com/watch?v=dQw4w9WgXcQ']],
            ['type' => 'embed', 'props' => ['url' => 'https://evil.example.com/x']],
        ]));

        $this->assertStringContainsString('<h1 class="cms-heading" id="title">Title</h1>', $html);
        $this->assertMatchesRegularExpression('/<div class="cms-columns( s\w+)+" data-stack="md">/', $html, 'layout props become atomic styles');
        $this->assertStringContainsString('<a class="cms-button" data-variant="primary" data-size="md" href="/go"><span>Go</span></a>', $html);
        $this->assertStringContainsString('youtube-nocookie.com/embed/dQw4w9WgXcQ', $html);
        $this->assertStringNotContainsString('evil.example.com', $html);
    }

    public function test_entries_accept_markdown_and_files_driver_stores_it(): void
    {
        $entry = $this->cms()->content()->create('docs', ['title' => 'Intro', 'data' => ['content' => "# Hi\n\nWelcome."]]);

        $this->assertSame('heading', $entry->data['content'][0]['type']);
        $file = content_path('collections/docs/intro.md');
        $this->assertStringContainsString("# Hi\n\nWelcome.", file_get_contents($file));
        $this->assertSame('Welcome.', $this->cms()->entries()->find($entry->id)->data['content'][1]['props']['text']);
    }

    public function test_custom_html_needs_permission(): void
    {
        $editor = $this->userWithRole('editor');
        $ctx = AbilityContext::forUser($editor, 'rest');

        $this->expectException(AbilityException::class);
        $this->cms()->abilities()->execute('entries/create', ['collection' => 'pages', 'title' => 'X', 'data' => ['content' => [['type' => 'html', 'props' => ['html' => '<script>x</script>']]]]], $ctx);
    }

    public function test_headings_get_stable_unique_anchors(): void
    {
        $nodes = [
            ['type' => 'heading', 'props' => ['text' => 'Getting started', 'level' => '2']],
            ['type' => 'section', 'children' => [
                ['type' => 'heading', 'props' => ['text' => 'Install <em>Cms</em>', 'level' => '3']],
                ['type' => 'heading', 'props' => ['text' => 'Getting started', 'level' => '2']],
            ]],
            ['type' => 'heading', 'props' => ['text' => 'Custom', 'level' => '2', 'anchor' => 'my-anchor']],
            ['type' => 'heading', 'props' => ['text' => 'Deep', 'level' => '4']],
        ];

        $toc = (new \Cms\Blocks\BlocksDocument($nodes))->headings();
        $this->assertSame(['getting-started', 'install-cms', 'getting-started-2', 'my-anchor'], array_column($toc, 'id'));
        $this->assertSame('Install Cms', $toc[1]['text']);

        $this->assertSame('Getting started Install Cms Getting started Custom Deep', $this->app->make(\Cms\Blocks\Document::class)->text([...$nodes, ['type' => 'section', 'props' => ['align' => 'center'], 'style' => ['base' => ['color' => 'token:color.primary']]]]));

        $html = $this->app->make(\Cms\Blocks\BlockRenderer::class)->render($nodes);
        foreach (['getting-started', 'install-cms', 'getting-started-2', 'my-anchor', 'deep'] as $id) {
            $this->assertStringContainsString('id="'.$id.'"', $html);
        }
    }

    public function test_hand_written_markdown_gets_stable_block_ids(): void
    {
        $field = \Cms\Content\Fields\Field::fromArray(['handle' => 'body', 'type' => 'blocks']);
        $markdown = "## Hello\n\n::: section\nText\n:::";
        $type = new \Cms\Blocks\BlocksField;

        $first = $type->fromMarkdown($markdown, $field);
        $second = $type->fromMarkdown($markdown, $field);

        $this->assertSame($first, $second);
        $ids = [$first[0]['id'], $first[1]['id'], $first[1]['children'][0]['id']];
        $this->assertCount(3, array_unique($ids));
        $this->assertMatchesRegularExpression('/^b[0-9a-f]{10}$/', $ids[0]);
    }
}
