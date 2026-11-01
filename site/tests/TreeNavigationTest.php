<?php

declare(strict_types=1);

namespace Cms\Site\Tests;

use Cms\Site\Rendering\Renderer;
use Cms\Tests\TestCase;

/** Navigation built from a tree collection: sections, the current branch and groups of pages. */
final class TreeNavigationTest extends TestCase
{
    protected function plugins(): array
    {
        return ['letterncms/blocks', 'letterncms/site'];
    }

    protected function collections(): array
    {
        return [
            'guide' => ['title' => 'Guide', 'structure' => 'tree', 'route' => '/{parent_uri}/{slug}', 'template' => 'guide', 'body' => 'content', 'fields' => [['handle' => 'content', 'type' => 'blocks'], ['handle' => 'group', 'type' => 'text']]],
        ];
    }

    private function page(string $title, ?string $parent = null, ?string $group = null, int $position = 0): string
    {
        return $this->cms()->content()->create('guide', ['title' => $title, 'status' => 'published', 'parentId' => $parent, 'position' => $position, 'data' => ['group' => $group, 'content' => []]])->id;
    }

    private function render(string $id, array $blocks): string
    {
        $this->cms()->content()->create('templates', ['title' => 'Guide', 'slug' => 'guide', 'status' => 'published', 'data' => ['blocks' => $blocks]]);

        return $this->app->make(Renderer::class)->entry($this->cms()->entries()->find($id))['html'];
    }

    public function test_children_of_the_root_list_the_whole_section_from_any_depth(): void
    {
        $design = $this->page('Design');
        $styles = $this->page('Styles', $design, position: 1);
        $tokens = $this->page('Tokens', $styles);
        $this->page('Templates', $design, position: 2);
        $this->page('Other');

        $html = $this->render($tokens, [
            ['type' => 'paragraph', 'bind' => ['text' => 'Section: {entry.root.title}']],
            ['type' => 'repeat', 'props' => ['source' => 'children', 'of' => 'root'], 'children' => [['type' => 'link', 'bind' => ['label' => 'item.title', 'url' => 'item.url']]]],
        ]);

        $this->assertStringContainsString('Section: Design', $html);
        $this->assertStringContainsString('>Styles</a>', $html);
        $this->assertStringContainsString('>Templates</a>', $html);
        $this->assertStringNotContainsString('>Other</a>', $html);
    }

    public function test_a_top_level_page_is_its_own_root(): void
    {
        $design = $this->page('Design');
        $this->page('Styles', $design);

        $html = $this->render($design, [
            ['type' => 'paragraph', 'bind' => ['text' => 'Section: {entry.root.title}']],
            ['type' => 'repeat', 'props' => ['source' => 'children', 'of' => 'root'], 'children' => [['type' => 'link', 'bind' => ['label' => 'item.title', 'url' => 'item.url']]]],
        ]);

        $this->assertStringContainsString('Section: Design', $html);
        $this->assertStringContainsString('>Styles</a>', $html);
    }

    public function test_entries_know_whether_they_are_the_page_or_above_it(): void
    {
        $design = $this->page('Design');
        $styles = $this->page('Styles', $design);
        $this->page('Templates', $design);
        $this->page('Other');

        $html = $this->render($styles, [
            ['type' => 'repeat', 'props' => ['source' => 'entries', 'collection' => 'guide', 'parent' => 'any', 'sort' => 'title', 'limit' => 10], 'children' => [
                ['type' => 'box', 'props' => ['tag' => 'li'], 'bind' => ['attr:data-title' => 'item.title', 'state:active' => 'item.active', 'state:here' => 'item.current']],
            ]],
        ]);

        $this->assertMatchesRegularExpression('/<li class="[^"]*is-active[^"]*" data-title="Design"/', $html);
        $this->assertDoesNotMatchRegularExpression('/<li class="[^"]*is-here[^"]*" data-title="Design"/', $html);
        $this->assertMatchesRegularExpression('/<li class="[^"]*is-active[^"]*is-here[^"]*" data-title="Styles"/', $html);
        $this->assertDoesNotMatchRegularExpression('/<li class="[^"]*is-active[^"]*" data-title="Other"/', $html);
        $this->assertDoesNotMatchRegularExpression('/<li class="[^"]*is-active[^"]*" data-title="Templates"/', $html);
    }

    public function test_search_records_say_which_section_a_page_is_in(): void
    {
        $design = $this->page('Design');
        $this->page('Styles', $design);

        $records = array_column($this->app->make(\Cms\Site\Search\SearchIndex::class)->records(), 'section', 'title');

        $this->assertSame('', $records['Design']);
        $this->assertSame('Design', $records['Styles']);
    }

    public function test_details_can_be_opened_by_a_binding(): void
    {
        $design = $this->page('Design');

        $html = $this->render($design, [['type' => 'box', 'props' => ['tag' => 'details'], 'bind' => ['attr:open' => 'entry.active'], 'children' => [['type' => 'paragraph', 'props' => ['text' => 'Inside']]]]]);

        $this->assertMatchesRegularExpression('/<details [^>]*open="1"/', $html);
    }

    public function test_a_field_named_active_wins_over_the_navigation_state(): void
    {
        $this->cms()->collections()->find('guide');
        $id = $this->cms()->content()->create('guide', ['title' => 'Own', 'status' => 'published', 'data' => ['active' => 'yes it is', 'content' => []]])->id;

        $html = $this->render($id, [['type' => 'paragraph', 'bind' => ['text' => 'entry.active']]]);

        $this->assertStringContainsString('yes it is', $html);
    }

    public function test_a_repeat_groups_by_a_field_in_the_order_groups_first_appear(): void
    {
        $design = $this->page('Design');
        $this->page('Styles', $design, 'Foundations', 1);
        $this->page('Motion', $design, 'Behaviour', 2);
        $this->page('Tokens', $design, 'Foundations', 3);
        $this->page('Loose', $design, null, 4);

        $html = $this->render($design, [
            ['type' => 'repeat', 'props' => ['source' => 'children', 'of' => 'entry', 'group_by' => 'group', 'as' => 'group'], 'children' => [
                ['type' => 'paragraph', 'bind' => ['text' => '[{group.label}]']],
                ['type' => 'repeat', 'props' => ['source' => 'list', 'path' => 'group.items', 'as' => 'page'], 'children' => [['type' => 'paragraph', 'bind' => ['text' => 'page.title']]]],
            ]],
        ]);

        $text = trim((string) preg_replace('/\s+/', ' ', strip_tags((string) preg_replace('#</p>#', ' ', substr($html, (int) strpos($html, '<main'))))));
        $text = $text === '' ? trim((string) preg_replace('/\s+/', ' ', strip_tags((string) preg_replace('#</p>#', ' ', $html)))) : $text;

        $this->assertMatchesRegularExpression('/\[Foundations\] Styles Tokens \[Behaviour\] Motion \[\] Loose/', $text);
    }
}
