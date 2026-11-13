<?php

declare(strict_types=1);

namespace Cms\Youtube\Tests;

use Cms\Modules\Blocks\BlockRenderer;
use Cms\Modules\Blocks\Document;
use Cms\Modules\Blocks\Scope;
use Cms\Tests\TestCase;

/** YouTube blocks while the plugin is installed and turned off: kept as they are, and named on the canvas. */
final class YoutubeOffTest extends TestCase
{
    protected function plugins(): array
    {
        return ['letterncms/blocks'];
    }

    protected function prepareSite(string $site): void
    {
        symlink(dirname(__DIR__), $site.'/plugins/youtube');
    }

    public function test_its_blocks_are_kept_and_the_canvas_says_the_plugin_is_off(): void
    {
        $this->assertFalse($this->cms()->packages()->isActive('letterncms/youtube'));
        $stored = [['id' => 'v1', 'type' => 'youtube/video', 'props' => ['url' => 'https://youtu.be/aqz-KE-bpKQ', 'start' => '0:30', 'load_on_click' => true]]];
        $this->assertSame($stored, app(Document::class)->normalize($stored));

        $renderer = app(BlockRenderer::class);
        $this->assertSame('', $renderer->render($stored, new Scope([])));

        $renderer->editor = true;

        try {
            $canvas = $renderer->withEditorDocument('test-source', fn () => $renderer->render($stored, new Scope([])));
        } finally {
            $renderer->editor = false;
        }

        $this->assertStringContainsString('<div class="cms-placeholder">Video block — its plugin, YouTube, is turned off</div>', $canvas);
    }
}
