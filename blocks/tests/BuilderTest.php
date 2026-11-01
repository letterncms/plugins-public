<?php

declare(strict_types=1);

namespace Cms\Blocks\Tests;

use Cms\Abilities\AbilityContext;
use Cms\Blocks\BlockRenderer;
use Cms\Blocks\Document;
use Cms\Blocks\Expression;
use Cms\Blocks\Patterns;
use Cms\Blocks\Scope;
use Cms\Blocks\StyleCompiler;
use Cms\Blocks\Templates;
use Cms\Support\Yaml;
use Cms\Tests\TestCase;

/** The builder's node model: styles, bindings, Repeat, components, scripts and animations. */
final class BuilderTest extends TestCase
{
    protected function plugins(): array
    {
        return ['letterncms/blocks'];
    }

    public function test_blocks_bodies_use_the_same_collection_contract_across_structures_and_storage(): void
    {
        $abilities = $this->cms()->abilities();
        $admin = $this->admin();
        $ctx = AbilityContext::forUser($admin, 'admin');
        foreach (['database', 'files'] as $driver) {
            foreach (['list', 'tree', 'dated'] as $structure) {
                $handle = $driver.'_'.$structure;
                $definition = ['title' => 'Shared content', 'driver' => $driver, 'structure' => $structure, 'body' => 'content', 'fields' => [
                    ['handle' => 'content', 'type' => 'blocks'], ['handle' => 'category', 'type' => 'text'],
                ]];
                $saved = $abilities->execute('collections/save', ['handle' => $handle, 'definition' => $definition], $ctx);
                $this->assertArrayNotHasKey('editor', $saved);
                $this->assertArrayNotHasKey('editor', Yaml::read(content_path("collections/{$handle}.yaml")));
                $entry = $abilities->execute('entries/create', ['collection' => $handle, 'title' => 'Block content', 'data' => [
                    'content' => [['type' => 'paragraph', 'props' => ['text' => 'One shared block document.']]], 'category' => 'news',
                ]], $ctx);
                $this->assertSame('paragraph', $entry['data']['content'][0]['type']);
                $this->assertSame('news', $entry['data']['category']);
                $this->assertSame($entry['version'], $this->cms()->entries()->find($entry['id'])->version());
            }
        }
        $manifest = $this->app->make(\Cms\Admin\Manifest::class)->build($admin);
        foreach ($manifest['collections'] as $summary) {
            $this->assertArrayNotHasKey('editor', $summary);
        }
        foreach ($abilities->execute('collections/list', [], $ctx) as $summary) {
            $this->assertArrayNotHasKey('editor', $summary);
        }
    }

    public function test_field_only_collections_and_body_changes_do_not_require_an_editor_mode(): void
    {
        $abilities = $this->cms()->abilities();
        $ctx = AbilityContext::forUser($this->admin(), 'admin');
        $definition = ['title' => 'Flexible content', 'body' => 'content', 'fields' => [['handle' => 'content', 'type' => 'blocks']]];
        $abilities->execute('collections/save', ['handle' => 'flexible', 'definition' => $definition], $ctx);
        $changed = $abilities->execute('collections/save', ['handle' => 'flexible', 'definition' => [...$definition, 'fields' => [['handle' => 'content', 'type' => 'markdown']]]], $ctx);
        $this->assertSame('markdown', $changed['resolvedFields'][0]['type']);
        $fieldOnly = $abilities->execute('collections/save', ['handle' => 'flexible', 'definition' => [
            'title' => 'Structured fields', 'fields' => [['handle' => 'role', 'type' => 'text']],
        ]], $ctx);
        $this->assertArrayNotHasKey('body', $fieldOnly);
        $this->assertArrayNotHasKey('editor', $fieldOnly);
        $entry = $abilities->execute('entries/create', ['collection' => 'flexible', 'title' => 'A person', 'data' => ['role' => 'Writer']], $ctx);
        $this->assertSame('Writer', $entry['data']['role']);
    }

    private function render(array $nodes, array $scope = []): string
    {
        $renderer = app(BlockRenderer::class);
        $renderer->begin();

        return $renderer->withEditorDocument($renderer->editor ? 'test-source' : null, fn () => $renderer->render(app(Document::class)->normalize($nodes), new Scope($scope)));
    }

