<?php

declare(strict_types=1);

namespace Cms\Blocks\Tests;

use Cms\Blocks\BlockRenderer;
use Cms\Blocks\Document;
use Cms\Blocks\Scope;
use Cms\Tests\TestCase;

final class InteractiveBlocksTest extends TestCase
{
    protected function plugins(): array
    {
        return ['letterncms/blocks'];
    }

    private function render(array $nodes, array $scope = []): string
    {
        $renderer = app(BlockRenderer::class);
        $renderer->begin();

        return $renderer->render(app(Document::class)->normalize($nodes), new Scope($scope));
    }

    private function panel(string $label, string $text): array
    {
        return ['type' => 'tab-panel', 'props' => ['label' => $label], 'children' => [['type' => 'paragraph', 'props' => ['text' => $text]]]];
    }

    public function test_tabs_are_the_aria_pattern_with_every_panel_in_the_html(): void
    {
        $html = $this->render([['type' => 'tabs', 'props' => ['label' => 'Install', 'default' => 2], 'children' => [$this->panel('Local', 'On your laptop'), $this->panel('Hosted', 'On a server')]]]);

        $this->assertStringContainsString('role="tablist" aria-label="Install"', $html);
        $this->assertMatchesRegularExpression('/<button type="button" role="tab" class="cms-tabs__tab" id="cms-t1-t0" aria-controls="cms-t1-p0" aria-selected="false" tabindex="-1">Local<\/button>/', $html);
        $this->assertMatchesRegularExpression('/id="cms-t1-t1" aria-controls="cms-t1-p1" aria-selected="true" tabindex="0">Hosted/', $html);
        $this->assertMatchesRegularExpression('/role="tabpanel" id="cms-t1-p0" aria-labelledby="cms-t1-t0" tabindex="0" hidden="until-found"/', $html);
        $this->assertDoesNotMatchRegularExpression('/id="cms-t1-p1"[^>]*hidden/', $html);
        $this->assertStringContainsString('On your laptop', $html);
        $this->assertStringContainsString('On a server', $html);
        $this->assertStringContainsString('cms-tabs__label', $html, 'labels for when there is no script to show the tabs');
        $this->assertTrue(app(BlockRenderer::class)->features['tabs']);
    }

    public function test_an_accordion_can_look_like_cards(): void
    {
        $items = [['question' => 'Free?', 'answer' => 'Yes.']];

        $this->assertStringNotContainsString('data-look', $this->render([['type' => 'accordion', 'props' => ['items' => $items]]]));
        $this->assertStringContainsString('data-look="cards"', $this->render([['type' => 'accordion', 'props' => ['items' => $items, 'look' => 'cards']]]));
    }

    public function test_tabs_edge_cases(): void
    {
        $one = $this->render([['type' => 'tabs', 'children' => [$this->panel('Only', 'Just this')]]]);
        $this->assertStringNotContainsString('tablist', $one);
        $this->assertStringContainsString('Just this', $one);

        $this->assertSame('', $this->render([['type' => 'tabs', 'children' => []]]));

        $range = $this->render([['type' => 'tabs', 'props' => ['default' => 9], 'children' => [$this->panel('A', 'a'), $this->panel('B', 'b')]]]);
        $this->assertStringContainsString('aria-controls="cms-t1-p1" aria-selected="true"', $range, 'out of range falls back to the nearest');

        $twice = $this->render([
            ['type' => 'tabs', 'children' => [$this->panel('A', 'a'), $this->panel('B', 'b')]],
            ['type' => 'tabs', 'children' => [$this->panel('C', 'c'), $this->panel('D', 'd')]],
        ]);
        $this->assertStringContainsString('id="cms-t1-p0"', $twice);
        $this->assertStringContainsString('id="cms-t2-p0"', $twice);
    }

    public function test_the_canvas_shows_every_panel_to_select(): void
    {
        $renderer = app(BlockRenderer::class);
        $renderer->editor = true;
        $html = $this->render([['type' => 'tabs', 'children' => [$this->panel('A', 'a'), $this->panel('B', 'b')]]]);
        $renderer->editor = false;

        $this->assertStringNotContainsString('hidden=', $html);
        $this->assertStringNotContainsString('role="tablist"', $html);
    }

    private function catalog(array $repeat = []): array
    {
        $this->cms()->hooks()->addFilter('blocks.data-sources', static fn (array $sources) => [...$sources, 'fruit' => ['label' => 'Fruit', 'resolve' => static fn () => [
            ['title' => 'Crème Brûlée', 'category' => 'Dessert', 'tags' => ['sweet', 'french']],
            ['title' => 'Apple', 'category' => 'Fruit', 'tags' => ['sweet']],
            ['title' => 'Lemon', 'category' => 'Fruit', 'tags' => []],
        ]]]);

        return ['type' => 'list-filter', 'children' => [
            ['type' => 'filter-search', 'props' => ['label' => 'Find', 'placeholder' => 'Type']],
            ['type' => 'filter-choice', 'props' => ['facet' => 'category', 'label' => 'Kind', 'all_label' => 'Everything']],
            ['type' => 'filter-choice', 'props' => ['facet' => 'tags', 'label' => 'Taste']],
            ['type' => 'repeat', 'props' => ['source' => 'fruit', 'tag' => 'ul', 'filter_by' => 'item.category, item.tags', 'search_in' => 'item.title', ...$repeat], 'children' => [['type' => 'paragraph', 'bind' => ['text' => 'item.title']]]],
            ['type' => 'filter-empty', 'children' => [['type' => 'paragraph', 'props' => ['text' => 'Nothing here']]]],
        ]];
    }

