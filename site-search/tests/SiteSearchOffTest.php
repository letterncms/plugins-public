<?php

declare(strict_types=1);

namespace Cms\SiteSearch\Tests;

use Cms\Modules\Blocks\BlockRenderer;
use Cms\Modules\Blocks\Document;
use Cms\Modules\Blocks\Scope;
use Cms\Modules\Site\Search\SearchIndex;
use Cms\Tests\TestCase;

/** Site Search blocks while the plugin is installed and turned off: kept as they are, named on the canvas, and the index as the core makes it. */
final class SiteSearchOffTest extends TestCase
{
    protected function plugins(): array
    {
        return ['letterncms/blocks', 'letterncms/site'];
    }

    protected function prepareSite(string $site): void
    {
        symlink(dirname(__DIR__), $site.'/plugins/site-search');
    }

    public function test_its_blocks_are_kept_and_the_canvas_says_the_plugin_is_off(): void
    {
        $this->assertFalse($this->cms()->packages()->isActive('letterncms/site-search'));
        $stored = [
            ['id' => 'f1', 'type' => 'site-search/form', 'props' => ['label' => 'Search the site', 'show_label' => true, 'results_page' => '/find']],
            ['id' => 'r1', 'type' => 'site-search/results', 'props' => ['heading_level' => '3', 'per_page' => 10]],
        ];
        $this->assertSame($stored, app(Document::class)->normalize($stored));

        $renderer = app(BlockRenderer::class);
        $this->assertSame('', $renderer->render($stored, new Scope([])));

        $renderer->editor = true;

        try {
            $canvas = $renderer->withEditorDocument('test-source', fn () => $renderer->render($stored, new Scope([])));
        } finally {
            $renderer->editor = false;
        }

        $this->assertStringContainsString('<div class="cms-placeholder">Form block — its plugin, Site Search, is turned off</div>', $canvas);
        $this->assertStringContainsString('<div class="cms-placeholder">Results block — its plugin, Site Search, is turned off</div>', $canvas);
    }

    public function test_the_index_has_no_additions_of_its_own(): void
    {
        $this->cms()->entryService()->create('posts', ['title' => 'Hello', 'status' => 'published', 'data' => ['excerpt' => 'Hi']]);

        $record = array_column(app(SearchIndex::class)->records(), null, 'title')['Hello'];

        $this->assertArrayNotHasKey('kind', $record);
        $this->assertArrayNotHasKey('date', $record);
    }
}