    private function css(callable $render): string
    {
        $css = [];
        $this->cms()->hooks()->on('render.style', function ($class, $rule) use (&$css) {
            $css[$class] = $rule;
        });
        $render();

        return implode('', $css);
    }

    public function test_styles_take_any_css_property_with_variants_and_custom_states(): void
    {
        $style = app(StyleCompiler::class)->sanitize([
            'base' => ['gridTemplateColumns' => 'repeat(3, 1fr)', 'backdropFilter' => 'blur(12px)', 'boxShadow' => '0 8px 24px token:color.primary/20', 'nope' => 'x', 'color' => 'red;}body{'],
            'mobile:hover' => ['opacity' => 0.8],
            'is-open' => ['rotate' => '45deg'],
            'in-open' => ['opacity' => 1],
            'before' => ['position' => 'absolute'],
            'evil:hover' => ['color' => 'red'],
        ]);

        $this->assertSame(['gridTemplateColumns', 'backdropFilter', 'boxShadow'], array_keys($style['base']));
        $this->assertArrayNotHasKey('evil:hover', $style);

        $css = $this->css(fn () => app(StyleCompiler::class)->classes($style));
        $this->assertStringContainsString('grid-template-columns:repeat(3, 1fr)', $css);
        $this->assertStringContainsString('-webkit-backdrop-filter:blur(12px);backdrop-filter:blur(12px)', $css);
        $this->assertStringContainsString('box-shadow:0 8px 24px color-mix(in srgb, var(--color-primary) 20%, transparent)', $css);
        $this->assertMatchesRegularExpression('/@media \(max-width:767\.98px\)\{@media \(hover:hover\)\{\.s\w+:hover\{opacity:0\.8\}\}\}/', $css);
        $this->assertMatchesRegularExpression('/\.s\w+\.is-open\{rotate:45deg\}/', $css);
        $this->assertMatchesRegularExpression('/\.is-open \.s\w+\{opacity:1\}/', $css);
        $this->assertMatchesRegularExpression('/\.p\w+::before\{content:""\}/', $css, 'pseudo-elements get content');
    }

    public function test_bindings_fill_props_states_attributes_and_visibility_from_the_scope(): void
    {
        $html = $this->render([
            ['type' => 'heading', 'props' => ['level' => '1'], 'bind' => ['text' => 'entry.title|upper']],
            ['type' => 'paragraph', 'bind' => ['text' => '© {site.year} {site.name}']],
            ['type' => 'link', 'bind' => ['label' => 'item.label', 'url' => 'item.url', 'state:active' => 'item.active', 'attr:title' => 'item.label']],
            ['type' => 'paragraph', 'props' => ['text' => 'Hidden'], 'bind' => ['visible' => 'entry.missing']],
            ['type' => 'paragraph', 'bind' => ['text' => 'entry.title']],
        ], [
            'entry' => ['title' => 'Hello <b>world</b>'],
            'site' => ['year' => '2026', 'name' => 'Acme'],
            'item' => ['label' => 'About', 'url' => '/about', 'active' => true],
        ]);

        $this->assertStringContainsString('HELLO &lt;B&gt;WORLD&lt;/B&gt;', $html, 'bound text is escaped');
        $this->assertStringContainsString('>© 2026 Acme</p>', $html);
        $this->assertMatchesRegularExpression('/<a class="cms-link[^"]* is-active" title="About" href="\/about">About<\/a>/', $html);
        $this->assertStringNotContainsString('Hidden', $html);
    }

    public function test_repeat_renders_children_per_item_with_nesting(): void
    {
        $html = $this->render([
            ['type' => 'repeat', 'props' => ['source' => 'list', 'path' => 'menu', 'tag' => 'ul'], 'children' => [
                ['type' => 'box', 'props' => ['tag' => 'li'], 'children' => [
                    ['type' => 'link', 'bind' => ['label' => 'item.label', 'url' => 'item.url']],
                    ['type' => 'repeat', 'props' => ['source' => 'list', 'path' => 'item.children', 'as' => 'child', 'tag' => 'ul'], 'children' => [
                        ['type' => 'paragraph', 'props' => ['tag' => 'li'], 'bind' => ['text' => '{index}. {child.label}']],
                    ]],
                ]],
            ]],
        ], ['menu' => [
            ['label' => 'Docs', 'url' => '/docs', 'children' => [['label' => 'Install'], ['label' => 'Upgrade']]],
            ['label' => 'Blog', 'url' => '/blog', 'children' => []],
        ]]);

        $this->assertSame(2, substr_count($html, '<li class="cms-box">'));
        $this->assertStringContainsString('>1. Install</li>', $html);
        $this->assertStringContainsString('>2. Upgrade</li>', $html);
        $this->assertStringContainsString('href="/blog">Blog</a>', $html);
    }

