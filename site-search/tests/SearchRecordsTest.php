<?php

declare(strict_types=1);

namespace Cms\SiteSearch\Tests;

use Cms\Modules\Site\Search\SearchIndex;
use Cms\Tests\TestCase;

/** What the plugin adds to each page's record in /search.json: its kind, its date when dated, and its keywords. */
final class SearchRecordsTest extends TestCase
{
    protected function plugins(): array
    {
        return ['letterncms/site-search'];
    }

    protected function collections(): array
    {
        return [
            'services' => ['title' => 'Services', 'singular' => 'Service', 'route' => '/services/{slug}', 'fields' => [['handle' => 'keywords', 'type' => 'list'], ['handle' => 'description', 'type' => 'textarea']]],
            'news' => ['title' => 'News', 'structure' => 'dated', 'route' => '/news/{slug}', 'fields' => [['handle' => 'keywords', 'type' => 'text']]],
            'pages' => ['title' => 'Pages', 'singular' => 'Page', 'structure' => 'tree', 'route' => '/{parent_uri}/{slug}'],
        ];
    }

    /** @return array<string, array> the index's records by title */
    private function records(): array
    {
        return array_column(app(SearchIndex::class)->records(), null, 'title');
    }

    public function test_each_record_says_its_kind_its_date_and_its_keywords(): void
    {
        $entries = $this->cms()->entryService();
        $entries->create('services', ['title' => 'Waste collection', 'status' => 'published', 'data' => ['keywords' => ['bin', ' rubbish ', '', 'recycling'], 'description' => 'When your bins are emptied.']]);
        $entries->create('news', ['title' => 'Recycling centre opens', 'status' => 'published', 'at' => '2026-03-02T10:00:00Z', 'data' => ['keywords' => "  tip\n dump  "]]);
        $entries->create('pages', ['title' => 'About', 'status' => 'published']);

        $records = $this->records();

        $service = $records['Waste collection'];
        $this->assertSame('Service', $service['kind'], 'the collection\'s name for one of its entries');
        $this->assertSame('bin, rubbish, recycling', $service['keywords'], 'a list as one line');
        $this->assertArrayNotHasKey('date', $service, 'a list of services has no dates');
        $this->assertSame('/services/waste-collection', $service['url'], 'the core\'s record as it was');
        $this->assertSame('When your bins are emptied.', $service['description']);

        $news = $records['Recycling centre opens'];
        $this->assertSame('News', $news['kind'], 'without a singular name, the collection\'s title');
        $this->assertSame('2026-03-02', $news['date'], 'a dated collection says the day it was published');
        $this->assertSame('tip dump', $news['keywords']);

        $about = $records['About'];
        $this->assertSame('Page', $about['kind']);
        $this->assertArrayNotHasKey('keywords', $about, 'nothing where there is nothing to say');
        $this->assertArrayNotHasKey('date', $about);

        $this->assertStringContainsString('"kind":"Service"', app(SearchIndex::class)->json(), 'in /search.json');
    }

    public function test_a_page_left_out_stays_out(): void
    {
        $this->cms()->hooks()->addFilter('site.search.record', static fn (?array $record) => ($record['title'] ?? '') === 'Hidden' ? null : $record, 5);
        $entries = $this->cms()->entryService();
        $entries->create('pages', ['title' => 'Hidden', 'status' => 'published']);
        $entries->create('pages', ['title' => 'Shown', 'status' => 'published']);

        $this->assertSame(['Shown'], array_keys($this->records()));
    }
}
