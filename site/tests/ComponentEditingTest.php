<?php

declare(strict_types=1);

namespace Cms\Site\Tests;

use DOMDocument;
use DOMXPath;
use Cms\Tests\TestCase;

/** A rendered component exposes local instance values, never its shared block definition. */
final class ComponentEditingTest extends TestCase
{
    protected function plugins(): array
    {
        return ['letterncms/blocks', 'letterncms/site'];
    }

    protected function collections(): array
    {
        return ['pages' => ['title' => 'Pages', 'route' => '/{slug}', 'body' => 'content', 'fields' => [['handle' => 'content', 'type' => 'blocks']]]];
    }

    private function makeComponent(string $handle, array $props, array $blocks): \Cms\Content\Entries\Entry
    {
        return $this->cms()->content()->create('components', ['title' => ucfirst($handle), 'slug' => $handle, 'status' => 'published', 'data' => ['synced' => true, 'props' => $props, 'blocks' => $blocks]]);
    }

    private function xpath(string $html): DOMXPath
    {
        $document = new DOMDocument;
        @$document->loadHTML($html, LIBXML_NONET);

        return new DOMXPath($document);
    }

    public function test_literal_and_default_component_properties_map_to_their_own_source_instance(): void
    {
        $component = $this->makeComponent('intro', [
            ['handle' => 'title', 'type' => 'textarea', 'default' => 'Default heading'],
            ['handle' => 'copy', 'type' => 'richtext'],
            ['handle' => 'label', 'type' => 'text'],
        ], [
            ['id' => 'definition-title', 'type' => 'heading', 'bind' => ['text' => 'props.title']],
            ['id' => 'definition-title-again', 'type' => 'paragraph', 'bind' => ['text' => 'props.title']],
            ['id' => 'definition-copy', 'type' => 'paragraph', 'bind' => ['text' => 'props.copy']],
            ['id' => 'definition-button', 'type' => 'button', 'bind' => ['label' => 'props.label']],
            ['id' => 'definition-constant', 'type' => 'paragraph', 'props' => ['text' => 'Shared constant']],
        ]);
        $version = $component->version();
        $entry = $this->cms()->content()->create('pages', ['title' => 'Page', 'data' => ['content' => [
            ['id' => 'first-instance', 'type' => 'pattern', 'props' => ['ref' => 'intro', 'values' => ['title' => 'First heading', 'copy' => '<strong>Rich copy</strong>', 'label' => 'First button']]],
            ['id' => 'second-instance', 'type' => 'pattern', 'props' => ['ref' => 'intro']],
            ['id' => 'ordinary-source', 'type' => 'paragraph', 'props' => ['text' => 'Following paragraph']],
        ]]]);
        $response = $this->actingAs($this->admin())->postJson('/__preview/'.$entry->id)->assertOk();
        $xpath = $this->xpath($response->getContent());
        $token = $response->headers->get('X-Cms-Editor-Document');
        $title = $xpath->query('//h2[text()="First heading"]')->item(0);
        $this->assertSame('first-instance', $title->getAttribute('data-cms-instance-node'));
        $this->assertSame('title', $title->getAttribute('data-cms-instance-prop'));
        $this->assertSame('textarea', $title->getAttribute('data-cms-instance-type'));
        $this->assertSame($token, $title->getAttribute('data-cms-instance-document'));
        $this->assertFalse($title->hasAttribute('data-cms-node'));
        $this->assertFalse($title->hasAttribute('data-cms-prop'));
        $this->assertSame('plain', $title->getAttribute('data-cms-instance-format'));
        $this->assertSame('First heading', $title->getAttribute('data-cms-instance-value'));
        $this->assertCount(2, $xpath->query('//*[@data-cms-instance-node="first-instance"][@data-cms-instance-prop="title"][@data-cms-instance-value="First heading"][not(@data-cms-node)]'), 'duplicate uses identify one local property without giving shared nodes source identity');
        $this->assertSame('Default heading', $xpath->query('//h2[@data-cms-instance-node="second-instance"]')->item(0)->getAttribute('data-cms-instance-value'));
        $this->assertCount(1, $xpath->query('//h2[text()="Default heading"][@data-cms-instance-node="second-instance"][@data-cms-instance-prop="title"]'), 'an inherited default can become a local value override');
        $this->assertCount(1, $xpath->query('//p[@data-cms-instance-node="first-instance"][@data-cms-instance-prop="copy"][@data-cms-instance-type="richtext"]'));
        $rich = $xpath->query('//p[@data-cms-instance-prop="copy"][@data-cms-instance-node="first-instance"]')->item(0);
        $this->assertSame('<strong>Rich copy</strong>', $rich->textContent, 'exact bindings preserve the existing plain rendered semantics');
        $this->assertSame('<strong>Rich copy</strong>', $rich->getAttribute('data-cms-instance-value'));
        $this->assertSame('plain', $rich->getAttribute('data-cms-instance-format'));
        $this->assertCount(1, $xpath->query('//span[text()="First button"][@data-cms-instance-node="first-instance"][@data-cms-instance-type="text"]'));
        $this->assertCount(0, $xpath->query('//p[text()="Shared constant"][@data-cms-instance-prop or @data-cms-prop or @data-cms-node]'));
        $this->assertCount(1, $xpath->query('//*[@data-cms-node="ordinary-source"][@data-cms-prop="text"]'), 'ordinary source editing resumes after a component');
        $this->assertSame($version, $this->cms()->entries()->find($component->id)->version());
        $this->postJson('/__preview/'.$entry->id, ['play' => true])->assertOk()->assertDontSee('data-cms-instance-', false);
    }

