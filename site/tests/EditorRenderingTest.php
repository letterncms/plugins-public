<?php

declare(strict_types=1);

namespace Cms\Site\Tests;

use DOMDocument;
use DOMXPath;
use Cms\Tests\TestCase;

/** Editor annotations describe source provenance, never coincidental DOM ids. */
final class EditorRenderingTest extends TestCase
{
    protected function plugins(): array
    {
        return ['letterncms/blocks', 'letterncms/site'];
    }

    protected function collections(): array
    {
        return ['pages' => ['title' => 'Pages', 'route' => '/{slug}', 'template' => 'page', 'body' => 'content', 'fields' => [
            ['handle' => 'content', 'type' => 'blocks'], ['handle' => 'aside', 'type' => 'blocks'], ['handle' => 'summary', 'type' => 'text'],
        ]]];
    }

    private function xpath(string $html): DOMXPath
    {
        $dom = new DOMDocument;
        @$dom->loadHTML($html, LIBXML_NONET);

        return new DOMXPath($dom);
    }

    private function template(array $blocks): \Cms\Content\Entries\Entry
    {
        return $this->cms()->content()->create('templates', ['title' => 'Page', 'slug' => 'page', 'status' => 'published', 'data' => ['blocks' => $blocks]]);
    }

    public function test_only_the_active_body_has_source_markers_even_when_template_ids_collide(): void
    {
        $this->template([
            ['id' => 'collision', 'type' => 'paragraph', 'props' => ['text' => 'Template chrome']],
            ['type' => 'outlet'], ['type' => 'outlet'],
        ]);
        $entry = $this->cms()->content()->create('pages', ['title' => 'Example', 'data' => ['content' => [
            ['id' => 'collision', 'type' => 'paragraph', 'props' => ['text' => 'Editable body']],
        ]]]);
        $version = $entry->version();
        $response = $this->actingAs($this->admin())->postJson('/__preview/'.$entry->id)->assertOk();
        $document = $response->headers->get('X-Cms-Editor-Document');
        $this->assertMatchesRegularExpression('/^[a-f0-9]{32}$/', $document);
        $xpath = $this->xpath($response->getContent());
        $source = $xpath->query('//*[@data-cms-node and not(ancestor::template)]');
        $this->assertCount(1, $source);
        $this->assertSame('Editable body', $source->item(0)->textContent);
        $this->assertSame($document, $source->item(0)->getAttribute('data-cms-document'));
        $this->assertSame('text', $source->item(0)->getAttribute('data-cms-prop'));
        $this->assertCount(1, $xpath->query('//*[@data-cms-editor-root="'.$document.'"]'));
        $this->assertSame($version, $this->cms()->entries()->find($entry->id)->version());
        $second = $this->postJson('/__preview/'.$entry->id)->assertOk();
        $this->assertNotSame($document, $second->headers->get('X-Cms-Editor-Document'), 'each response has an independent source scope');

        $this->postJson('/__preview/'.$entry->id, ['play' => true])->assertOk()->assertHeaderMissing('X-Cms-Editor-Document')->assertDontSee('data-cms-node=', false)->assertDontSee('data-cms-prototype=', false);
    }

    public function test_component_and_repeat_expansions_never_borrow_the_source_identity(): void
    {
        $this->cms()->content()->create('components', ['title' => 'Card', 'slug' => 'card', 'status' => 'published', 'data' => ['synced' => true, 'blocks' => [
            ['id' => 'collision', 'type' => 'paragraph', 'props' => ['text' => 'Component text']],
        ]]]);
        $entry = $this->cms()->content()->create('pages', ['title' => 'Example', 'data' => ['content' => [
            ['id' => 'collision', 'type' => 'pattern', 'props' => ['ref' => 'card']],
            ['id' => 'repeat-source', 'type' => 'repeat', 'props' => ['source' => 'list', 'path' => 'entry.missing'], 'children' => [
                ['id' => 'child-source', 'type' => 'paragraph', 'props' => ['text' => 'Repeated item']],
            ]],
            ['id' => 'after-source', 'type' => 'paragraph', 'props' => ['text' => 'After expansion']],
        ]]]);
        $response = $this->actingAs($this->admin())->postJson('/__preview/'.$entry->id)->assertOk();
        $xpath = $this->xpath($response->getContent());
        $this->assertCount(3, $xpath->query('//*[@data-cms-node and not(ancestor::template)]'));
        $this->assertCount(0, $xpath->query('//*[@data-cms-node="child-source"]'));
        $this->assertCount(0, $xpath->query('//p[text()="Component text"][@data-cms-node or @data-cms-prop]'));
        $this->assertCount(3, $xpath->query('//p[text()="Repeated item"]'));
        $this->assertCount(1, $xpath->query('//p[text()="After expansion"][@data-cms-prop="text"]'), 'source scope resumes after generated content');
    }