    public function test_scripts_compile_to_alpine_attributes_and_actions(): void
    {
        $html = $this->render([
            ['type' => 'box', 'logic' => ['data' => ['open' => false], 'states' => ['open' => 'open'], 'behaviors' => ['scroll' => ['threshold' => 10]]], 'children' => [
                ['type' => 'box', 'props' => ['tag' => 'button'], 'logic' => [
                    'attrs' => ['aria-expanded' => 'open', 'onclick' => 'alert(1)'],
                    'on' => [['event' => 'click', 'mods' => ['outside', 'evil'], 'actions' => [['do' => 'toggle', 'var' => 'open'], ['do' => 'run', 'expr' => 'new Function("x")'], ['do' => 'play', 'node' => 'panel1', 'motion' => 'm1']]]],
                ]],
                ['id' => 'panel1', 'type' => 'box', 'logic' => ['show' => 'open', 'transition' => 'fade'], 'motion' => [['id' => 'm1', 'trigger' => ['type' => 'event'], 'steps' => [['target' => 'children', 'from' => ['opacity' => 0, 'y' => 12, 'evil' => 1], 'stagger' => 0.05]]]]],
            ]],
        ]);

        $this->assertStringContainsString('x-data="{&quot;open&quot;:false}"', $html);
        $this->assertStringContainsString('x-bind:class="{ &#039;is-open&#039;: (open) }"', $html);
        $this->assertStringContainsString('x-cms-scroll=', $html);
        $this->assertStringContainsString('x-on:click.outside="$cms.run($el, $event, &#039;0&#039;)"', $html);
        $this->assertStringContainsString('x-bind:aria-expanded="open"', $html);
        $this->assertStringNotContainsString('onclick', $html);
        $this->assertStringNotContainsString('new Function', $html, 'unsafe expressions are dropped');
        $this->assertStringContainsString('x-show="open" x-cloak x-transition.opacity.duration.200ms', $html);
        $this->assertStringContainsString('data-cms="panel1"', $html, 'a node that is played gets a reference');
        $this->assertStringContainsString('&quot;opacity&quot;:0', $html);
        $this->assertStringContainsString('translateY(12px)', $html, 'unknown animation properties are dropped');
        $this->assertStringNotContainsString('evil', $html);
        $this->assertSame(['logic' => true, 'motion' => true], app(BlockRenderer::class)->features);
    }

    public function test_the_canvas_shows_conditional_elements_as_they_first_appear(): void
    {
        $this->assertFalse(Expression::evaluate('open', ['open' => false]));
        $this->assertTrue(Expression::evaluate("!open && tab === 'a'", ['open' => false, 'tab' => 'a']));
        $this->assertNull(Expression::evaluate('items.filter(x)', ['items' => []]));

        $renderer = app(BlockRenderer::class);
        $renderer->editor = true;
        $html = $this->render([
            ['id' => 'root1', 'type' => 'box', 'logic' => ['data' => ['open' => false]], 'children' => [
                ['id' => 'shown', 'type' => 'paragraph', 'props' => ['text' => 'Closed'], 'logic' => ['show' => '!open']],
                ['id' => 'hidden', 'type' => 'paragraph', 'props' => ['text' => 'Open'], 'logic' => ['show' => 'open']],
            ]],
        ]);
        $renderer->editor = false;

        $this->assertMatchesRegularExpression('/data-cms-node="hidden"[^>]*data-cms-conditional="hidden"/', $html);
        $this->assertDoesNotMatchRegularExpression('/data-cms-node="shown"[^>]*data-cms-conditional/', $html);
        $this->assertStringNotContainsString('x-cloak', $html, 'the canvas does not hide with Alpine');
    }