    public function test_computed_data_bound_undeclared_and_nontext_properties_do_not_become_instance_slots(): void
    {
        $this->makeComponent('computed', [
            ['handle' => 'title', 'type' => 'text'], ['handle' => 'count', 'type' => 'number'],
        ], [
            ['type' => 'paragraph', 'bind' => ['text' => 'props.title']],
            ['type' => 'paragraph', 'bind' => ['text' => 'props.title|upper']],
            ['type' => 'paragraph', 'bind' => ['text' => 'props.title|raw']],
            ['type' => 'paragraph', 'bind' => ['text' => 'Prefix {props.title}']],
            ['type' => 'paragraph', 'bind' => ['text' => 'props.count']],
            ['type' => 'paragraph', 'bind' => ['text' => 'props.undeclared']],
            ['type' => 'paragraph', 'bind' => ['text' => 'props.title'], 'logic' => ['text' => 'something']],
        ]);
        $entry = $this->cms()->content()->create('pages', ['title' => 'Page title', 'data' => ['content' => [
            ['id' => 'literal-instance', 'type' => 'pattern', 'props' => ['ref' => 'computed', 'values' => ['title' => 'Literal title', 'count' => 42]]],
            ['id' => 'data-instance', 'type' => 'pattern', 'props' => ['ref' => 'computed'], 'bind' => ['values.title' => 'entry.title']],
        ]]]);
        $response = $this->actingAs($this->admin())->postJson('/__preview/'.$entry->id)->assertOk();
        $xpath = $this->xpath($response->getContent());
        $this->assertCount(1, $xpath->query('//*[@data-cms-instance-prop]'));
        $this->assertCount(1, $xpath->query('//p[text()="Literal title"][@data-cms-instance-node="literal-instance"]'));
        $this->assertCount(0, $xpath->query('//*[@data-cms-instance-node="data-instance"]'));
    }

