<?php

declare(strict_types=1);

namespace Cms\Seo\Tests;

use Cms\Content\Entries\EntryService;
use Cms\Seo\Feeds;
use Cms\Site\Build\SiteBuilder;
use Cms\Tests\TestCase;

final class FeedsTest extends TestCase
{
    protected function plugins(): array
    {
        return ['letterncms/blocks', 'letterncms/site', 'letterncms/seo'];
    }

    private function publish(string $title, string $at = '2026-10-01T10:00:00+00:00', array $extra = []): array
    {
        return app(EntryService::class)->create('posts', ['title' => $title, 'status' => 'published', 'at' => $at, 'data' => ['excerpt' => "About {$title} & more", ...$extra]], $this->admin())->toArray();
    }

    public function test_a_dated_collection_has_a_feed_with_its_newest_entries_first(): void
    {
        $this->publish('Older', '2026-09-01T10:00:00+00:00');
        $this->publish('Newer <b>');

        $feeds = app(Feeds::class)->all();
        $this->assertSame(['blog/feed.xml'], array_keys($feeds), 'a tree of pages has no feed');

        $xml = app(Feeds::class)->render('posts', 'en');
        $doc = simplexml_load_string($xml);

        $this->assertNotFalse($doc, 'valid XML');
        $this->assertSame('2.0', (string) $doc['version']);
        $this->assertSame('Newer <b>', (string) $doc->channel->item[0]->title);
        $this->assertSame('Older', (string) $doc->channel->item[1]->title);
        $this->assertSame('http://localhost/blog/newer-b', (string) $doc->channel->item[0]->link);
        $this->assertSame('About Newer <b> & more', (string) $doc->channel->item[0]->description);
        $this->assertStringContainsString('01 Oct 2026', (string) $doc->channel->item[0]->pubDate);
        $this->assertSame('http://localhost/blog', (string) $doc->channel->link);
    }

    public function test_a_collection_with_nothing_published_has_no_feed_and_it_is_served_without_a_build(): void
    {
        $this->assertSame([], app(Feeds::class)->all());
        $this->get('/blog/feed.xml')->assertNotFound();

        $this->publish('First');
        $this->get('/blog/feed.xml')->assertOk()->assertHeader('Content-Type', 'application/rss+xml; charset=utf-8')->assertSee('First');
    }

    public function test_the_build_writes_it_and_pages_of_the_collection_link_to_it(): void
    {
        $this->publish('First');
        app(SiteBuilder::class)->buildAll();

        $this->assertFileExists(public_path('blog/feed.xml'));
        $this->assertStringContainsString('<link rel="alternate" type="application/rss+xml" title="Posts" href="/blog/feed.xml">', (string) file_get_contents(public_path('blog/first/index.html')));
    }

    public function test_a_built_file_nothing_produces_any_more_goes_with_its_build(): void
    {
        $extra = true;
        $this->cms()->hooks()->addFilter('site.build.extras', static function (array $extras) use (&$extra) {
            return $extra ? [...$extras, 'gone' => ['path' => 'sitemap-xx.xml', 'render' => static fn () => '<urlset/>']] : $extras;
        });

        app(SiteBuilder::class)->buildAll();
        $this->assertFileExists(public_path('sitemap-xx.xml'));

        $extra = false;
        app(SiteBuilder::class)->buildAll();
        $this->assertFileDoesNotExist(public_path('sitemap-xx.xml'));
    }
}
