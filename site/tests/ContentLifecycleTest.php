<?php

declare(strict_types=1);

namespace Cms\Site\Tests;

use Cms\Abilities\AbilityContext;
use Cms\Site\Build\SiteBuilder;
use Cms\Tests\TestCase;

/** Built routes and their derived assets follow content lifecycle, even within one second. */
final class ContentLifecycleTest extends TestCase
{
    protected function plugins(): array
    {
        return ['letterncms/blocks', 'letterncms/site', 'letterncms/seo'];
    }

    public function test_collection_deletion_removes_just_built_routes_search_sitemap_and_markdown(): void
    {
        $this->freezeTime();
        $entry = $this->cms()->content()->create('notes', ['title' => 'Experiment', 'status' => 'published', 'data' => ['body' => 'Experimental content']]);
        $builder = app(SiteBuilder::class);
        $this->assertSame([], $builder->buildAll()['errors']);
        $this->assertFileExists(public_path('notes/experiment/index.html'));
        $this->assertFileExists(public_path('notes/experiment.md'));
        $this->assertStringContainsString('/notes/experiment', file_get_contents(public_path('search.json')));
        $this->assertStringContainsString('/notes/experiment', file_get_contents(public_path('sitemap.xml')));

        $this->cms()->abilities()->execute('collections/delete', ['handle' => 'notes', 'deleteEntries' => true], AbilityContext::system());
        $this->assertSame([], $builder->buildAll()['errors']);
        $this->assertFileDoesNotExist(public_path('notes/experiment/index.html'));
        $this->assertFileDoesNotExist(public_path('notes/experiment.md'));
        $this->assertStringNotContainsString('/notes/experiment', file_get_contents(public_path('search.json')));
        $this->assertStringNotContainsString('/notes/experiment', file_get_contents(public_path('sitemap.xml')));
    }

    public function test_moving_published_file_tree_rebuilds_only_public_content_and_removes_old_markdown(): void
    {
        $service = $this->cms()->content();
        $parent = $service->create('notes', ['title' => 'Parent']);
        $entry = $service->create('notes', ['title' => 'Public', 'status' => 'published', 'data' => ['body' => 'Public words']]);
        $child = $service->create('notes', ['title' => 'Child', 'parentId' => $entry->id, 'status' => 'published', 'data' => ['body' => 'Child words']]);
        $builder = app(SiteBuilder::class);
        $builder->buildAll();
        $draft = $service->update($entry->id, ['title' => 'Secret', 'slug' => 'secret', 'data' => ['body' => 'Secret draft words']]);
        $service->move($entry->id, $parent->id, expectedVersion: $draft->version());
        $this->assertSame([], $builder->buildAll()['errors']);
        $new = file_get_contents(public_path('notes/parent/public/index.html'));
        $this->assertStringContainsString('Public words', $new);
        $this->assertStringNotContainsString('Secret draft words', $new);
        // Redirect stubs may occupy old HTML URLs; their Markdown must be gone.
        $this->assertFileDoesNotExist(public_path('notes/public.md'));
        $this->assertFileDoesNotExist(public_path('notes/public/child.md'));
        $this->assertFileExists(public_path('notes/parent/public/child/index.html'));
        $this->assertSame('Secret', $this->cms()->entries()->find($entry->id)->working()['title']);
    }
}
