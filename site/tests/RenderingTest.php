<?php

declare(strict_types=1);

namespace Cms\Site\Tests;

use Cms\Site\Rendering\Renderer;
use Cms\Tests\TestCase;

/** Pages render from templates and components (entries), with no theme files. */
final class RenderingTest extends TestCase
{
    protected function plugins(): array
    {
        return ['letterncms/blocks', 'letterncms/site'];
    }

    protected function collections(): array
    {
        return [
            'pages' => ['title' => 'Pages', 'route' => '/{slug}', 'template' => 'page', 'body' => 'content', 'fields' => [['handle' => 'content', 'type' => 'blocks']]],
            'posts' => ['title' => 'Posts', 'structure' => 'dated', 'route' => '/blog/{slug}', 'body' => 'content', 'fields' => [['handle' => 'content', 'type' => 'blocks'], ['handle' => 'excerpt', 'type' => 'textarea']]],
        ];
    }

    public function test_plugins_add_their_collection_settings(): void
    {
        $settings = fn (string $handle) => array_column($this->cms()->collections()->settingsFields($this->cms()->collections()->find($handle)), null, 'handle');

        $pages = $settings('pages');
        $this->assertSame(['content' => 'Content'], $pages['body']['options']);
        $this->assertSame('None', $pages['body']['placeholder']);
        $this->assertArrayNotHasKey('editor', $pages, 'editing capabilities come from fields, not a collection mode');
        $this->assertSame('template', $pages['template']['handle']);

        // Templates have no URL of their own, so no template to pick.
        $this->assertArrayNotHasKey('template', $settings('templates'));
    }

    public function test_collection_templates_are_explicit_and_the_picker_only_offers_real_templates(): void
    {
        $templates = $this->app->make(\Cms\Modules\Blocks\Templates::class);
        $posts = $this->cms()->collections()->find('posts');
        $this->assertSame('', $posts->template);
        $settings = fn () => array_column($this->cms()->collections()->settingsFields($posts), null, 'handle')['template'];
        $this->assertSame([], $settings()['options']);
        $this->assertSame('Content Only', $settings()['placeholder']);

        $this->cms()->content()->create('templates', ['title' => 'Default', 'slug' => 'default', 'status' => 'published', 'data' => ['blocks' => [['type' => 'outlet']]]]);
        $templates->flush();
        $this->assertSame('Default', $settings()['placeholder']);
        $this->assertSame('default', $templates->resolve([$posts->template])['handle']);
        $this->assertArrayNotHasKey('posts_single', $settings()['options']);

        $this->cms()->content()->create('templates', ['title' => 'Post detail', 'slug' => 'posts_single', 'status' => 'published', 'data' => ['blocks' => [
            ['type' => 'heading', 'props' => ['text' => 'Post template']], ['type' => 'outlet'],
        ]]]);
        $templates->flush();
        $this->assertSame('Post detail', $settings()['options']['posts_single']);
        $this->assertSame('default', $templates->resolve([$posts->template])['handle'], 'Creating a similarly named template does not silently change a collection.');
        $definition = $posts->toYaml();
        $definition['template'] = 'posts_single';
        $this->cms()->collections()->save(\Cms\Content\Collections\Collection::fromArray('posts', $definition));
        $post = $this->cms()->content()->create('posts', ['title' => 'Hello', 'status' => 'published', 'data' => ['excerpt' => 'Hi']]);
        $this->assertStringContainsString('Post template', $this->app->make(Renderer::class)->entry($this->cms()->entries()->find($post->id))['html']);

        $this->cms()->content()->create('templates', ['title' => 'Missing page', 'slug' => '404', 'status' => 'published', 'data' => ['blocks' => []]]);
        $this->cms()->content()->create('templates', ['title' => 'Pages only', 'slug' => 'pages_only', 'status' => 'published', 'data' => ['collections' => ['pages'], 'blocks' => []]]);
        $templates->flush();
        $this->assertSame(['default' => 'Default', 'posts_single' => 'Post detail'], $settings()['options']);

        // A maximum-length collection handle must still fit its template suffix.
        $longHandle = str_repeat('a', 64).'_single';
        $long = $this->cms()->content()->create('templates', ['title' => 'Long handle', 'slug' => $longHandle, 'data' => ['blocks' => []]]);
        $this->assertSame($longHandle, $long->slug);
    }