    public function test_a_filter_lists_options_with_counts_and_marks_every_item(): void
    {
        $html = $this->render([$this->catalog()]);

        $this->assertStringContainsString('data-cms-filter ', $html);
        $this->assertStringContainsString('<input type="radio" name="category" value="" checked><span>Everything</span> <span class="cms-filter__count">3</span>', $html);
        $this->assertStringContainsString('value="fruit"><span>Fruit</span> <span class="cms-filter__count">2</span>', $html);
        $this->assertStringContainsString('value="sweet"><span>sweet</span> <span class="cms-filter__count">2</span>', $html);
        $this->assertStringContainsString('<li data-cms-fi data-cms-f-category="dessert" data-cms-f-tags="sweet french" data-cms-q="creme brulee" style="display:contents">', $html);
        $this->assertStringContainsString('data-cms-f-tags="" data-cms-q="lemon"', $html, 'an item without a value shows only under All');
        $this->assertMatchesRegularExpression('/data-cms-filter-empty hidden/', $html);
        $this->assertStringContainsString('role="status"', $html);
        $this->assertTrue(app(BlockRenderer::class)->features['filter']);
    }

    public function test_a_sort_offers_its_orders_and_each_item_carries_what_it_compares(): void
    {
        $filter = $this->catalog(['sort_by' => 'item.title']);
        $filter['children'][] = ['type' => 'filter-sort', 'props' => ['label' => 'Order', 'options' => [['label' => 'As listed', 'field' => ''], ['label' => 'Name', 'field' => 'item.title', 'direction' => 'desc']]]];
        $html = $this->render([$filter]);

        $this->assertStringContainsString('<option value="" data-field="" data-direction="asc" selected>As listed</option>', $html);
        $this->assertStringContainsString('<option value="name" data-field="title" data-direction="desc">Name</option>', $html);
        $this->assertStringContainsString('data-cms-s-title="Apple"', $html);
    }

    public function test_a_choice_can_be_a_list_rather_than_chips(): void
    {
        $filter = $this->catalog();
        $filter['children'][1]['props']['look'] = 'list';

        $this->assertStringContainsString('data-look="list"', $this->render([$filter]));
    }

    public function test_a_choice_without_values_does_not_show_and_a_plain_repeat_is_unchanged(): void
    {
        $html = $this->render([$this->catalog(['filter_by' => 'item.category'])]);
        $this->assertStringNotContainsString('name="tags"', $html);

        $this->render([$this->catalog()]);
        $plain = $this->render([['type' => 'repeat', 'props' => ['source' => 'fruit'], 'children' => [['type' => 'paragraph', 'bind' => ['text' => 'item.title']]]]]);
        $this->assertStringNotContainsString('data-cms-fi', $plain);
    }

    public function test_a_table_can_mark_a_column_and_head_its_rows(): void
    {
        $rows = [['', 'Ours', 'Theirs'], ['Price', 'Free', '$10']];

        $plain = $this->render([['type' => 'table', 'props' => ['rows' => $rows]]]);
        $this->assertStringNotContainsString('data-highlight', $plain);
        $this->assertStringNotContainsString('data-look', $plain);
        $this->assertStringContainsString('<td>Price</td>', $plain);

        $marked = $this->render([['type' => 'table', 'props' => ['rows' => $rows, 'highlight' => 2, 'row_headers' => true, 'look' => 'soft']]]);
        $this->assertStringContainsString('data-look="soft" data-columns="3"', $marked);
        $this->assertStringContainsString('<th scope="col" data-highlight>Ours</th>', $marked);
        $this->assertStringContainsString('<th scope="row">Price</th>', $marked);
        $this->assertStringContainsString('<td data-highlight>Free</td>', $marked);
        $this->assertStringContainsString('<td>$10</td>', $marked);
    }

    public function test_tabs_can_look_segmented(): void
    {
        $panels = [['type' => 'tab-panel', 'props' => ['label' => 'One']], ['type' => 'tab-panel', 'props' => ['label' => 'Two']]];

        $this->assertStringNotContainsString('cms-tabs__list--segmented', $this->render([['type' => 'tabs', 'children' => $panels]]));
        $this->assertStringContainsString('cms-tabs__list--segmented', $this->render([['type' => 'tabs', 'props' => ['look' => 'segmented'], 'children' => $panels]]));
    }

    public function test_the_carousel_behavior_reaches_the_page(): void
    {
        $html = $this->render([['type' => 'box', 'logic' => ['behaviors' => ['carousel' => []]]]]);

        $this->assertStringContainsString('x-cms-carousel', $html);
    }

    public function test_tabs_can_stand_in_a_column(): void
    {
        $panels = [['type' => 'tab-panel', 'props' => ['label' => 'One']], ['type' => 'tab-panel', 'props' => ['label' => 'Two']]];

        $this->assertStringContainsString('cms-tabs__list--vertical', $this->render([['type' => 'tabs', 'props' => ['look' => 'vertical'], 'children' => $panels]]));
    }

    public function test_the_menu_behavior_reaches_the_page(): void
    {
        $html = $this->render([['type' => 'box', 'logic' => ['behaviors' => ['menu' => []]]]]);

        $this->assertStringContainsString('x-cms-menu', $html);
    }
}