    public function test_template_editing_marks_its_source_and_keeps_sample_body_read_only(): void
    {
        $template = $this->template([
            ['id' => 'collision', 'type' => 'paragraph', 'props' => ['text' => 'Template source']], ['type' => 'outlet'],
        ]);
        $sample = $this->cms()->content()->create('pages', ['title' => 'Sample', 'status' => 'published', 'data' => ['content' => [
            ['id' => 'collision', 'type' => 'paragraph', 'props' => ['text' => 'Sample body']],
        ]]]);
        $response = $this->actingAs($this->admin())->postJson('/__preview/'.$template->id, ['sample' => $sample->id])->assertOk();
        $xpath = $this->xpath($response->getContent());
        $this->assertCount(2, $xpath->query('//*[@data-cms-node and not(ancestor::template)]'));
        $this->assertCount(1, $xpath->query('//p[text()="Template source"][@data-cms-prop="text"]'));
        $this->assertCount(0, $xpath->query('//p[text()="Sample body"][@data-cms-node or @data-cms-prop]'));
        $this->assertCount(1, $xpath->query('//*[@data-cms-editor-root]'));
    }

    public function test_unsaved_entries_and_other_blocks_fields_use_the_same_renderer_without_saving(): void
    {
        $this->template([['type' => 'heading', 'bind' => ['text' => 'entry.title']], ['type' => 'outlet']]);
        $count = $this->cms()->entries()->query()->collection('pages')->count();
        $response = $this->actingAs($this->admin())->postJson('/__preview', ['collection' => 'pages', 'title' => 'Unsaved title', 'slug' => 'unsaved', 'data' => [
            'content' => [['id' => 'unsaved-source', 'type' => 'paragraph', 'props' => ['text' => 'Unsaved body']]],
            'aside' => [['id' => 'aside-source', 'type' => 'paragraph', 'bind' => ['text' => 'entry.title']]],
        ]])->assertOk()->assertSee('Unsaved title')->assertSee('Unsaved body');
        $this->assertSame($count, $this->cms()->entries()->query()->collection('pages')->count());
        $this->assertCount(1, $this->xpath($response->getContent())->query('//*[@data-cms-node="unsaved-source"]'));
        $this->postJson('/__preview', ['collection' => 'pages', 'field' => 'aside', 'title' => 'Field scope', 'data' => [
            'content' => [['type' => 'paragraph', 'props' => ['text' => 'Body excluded']]],
            'aside' => [['id' => 'aside-source', 'type' => 'paragraph', 'bind' => ['text' => 'entry.title']]],
        ]])->assertOk()->assertSee('Field scope')->assertDontSee('Body excluded');
        $this->postJson('/__preview', ['collection' => 'pages', 'field' => 'summary'])->assertStatus(422);
        $this->postJson('/__preview', ['collection' => 'pages', 'locale' => 'invalid'])->assertStatus(422);
        $this->postJson('/__preview', ['collection' => 'missing'])->assertNotFound();
        $this->assertSame($count, $this->cms()->entries()->query()->collection('pages')->count());
    }

    public function test_empty_documents_keep_an_insertion_root_and_inert_normalized_text_prototypes(): void
    {
        $entry = $this->cms()->content()->create('pages', ['title' => 'Empty', 'data' => ['content' => []]]);
        $response = $this->actingAs($this->admin())->postJson('/__preview/'.$entry->id)->assertOk();
        $xpath = $this->xpath($response->getContent());
        $this->assertCount(1, $xpath->query('//*[@data-cms-editor-root]'));
        $this->assertCount(0, $xpath->query('//*[@data-cms-node and not(ancestor::template)]'));
        $this->assertCount(1, $xpath->query('//*[@data-cms-editor-root]/div[@data-cms-editor-empty-root]'));
        foreach (['paragraph' => 'text', 'heading' => 'text', 'list' => 'items', 'quote' => 'text', 'callout' => 'text', 'code' => 'code'] as $type => $prop) {
            $this->assertCount(1, $xpath->query('//template[@data-cms-prototype="'.$type.'"]//*[@data-cms-node="__prototype__"]'));
            $this->assertCount(1, $xpath->query('//template[@data-cms-prototype="'.$type.'"]//*[@data-cms-prop="'.$prop.'"]'));
        }
        $this->assertCount(1, $xpath->query('//template[@data-cms-prototype="list"]//li[@data-cms-index="0"]'));
    }