    public function test_nested_exact_forwarding_preserves_the_original_source_property_and_type(): void
    {
        $this->makeComponent('leaf', [['handle' => 'label', 'type' => 'text']], [['type' => 'paragraph', 'bind' => ['text' => 'props.label']]]);
        $this->makeComponent('middle', [['handle' => 'forwarded', 'type' => 'textarea']], [
            ['type' => 'pattern', 'props' => ['ref' => 'leaf'], 'bind' => ['values.label' => 'props.forwarded']],
        ]);
        $this->makeComponent('outer', [['handle' => 'title', 'type' => 'richtext']], [
            ['type' => 'pattern', 'props' => ['ref' => 'middle'], 'bind' => ['values.forwarded' => 'props.title']],
            ['type' => 'pattern', 'props' => ['ref' => 'leaf', 'values' => ['label' => 'Nested constant']]],
            ['type' => 'pattern', 'props' => ['ref' => 'leaf'], 'bind' => ['values.label' => 'props.title|upper']],
        ]);
        $entry = $this->cms()->content()->create('pages', ['title' => 'Page', 'data' => ['content' => [
            ['id' => 'outer-source', 'type' => 'pattern', 'props' => ['ref' => 'outer', 'values' => ['title' => 'Forwarded value']]],
        ]]]);
        $response = $this->actingAs($this->admin())->postJson('/__preview/'.$entry->id)->assertOk();
        $xpath = $this->xpath($response->getContent());
        $this->assertCount(1, $xpath->query('//*[@data-cms-instance-prop]'));
        $slot = $xpath->query('//p[text()="Forwarded value"]')->item(0);
        $this->assertSame('outer-source', $slot->getAttribute('data-cms-instance-node'));
        $this->assertSame('title', $slot->getAttribute('data-cms-instance-prop'));
        $this->assertSame('richtext', $slot->getAttribute('data-cms-instance-type'), 'the client writes using the original source field contract');
        $this->assertFalse($slot->hasAttribute('data-cms-node'));
        $this->assertCount(0, $xpath->query('//p[text()="Nested constant" or text()="FORWARDED VALUE"][@data-cms-instance-prop]'));
    }

    public function test_dynamic_component_reference_choices_refresh_after_creating_another_component(): void
    {
        $this->makeComponent('first', [], []);
        $registry = $this->app->make(\Cms\Modules\Blocks\BlockRegistry::class);
        $pattern = $registry->get('pattern');
        $before = $pattern->blueprint()->field('ref')->get('options');
        $this->assertArrayHasKey('first', $before);
        $this->makeComponent('second', [], []);
        $after = $pattern->blueprint()->field('ref')->get('options');
        $this->assertArrayHasKey('second', $after, 'a new component is immediately available within the same request or batch');
        $this->assertArrayNotHasKey('second', $before);
        $paragraph = $registry->get('paragraph');
        $this->assertSame($paragraph->blueprint(), $paragraph->blueprint(), 'static block schemas retain their cache');
    }

    public function test_repeated_and_template_instances_stay_read_only_and_cannot_leak_their_mapping(): void
    {
        $this->makeComponent('repeated', [['handle' => 'title', 'type' => 'text']], [
            ['type' => 'repeat', 'props' => ['source' => 'numbers', 'count' => 2], 'children' => [['type' => 'paragraph', 'bind' => ['text' => 'props.title']]]],
            ['type' => 'paragraph', 'bind' => ['text' => 'props.title']],
        ]);
        $this->cms()->content()->create('templates', ['title' => 'Default', 'slug' => 'default', 'status' => 'published', 'data' => ['blocks' => [
            ['type' => 'pattern', 'props' => ['ref' => 'repeated', 'values' => ['title' => 'Template value']]], ['type' => 'outlet'],
        ]]]);
        $entry = $this->cms()->content()->create('pages', ['title' => 'Page', 'data' => ['content' => [
            ['id' => 'local-instance', 'type' => 'pattern', 'props' => ['ref' => 'repeated', 'values' => ['title' => 'Local value']]],
            ['type' => 'repeat', 'props' => ['source' => 'numbers', 'count' => 2], 'children' => [
                ['type' => 'pattern', 'props' => ['ref' => 'repeated', 'values' => ['title' => 'Generated value']]],
            ]],
            ['id' => 'no-leak', 'type' => 'paragraph', 'bind' => ['text' => 'props.title']],
        ]]]);
        $response = $this->actingAs($this->admin())->postJson('/__preview/'.$entry->id)->assertOk();
        $xpath = $this->xpath($response->getContent());
        $this->assertCount(1, $xpath->query('//*[@data-cms-instance-prop]'));
        $this->assertCount(1, $xpath->query('//p[text()="Local value"][@data-cms-instance-node="local-instance"]'));
        $this->assertCount(3, $xpath->query('//p[text()="Local value"]'), 'repeat output remains visible without ambiguous editing targets');
        $this->assertCount(0, $xpath->query('//p[text()="Template value" or text()="Generated value"][@data-cms-instance-prop]'));
        $this->assertCount(0, $xpath->query('//*[@data-cms-node="no-leak"][@data-cms-instance-prop]'));
    }
}