    public function test_entry_template_fields_share_picker_configuration_and_resolve_the_collection_fallback(): void
    {
        $collections = $this->cms()->collections();
        $definition = $collections->find('posts')->toYaml();
        $definition['template'] = 'article';
        $definition['fields'][] = ['handle' => 'template', 'type' => 'select', 'dynamic' => 'templates', 'section' => 'sidebar'];
        $definition['fields'][] = ['handle' => 'other_layout', 'type' => 'select', 'dynamic' => 'templates'];
        $definition['fields'][] = ['handle' => 'template_choices', 'type' => 'select', 'multiple' => true, 'dynamic' => 'templates'];
        $collections->save(\Cms\Content\Collections\Collection::fromArray('posts', $definition));
        $posts = $collections->find('posts');
        $fields = fn () => array_column($posts->toArray()['resolvedFields'], null, 'handle');
        $this->assertSame('Content Only', $fields()['template']['placeholder']);
        $this->assertNull($fields()['template']['fallbackHandle']);
        $content = $this->cms()->content();
        $templates = $this->app->make(\Cms\Modules\Blocks\Templates::class);
        $content->create('templates', ['title' => 'Site default', 'slug' => 'default', 'status' => 'published', 'data' => ['blocks' => [['type' => 'outlet']]]]);
        $templates->flush();
        $this->assertSame('Site default', $fields()['template']['placeholder'], 'a missing collection template falls back exactly like the renderer');
        $content->create('templates', ['title' => 'Article', 'slug' => 'article', 'status' => 'published', 'data' => ['collections' => ['posts'], 'blocks' => [['type' => 'outlet']]]]);
        $content->create('templates', ['title' => 'Pages only', 'slug' => 'pages_only', 'status' => 'published', 'data' => ['collections' => ['pages'], 'blocks' => []]]);
        $templates->flush();
        $field = $fields()['template'];
        $this->assertSame('site-template', $field['component']);
        $this->assertSame('Article', $field['placeholder']);
        $this->assertSame('article', $field['fallbackHandle']);
        $this->assertSame('', $field['defaultHandle']);
        $this->assertSame(['article' => 'Article', 'default' => 'Site default'], $field['options']);
        $this->assertSame($field['component'], $fields()['other_layout']['component'], 'the declared dynamic source selects presentation, not a magic field handle');
        $this->assertArrayNotHasKey('component', $fields()['template_choices'], 'multiple template choices keep the multiple-select contract');
        $settings = array_column($collections->settingsFields($posts), null, 'handle')['template'];
        $this->assertSame('site-template', $settings['component']);
        $this->assertSame('Site default', $settings['placeholder'], 'collection settings inherit the site; entries inherit their collection');
        $this->assertSame($field['options'], $settings['options']);
        $this->assertArrayNotHasKey('component', array_column($posts->toYaml()['fields'], null, 'handle')['template'], 'resolved presentation is never written into the collection definition');
        $plain = \Cms\Content\Fields\Blueprint::make([['handle' => 'template', 'type' => 'select', 'options' => ['x' => 'X']]])->toArray(['collection' => $posts]);
        $this->assertArrayNotHasKey('component', $plain[0], 'an unrelated select with the same handle remains an ordinary select');
    }

    public function test_a_page_is_its_template_with_the_entry_in_the_outlet(): void
    {
        $content = $this->cms()->content();
        $content->create('components', ['title' => 'Header', 'slug' => 'header', 'status' => 'published', 'data' => ['synced' => true, 'blocks' => [
            ['type' => 'box', 'props' => ['tag' => 'header'], 'children' => [['type' => 'link', 'props' => ['url' => '/'], 'bind' => ['label' => 'site.name']]]],
        ]]]);
        $content->create('templates', ['title' => 'Page', 'slug' => 'page', 'status' => 'published', 'data' => ['blocks' => [
            ['type' => 'pattern', 'props' => ['ref' => 'header']],
            ['type' => 'box', 'props' => ['tag' => 'main'], 'anchor' => 'main', 'children' => [
                ['type' => 'heading', 'props' => ['level' => '1'], 'bind' => ['text' => 'entry.title']],
                ['type' => 'outlet'],
                ['type' => 'repeat', 'props' => ['source' => 'entries', 'collection' => 'posts', 'limit' => 5], 'children' => [
                    ['type' => 'link', 'bind' => ['label' => 'item.title', 'url' => 'item.url']],
                ]],
            ]],
        ]]]);
        $content->create('posts', ['title' => 'First post', 'slug' => 'first', 'status' => 'published', 'data' => ['excerpt' => 'Hi']]);
        $page = $content->create('pages', ['title' => 'About us', 'slug' => 'about', 'status' => 'published', 'data' => ['content' => [['type' => 'paragraph', 'props' => ['text' => 'We make sites.']]]]]);

        $result = $this->app->make(Renderer::class)->entry($this->cms()->entries()->find($page->id));
        $html = $result['html'];

        $this->assertStringStartsWith('<!doctype html><html lang="en" dir="ltr">', $html);
        $this->assertMatchesRegularExpression('#<link rel="stylesheet" href="/_cms/site/design-\w+\.css">#', $html);
        $this->assertStringContainsString('<a class="cms-skip" href="#main">', $html);
        $this->assertMatchesRegularExpression('#<header class="cms-box"><a class="cms-link" href="/"[^>]*>#', $html);
        $this->assertStringContainsString('About us</h1>', $html);
        $this->assertStringContainsString('<p class="cms-paragraph">We make sites.</p>', $html);
        $this->assertStringContainsString('href="/blog/first">First post</a>', $html);

        $deps = array_keys($result['context']->dependencies);
        $this->assertContains('collection:templates', $deps, 'any template change rebuilds the page');
        $this->assertContains('collection:posts', $deps);
        $this->assertNotEmpty(array_filter($deps, static fn ($d) => str_starts_with($d, 'entry:')), 'the component it uses is a dependency');
    }

