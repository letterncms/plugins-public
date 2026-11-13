<?php

declare(strict_types=1);

namespace Cms\Spotify\Tests;

use Cms\Modules\Blocks\BlockRenderer;
use Cms\Modules\Blocks\Document;
use Cms\Modules\Blocks\Scope;
use Cms\Tests\TestCase;

/** Spotify blocks while the plugin is installed and turned off: kept as they are, and named on the canvas. */
final class SpotifyOffTest extends TestCase
{
    protected function plugins(): array
    {
        return ['letterncms/blocks'];
    }

    protected function prepareSite(string $site): void
    {
        symlink(dirname(__DIR__), $site.'/plugins/spotify');
    }

    public function test_its_blocks_are_kept_and_the_canvas_says_the_plugin_is_off(): void
    {
        $this->assertFalse($this->cms()->packages()->isActive('letterncms/spotify'));
        $stored = [['id' => 'v1', 'type' => 'spotify/player', 'props' => ['url' => 'spotify:album:37i9dQZF1DXcBWIGoYBM5M', 'size' => 'tall', 'theme' => 'dark']]];
        $this->assertSame($stored, app(Document::class)->normalize($stored));

        $renderer = app(BlockRenderer::class);
        $this->assertSame('', $renderer->render($stored, new Scope([])));

        $renderer->editor = true;

        try {
            $canvas = $renderer->withEditorDocument('test-source', fn () => $renderer->render($stored, new Scope([])));
        } finally {
            $renderer->editor = false;
        }

        $this->assertStringContainsString('<div class="cms-placeholder">Player block — its plugin, Spotify, is turned off</div>', $canvas);
    }
}