    public function test_inline_slots_exist_only_for_literal_source_props(): void
    {
        $entry = $this->cms()->content()->create('pages', ['title' => 'Binding', 'data' => ['content' => [
            ['id' => 'literal', 'type' => 'paragraph', 'props' => ['text' => 'Literal']],
            ['id' => 'bound', 'type' => 'paragraph', 'bind' => ['text' => 'entry.title']],
            ['id' => 'leaf-link', 'type' => 'link', 'props' => ['label' => 'Link']],
            ['id' => 'parent-link', 'type' => 'link', 'children' => [['id' => 'child-label', 'type' => 'paragraph', 'props' => ['text' => 'Child label']]]],
            ['id' => 'literal-list', 'type' => 'list', 'props' => ['items' => ['One', 'Two']]],
            ['id' => 'source-button', 'type' => 'button', 'props' => ['label' => 'Button']],
            ['id' => 'source-quote', 'type' => 'quote', 'props' => ['text' => 'Quote', 'cite' => 'Credit']],
            ['id' => 'source-callout', 'type' => 'callout', 'props' => ['text' => 'Callout']],
            ['id' => 'source-code', 'type' => 'code', 'props' => ['code' => 'echo 1;']],
            ['id' => 'source-image', 'type' => 'image', 'props' => ['image' => 'https://example.com/photo.jpg', 'caption' => 'Picture']],
        ]]]);
        $response = $this->actingAs($this->admin())->postJson('/__preview/'.$entry->id)->assertOk();
        $xpath = $this->xpath($response->getContent());
        $this->assertCount(1, $xpath->query('//*[@data-cms-node="literal"][@data-cms-prop="text"]'));
        $this->assertCount(0, $xpath->query('//*[@data-cms-node="bound"][@data-cms-prop]'));
        $this->assertCount(2, $xpath->query('//*[@data-cms-node="literal-list"]/li[@data-cms-prop="items"][@data-cms-index]'));
        $this->assertCount(1, $xpath->query('//*[@data-cms-node="leaf-link"][@data-cms-prop="label"]'));
        $this->assertCount(0, $xpath->query('//*[@data-cms-node="parent-link"][@data-cms-prop]'));
        $this->assertCount(1, $xpath->query('//*[@data-cms-node="child-label"][@data-cms-prop="text"]'));
        foreach (['source-button' => 'label', 'source-quote' => 'cite', 'source-callout' => 'text', 'source-code' => 'code', 'source-image' => 'caption'] as $id => $prop) {
            $this->assertCount(1, $xpath->query('//*[@data-cms-node="'.$id.'"]//*[@data-cms-prop="'.$prop.'"]'), $id);
        }
    }

    public function test_optional_attribution_and_captions_keep_the_public_layout_when_empty(): void
    {
        $nodes = [];
        foreach ([
            'quote' => ['text' => 'A quotation'],
            'image' => ['image' => 'https://example.com/photo.jpg'],
            'video' => ['url' => '/movie.mp4'],
        ] as $type => $props) {
            $prop = $type === 'quote' ? 'cite' : 'caption';
            $nodes[] = ['id' => 'empty-'.$type, 'type' => $type, 'props' => [...$props, $prop => '']];
            $nodes[] = ['id' => 'filled-'.$type, 'type' => $type, 'props' => [...$props, $prop => 'Visible attribution']];
        }
        $entry = $this->cms()->content()->create('pages', ['title' => 'Optional text', 'data' => ['content' => $nodes]]);
        $response = $this->actingAs($this->admin())->postJson('/__preview/'.$entry->id)->assertOk();
        $xpath = $this->xpath($response->getContent());
        foreach (['quote' => 'cite', 'image' => 'caption', 'video' => 'caption'] as $type => $prop) {
            $this->assertCount(0, $xpath->query('//*[@data-cms-node="empty-'.$type.'"]/figcaption'), 'empty '.$prop.' adds no editor-only line');
            $this->assertCount(0, $xpath->query('//*[@data-cms-node="empty-'.$type.'"]//*[@data-cms-prop="'.$prop.'"]'));
            $this->assertCount(1, $xpath->query('//*[@data-cms-node="filled-'.$type.'"]/figcaption[@data-cms-prop="'.$prop.'"][text()="Visible attribution"]'));
        }
        $public = $this->postJson('/__preview/'.$entry->id, ['play' => true])->assertOk();
        $this->assertCount(3, $this->xpath($public->getContent())->query('//figcaption'));
    }

