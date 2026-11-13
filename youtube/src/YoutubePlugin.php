<?php

declare(strict_types=1);

namespace Cms\Youtube;

use Cms\Modules\Blocks\BlockRegistry;
use Cms\Packages\Plugin;

/**
 * The YouTube block. Its stylesheet comes to the pages that show one, and the script that swaps the poster for
 * YouTube's player only to those with a video that waits to be played.
 */
final class YoutubePlugin extends Plugin
{
    public function register(): void
    {
        $this->app->singleton(Videos::class);
    }

    public function boot(): void
    {
        $this->cms()->whenAvailable(BlockRegistry::class, function (BlockRegistry $blocks): void {
            $blocks->feature('youtube/video', style: $this->file('youtube.css'));
            $blocks->feature('youtube/facade', script: $this->file('facade.js'));
            $blocks->register(YoutubeVideo::definition());
        });

        // The site's content security policy (core `site.policy`): the player's frame, from either of YouTube's hosts.
        $this->cms()->hooks()->addFilter('site.policy', static function (array $policy): array {
            $policy['frame-src'] = [...($policy['frame-src'] ?? ["'self'"]), ...YoutubeVideo::FRAME_HOSTS];

            return $policy;
        });
    }

    /** A built file's address with its version, so browsers fetch it again after an update; null before the first build. */
    private function file(string $name): ?string
    {
        $path = $this->path('dist/'.$name);

        return is_file($path) ? $this->asset($name).'?v='.substr((string) hash_file('xxh3', $path), 0, 10) : null;
    }
}