    public function test_components_and_templates_are_entries_with_drafts(): void
    {
        $this->assertTrue($this->cms()->collections()->find('components')->system);
        $this->assertSame('blocks', $this->cms()->collections()->find('templates')->body);
        $this->assertArrayNotHasKey('editor', $this->cms()->collections()->find('templates')->toArray());

        $card = $this->cms()->content()->create('components', ['title' => 'Card', 'slug' => 'card', 'status' => 'published', 'data' => [
            'synced' => true,
            'props' => [['handle' => 'title', 'type' => 'text', 'label' => 'Title', 'default' => 'Default title']],
            'blocks' => [['type' => 'heading', 'props' => ['level' => '3'], 'bind' => ['text' => 'props.title']]],
        ]]);

        $html = $this->render([
            ['type' => 'pattern', 'props' => ['ref' => 'card', 'values' => ['title' => 'Fast']]],
            ['type' => 'pattern', 'props' => ['ref' => 'card']],
            ['type' => 'pattern', 'props' => ['ref' => 'card'], 'bind' => ['values.title' => 'entry.title']],
        ], ['entry' => ['title' => 'From data']]);

        $this->assertStringContainsString('>Fast</h3>', $html);
        $this->assertStringContainsString('>Default title</h3>', $html);
        $this->assertStringContainsString('>From data</h3>', $html);
        $this->assertFileExists(content_path('collections/components/card.md'));

        // A draft is visible to previews only.
        $this->cms()->content()->update($card->id, ['data' => ['blocks' => [['type' => 'heading', 'props' => ['text' => 'Draft', 'level' => '3']]]]]);
        app(Patterns::class)->flush();
        $this->assertStringContainsString('>Fast</h3>', $this->render([['type' => 'pattern', 'props' => ['ref' => 'card', 'values' => ['title' => 'Fast']]]]));
        $this->assertSame('Draft', app(Patterns::class)->find('card', true)['blocks'][0]['props']['text']);

        $this->cms()->content()->create('templates', ['title' => 'Post', 'slug' => 'post', 'status' => 'published', 'data' => ['collections' => ['pages'], 'blocks' => [['type' => 'outlet']]]]);
        $this->assertSame('post', app(Templates::class)->resolve(['missing', 'post'])['handle']);
        $this->assertSame(['post' => 'Post'], app(Templates::class)->options('pages'));
    }

    public function test_agents_cannot_smuggle_scripts_through_attributes_or_bindings(): void
    {
        $nodes = app(Document::class)->normalize([[
            'type' => 'box',
            'attrs' => ['onclick' => 'x()', 'style' => 'color:red', 'aria-label' => 'Menu <b>x</b>', 'data-cms-node' => 'fake', 'data-cms' => 'fake', 'data-key' => 'mine'],
            'bind' => ['onclick' => 'entry.title', 'attr:onload' => 'entry.title', 'style:color' => 'entry.color', 'text' => 'entry.title; alert(1)'],
        ]]);

        $this->assertSame(['aria-label' => 'Menu x', 'data-key' => 'mine'], $nodes[0]['attrs']);
        $this->assertSame(['style:color' => 'entry.color'], $nodes[0]['bind']);
    }

    public function test_bound_links_only_take_safe_schemes(): void
    {
        // From data: a field holding a javascript: URL is dropped, a normal one kept.
        $html = $this->render([
            ['type' => 'box', 'bind' => ['attr:href' => 'evil']],
            ['type' => 'box', 'bind' => ['attr:href' => 'good']],
        ], ['evil' => ' JavaScript:alert(1)', 'good' => '/pricing']);

        $this->assertStringNotContainsString('alert', $html);
        $this->assertStringContainsString('href="/pricing"', $html);

        // From scripts: the expression goes to the runtime's check instead of straight into x-bind; data-cms-* stays the runtime's own.
        $html = $this->render([['type' => 'box', 'logic' => ['attrs' => ['href' => "x) || ('javascript:alert(1)'", 'data-cms-on' => "'{}'", 'data-tab' => 'tab']]]]);

        $this->assertStringContainsString('x-bind:href="$cms.url($el, &#039;href&#039;)"', $html);
        $this->assertStringContainsString('data-cms-href="x) || (&#039;javascript:alert(1)&#039;"', $html);
        $this->assertStringNotContainsString('x-bind:data-cms-on', $html);
        $this->assertStringContainsString('x-bind:data-tab="tab"', $html);
    }
}