    public function test_only_exact_entry_title_bindings_expose_a_metadata_target_in_editor_output(): void
    {
        $this->template([
            ['id' => 'template-title', 'type' => 'heading', 'bind' => ['text' => 'entry.title']],
            ['id' => 'filtered-title', 'type' => 'paragraph', 'bind' => ['text' => 'entry.title|upper']],
            ['id' => 'interpolated-title', 'type' => 'paragraph', 'bind' => ['text' => 'About {entry.title}']],
            ['id' => 'literal-title', 'type' => 'paragraph', 'props' => ['text' => 'Current title']],
            ['type' => 'outlet'],
        ]);
        $entry = $this->cms()->content()->create('pages', ['title' => 'Current title', 'data' => ['content' => [
            ['id' => 'bound-body-title', 'type' => 'paragraph', 'bind' => ['text' => 'entry.title']],
        ]]]);
        $response = $this->actingAs($this->admin())->postJson('/__preview/'.$entry->id)->assertOk();
        $xpath = $this->xpath($response->getContent());
        $this->assertCount(2, $xpath->query('//*[@data-cms-entry-field="title"]'));
        $this->assertCount(1, $xpath->query('//h2[text()="Current title"][@data-cms-entry-field="title"][not(@data-cms-node)]'), 'template metadata is distinct from source provenance');
        $this->assertCount(1, $xpath->query('//*[@data-cms-node="bound-body-title"][@data-cms-entry-field="title"][not(@data-cms-prop)]'));
        $this->assertCount(0, $xpath->query('//p[text()="CURRENT TITLE" or text()="About Current title"][@data-cms-entry-field]'));
        $this->postJson('/__preview/'.$entry->id, ['play' => true])->assertOk()->assertDontSee('data-cms-entry-field=', false);
        $this->postJson('/__preview', ['standalone' => true, 'data' => ['blocks' => [['type' => 'paragraph', 'bind' => ['text' => 'entry.title']]]]])->assertOk()->assertDontSee('data-cms-entry-field=', false);
    }

    public function test_standalone_schema_fields_render_for_admin_read_only_users_without_persistence(): void
    {
        $this->cms()->settingsRegistry()->save('roles', ['roles' => ['inspector' => ['label' => 'Inspector', 'permissions' => ['admin.access']]]]);
        $entry = $this->cms()->content()->create('pages', ['title' => 'Unchanged']);
        $version = $entry->version();
        $count = $this->cms()->entries()->query()->collection('pages')->count();
        $snapshot = ['standalone' => true, 'field' => 'content', 'title' => 'Shared field', 'data' => ['content' => [
            ['id' => 'standalone-text', 'type' => 'paragraph', 'props' => ['text' => 'Literal <script>bad()</script><strong>safe</strong>']],
            ['type' => 'paragraph', 'props' => ['text' => 'Generated identity']],
        ]]];
        $response = $this->actingAs($this->userWithRole('inspector'))->postJson('/__preview', $snapshot)->assertOk()->assertSee('Shared field')->assertSee('<strong>safe</strong>', false)->assertDontSee('<script>bad()', false);
        $document = $response->headers->get('X-Cms-Editor-Document');
        $this->assertMatchesRegularExpression('/^[a-f0-9]{32}$/', $document);
        $xpath = $this->xpath($response->getContent());
        $this->assertCount(1, $xpath->query('//*[@data-cms-editor-root="'.$document.'"]'));
        $this->assertCount(2, $xpath->query('//*[@data-cms-node and not(ancestor::template)]'));
        $this->assertCount(1, $xpath->query('//*[@data-cms-node="standalone-text"][@data-cms-prop="text"]'));
        $this->assertCount(6, $xpath->query('//template[@data-cms-prototype]'));
        $this->postJson('/__preview', [...$snapshot, 'play' => true])->assertOk()->assertHeaderMissing('X-Cms-Editor-Document')->assertDontSee('data-cms-node=', false)->assertDontSee('data-cms-prototype=', false);
        $this->postJson('/__preview', [...$snapshot, 'field' => null])->assertStatus(422);
        $this->postJson('/__preview', [...$snapshot, 'data' => ['content' => 'invalid']])->assertStatus(422);
        $this->postJson('/__preview', [...$snapshot, 'collection' => 'pages'])->assertNotFound();
        $this->postJson('/__preview', ['standalone' => true])->assertOk();
        $this->assertSame($count, $this->cms()->entries()->query()->collection('pages')->count());
        $this->assertSame($version, $this->cms()->entries()->find($entry->id)->version());
        $this->actingAs($this->userWithRole('unprivileged'))->postJson('/__preview', $snapshot)->assertForbidden();
    }

    public function test_saved_view_only_inspection_is_allowed_but_unsaved_render_requires_authoring_permission(): void
    {
        $this->cms()->settingsRegistry()->save('roles', ['roles' => ['viewer' => ['label' => 'Viewer', 'permissions' => ['admin.access', 'entries.pages.view']]]]);
        $entry = $this->cms()->content()->create('pages', ['title' => 'Inspect', 'data' => ['content' => [['id' => 'inspect-source', 'type' => 'paragraph', 'props' => ['text' => 'Visible']]]]]);
        $version = $entry->version();
        $this->actingAs($this->userWithRole('viewer'))->postJson('/__preview/'.$entry->id)->assertOk()->assertSee('data-cms-node="inspect-source"', false);
        $this->postJson('/__preview', ['collection' => 'pages'])->assertNotFound();
        $this->assertSame($version, $this->cms()->entries()->find($entry->id)->version());
    }
}