    public function test_search_and_twins_read_the_text_of_components_and_repeats(): void
    {
        $content = $this->cms()->content();
        $content->create('components', ['title' => 'Intro', 'slug' => 'intro', 'status' => 'published', 'data' => ['synced' => true,
            'props' => [['handle' => 'title', 'type' => 'text', 'label' => 'Title']],
            'blocks' => [['type' => 'heading', 'props' => ['level' => '2'], 'bind' => ['text' => 'props.title']]],
        ]]);
        $content->create('posts', ['title' => 'Launch notes', 'slug' => 'launch', 'status' => 'published', 'data' => []]);
        $page = $content->create('pages', ['title' => 'Home', 'slug' => 'home', 'status' => 'published', 'data' => ['content' => [
            ['type' => 'pattern', 'props' => ['ref' => 'intro', 'values' => ['title' => 'Built for agencies']]],
            ['type' => 'repeat', 'props' => ['source' => 'entries', 'collection' => 'posts'], 'children' => [['type' => 'paragraph', 'bind' => ['text' => 'item.title']]]],
        ]]]);

        $nodes = $this->app->make(Renderer::class)->materialize($this->cms()->entries()->find($page->id), $this->cms()->entries()->find($page->id)->data['content']);
        $text = $this->app->make(\Cms\Modules\Blocks\Document::class)->text($nodes);

        $this->assertSame('Built for agencies Launch notes', $text);
    }

    public function test_without_templates_a_page_still_renders_its_content(): void
    {
        $page = $this->cms()->content()->create('pages', ['title' => 'Bare', 'slug' => 'bare', 'status' => 'published', 'data' => ['content' => [['type' => 'heading', 'props' => ['text' => 'Hello', 'level' => '1']]]]]);
        $html = $this->app->make(Renderer::class)->entry($this->cms()->entries()->find($page->id))['html'];

        $this->assertStringContainsString('<main class="cms-box" id="main"><h1 class="cms-heading" id="hello">Hello</h1></main>', $html);
        $this->assertSame(404, $this->app->make(Renderer::class)->notFound('/nope')['status']);
    }

    public function test_buttons_from_the_design_system_are_valid_block_variants(): void
    {
        $this->cms()->abilities()->execute('design/update', ['buttons' => ['on-brand' => ['base' => ['color' => '#000']]]], \Cms\Abilities\AbilityContext::system());

        $field = app(\Cms\Modules\Blocks\BlockRegistry::class)->get('button')->blueprint()->field('variant');

        $this->assertSame('On brand', $field->get('options')['on-brand']);
        $this->assertSame('Primary', $field->get('options')['primary']);
    }

    public function test_the_design_system_compiles_tokens_fonts_elements_and_buttons(): void
    {
        $this->cms()->abilities()->execute('design/update', [
            'tokens' => ['color.primary' => '#0f766e'],
            'dark' => ['color.primary' => '#5eead4'],
            'styles' => ['h1' => ['fontSize' => 'token:text.5xl', 'letterSpacing' => '-0.04em']],
            'buttons' => ['primary' => ['base' => ['backgroundColor' => 'token:color.primary'], 'hover' => ['opacity' => 0.9]]],
            'transition' => ['type' => 'slide', 'duration' => 0.4],
        ], \Cms\Abilities\AbilityContext::system('test'));

        $design = $this->app->make(\Cms\Site\Design\Design::class);
        $design->forget();
        $css = (string) file_get_contents(public_path(ltrim($design->stylesheet(), '/')));

        $this->assertFileExists(content_path('design/tokens.json'));
        $this->assertStringContainsString('--color-primary:#0f766e', $css);
        $this->assertStringContainsString('[data-theme=dark]{', $css);
        $this->assertStringContainsString('h1{font-size:var(--text-5xl);letter-spacing:-0.04em}', $css);
        $this->assertStringContainsString('.cms-button.cms-button[data-variant="primary"]{background-color:var(--color-primary)}', $css);
        $this->assertStringContainsString('@view-transition{navigation:auto}', $css);
    }

