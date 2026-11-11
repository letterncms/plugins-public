<?php

declare(strict_types=1);

namespace Cms\Seo\Tests;

use Cms\Content\Collections\Archives;
use Cms\Content\Entries\Entry;
use Cms\Modules\Site\Build\SiteBuilder;
use Cms\Seo\SiteFiles;
use Cms\Tests\TestCase;

/** A term's archives are pages like any other to a search engine: in the sitemap of their language, with a Markdown version. */
final class TermArchivesTest extends TestCase
{
    protected function plugins(): array
    {
        return ['letterncms/blocks', 'letterncms/site', 'letterncms/languages', 'letterncms/seo'];
    }

    protected function collections(): array
    {
        return [
            'topics' => ['title' => 'Topics', 'taxonomy' => true, 'route' => '/topics/{slug}', 'fields' => [['handle' => 'description', 'type' => 'textarea']]],
            'posts' => ['title' => 'Posts', 'structure' => 'dated', 'route' => '/blog/{slug}', 'fields' => [['handle' => 'topics', 'type' => 'terms', 'taxonomy' => 'topics'], ['handle' => 'notes', 'type' => 'textarea']], 'archives' => ['topics' => ['route' => '/blog/topics/{slug}', 'routes' => ['de' => '/blog/themen/{slug}']]]],
        ];
    }

    protected function setUp(): void
    {
        parent::setUp();

        if (! class_exists(Archives::class)) {
            $this->markTestSkipped('This core has no term archives.');
        }
    }

    private function make(string $collection, string $title, array $data = [], array $more = []): Entry
    {
        return $this->cms()->entryService()->create($collection, ['title' => $title, 'status' => 'published', 'data' => $data, ...$more]);
    }

    public function test_an_archive_is_in_the_sitemap_of_its_language(): void
    {
        $tides = $this->make('topics', 'Tides');
        $this->make('posts', 'Spring tide', ['topics' => [$tides->id]]);
        $files = app(SiteFiles::class);
        $archive = '<loc>'.$this->cms()->url('/blog/topics/tides').'</loc>';

        // One language: one sitemap, and the archive in it.
        $this->assertStringContainsString($archive, $files->sitemap());

        $gezeiten = $this->cms()->entryService()->copyFrom($tides, ['title' => 'Gezeiten', 'slug' => 'gezeiten', 'data' => [], 'locale' => 'de', 'originId' => $tides->id, 'status' => 'published']);
        $this->make('posts', 'Springflut', ['topics' => [$gezeiten->id]], ['locale' => 'de']);
        $german = '<loc>'.$this->cms()->url('/de/blog/themen/gezeiten').'</loc>';

        // Two: each in its own.
        $this->assertStringContainsString('sitemapindex', $files->sitemap());
        $this->assertStringContainsString($archive, $files->sitemap('en'));
        $this->assertStringNotContainsString($german, $files->sitemap('en'));
        $this->assertStringContainsString($german, $files->sitemap('de'));
        $this->assertStringNotContainsString($archive, $files->sitemap('de'));
        // Each names the other language's archive of the same term, as a page names its translations.
        $this->assertStringContainsString('<xhtml:link rel="alternate" hreflang="de" href="'.$this->cms()->url('/de/blog/themen/gezeiten').'"/>', $files->sitemap('en'));
        $this->assertStringContainsString('<xhtml:link rel="alternate" hreflang="en" href="'.$this->cms()->url('/blog/topics/tides').'"/>', $files->sitemap('de'));

        // What says no language is the default language's, as before.
        $this->cms()->hooks()->addFilter('seo.sitemap', static fn (array $urls) => [...$urls, ['uri' => '/shop/boat', 'updated' => null]]);
        $this->assertStringContainsString('/shop/boat', $files->sitemap('en'));
        $this->assertStringNotContainsString('/shop/boat', $files->sitemap('de'));

        // A term kept out of search keeps its archives out too.
        $this->cms()->entryService()->update($tides->id, ['data' => ['seo' => ['noindex' => true]]], publish: true);
        $this->assertStringNotContainsString($archive, $files->sitemap('en'));

        // A draft term has no archive to list.
        $this->cms()->entryService()->unpublish($gezeiten->id);
        $this->assertStringNotContainsString($german, $files->sitemap());
    }

    public function test_an_archive_has_a_markdown_version_that_lists_what_is_filed(): void
    {
        $tides = $this->make('topics', 'Tides', ['description' => 'The sea, twice a day.']);
        $this->make('posts', 'Spring tide', ['topics' => [$tides->id]]);
        $this->cms()->entryService()->create('posts', ['title' => 'Unfinished tide', 'data' => ['topics' => [$tides->id]]]);
        // Naming the term in its text files nothing.
        $this->make('posts', 'Mentioned tide', ['notes' => 'See entry:'.$tides->id.' for more.']);

        $this->assertStringContainsString('<link rel="alternate" type="text/markdown" href="/blog/topics/tides.md">', $this->get('/blog/topics/tides')->assertOk()->getContent());
        $twin = $this->get('/blog/topics/tides.md')->assertOk()->getContent();
        $this->assertStringContainsString('# Tides', $twin);
        $this->assertStringContainsString('- [Spring tide]('.$this->cms()->url('/blog/spring-tide').')', $twin);
        $this->assertStringNotContainsString('Unfinished', $twin);
        $this->assertStringNotContainsString('Mentioned tide', $twin);
        $this->assertStringContainsString('Source: '.$this->cms()->url('/blog/topics/tides'), $twin);
        $this->assertStringNotContainsString('Spring tide', $this->get('/topics/tides.md')->assertOk()->getContent(), 'The term\'s own page is the term.');
        $this->get('/blog/topics/nowhere.md')->assertNotFound();

        app(SiteBuilder::class)->buildAll();
        $this->assertSame($twin, file_get_contents(public_path('blog/topics/tides.md')));

        // Gone with the term.
        $this->cms()->entryService()->unpublish($tides->id);
        $builder = app(SiteBuilder::class);

        while ($builder->hasWork()) {
            $builder->process(10_000);
        }

        $this->assertFileDoesNotExist(public_path('blog/topics/tides.md'));
        $this->get('/blog/topics/tides.md')->assertNotFound();
    }
}