    public function test_tokens_rename_with_their_references_and_dark_values_clear(): void
    {
        $abilities = $this->cms()->abilities();
        $context = \Cms\Abilities\AbilityContext::system('test');
        $abilities->execute('design/update', ['buttons' => ['primary' => ['base' => ['backgroundColor' => 'token:color.primary']]], 'dark' => ['color.primary' => '#5eead4']], $context);
        $before = array_keys(array_filter((array) json_decode((string) file_get_contents(content_path('design/tokens.json')), true)['tokens']['color'], fn ($k) => $k[0] !== '$', ARRAY_FILTER_USE_KEY));

        $preview = $abilities->execute('design/preview', ['tokens' => ['color.primary' => '#123456'], 'css' => '.x{color:red}'], $context);
        $this->assertStringContainsString('--color-primary:#123456', $preview['css']);
        $this->assertStringContainsString('.x{color:red}', $preview['css']);

        $abilities->execute('design/update', ['rename' => ['color.primary' => 'color.brand'], 'dark' => ['color.brand' => null]], $context);

        $json = json_decode((string) file_get_contents(content_path('design/tokens.json')), true);
        $after = array_keys(array_filter($json['tokens']['color'], fn ($k) => $k[0] !== '$', ARRAY_FILTER_USE_KEY));
        $this->assertSame(array_search('primary', $before, true), array_search('brand', $after, true));
        $this->assertArrayNotHasKey('$extensions', $json['tokens']['color']['brand']);
        $this->assertSame('token:color.brand', $json['buttons']['primary']['base']['backgroundColor']);
    }

    public function test_rendered_preview_uses_the_unsaved_editorial_snapshot_without_persisting_it(): void
    {
        $actor = $this->admin(['name' => 'Original credit']);
        $credit = $this->admin(['name' => 'Preview credit']);
        $entry = $this->cms()->content()->create('pages', ['title' => 'Saved title', 'authorId' => $actor->id, 'data' => ['content' => []]]);
        $version = $entry->version();
        $snapshot = ['title' => 'Unsaved title', 'slug' => 'unsaved-slug', 'authorId' => $credit->id, 'data' => ['content' => [
            ['type' => 'heading', 'bind' => ['text' => 'entry.title']],
            ['type' => 'paragraph', 'bind' => ['text' => 'entry.author.name']],
            ['type' => 'paragraph', 'bind' => ['text' => 'entry.slug']],
        ]], 'play' => true];
        $response = $this->actingAs($actor)->postJson('/__preview/'.strtolower($entry->id), $snapshot)->assertOk();
        $response->assertSee('Unsaved title')->assertSee('Preview credit')->assertSee('unsaved-slug');
        $response->assertDontSee('data-cms-node=', false);
        $this->assertSame($version, $this->cms()->entries()->find($entry->id)->version());
        $this->assertSame($actor->id, $this->cms()->entries()->find($entry->id)->authorId);

        $snapshot['authorId'] = null;
        $this->postJson('/__preview/'.strtolower($entry->id), $snapshot)->assertOk()->assertDontSee('Preview credit');
        $snapshot['authorId'] = 'nonexistent';
        $this->postJson('/__preview/'.strtolower($entry->id), $snapshot)->assertStatus(422);
    }

    public function test_an_update_rebuilds_the_static_site(): void
    {
        $this->cms()->content()->create('pages', ['title' => 'About', 'slug' => 'about', 'status' => 'published', 'data' => ['content' => []]]);
        $builder = app(\Cms\Site\Build\SiteBuilder::class);
        $builder->buildAll();

        $this->assertFalse($builder->hasWork());

        // A new runtime (or CMS, or plugin version): every built page names files that are gone.
        $this->cms()->hooks()->addFilter('site.build.signature', fn (array $parts) => [...$parts, 'runtime/cms-new.js']);

        $this->assertTrue($builder->hasWork());
        $this->assertSame(\Cms\Support\Brand::get().' or a plugin was updated', $builder->status()['message']);

        while ($builder->hasWork()) {
            $builder->process(10_000);
        }

        $this->assertFalse($builder->hasWork());
    }
}
