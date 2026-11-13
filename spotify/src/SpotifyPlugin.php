<?php

declare(strict_types=1);

namespace Cms\Spotify;

use Cms\Modules\Blocks\BlockRegistry;
use Cms\Packages\Plugin;

/**
 * The Spotify block. Its stylesheet comes to the pages that show one, and the script that loads the player on a click
 * only to those with a player that waits for one.
 */
final class SpotifyPlugin extends Plugin
{
    public function register(): void
    {
        $this->app->singleton(Items::class);
    }

    public function boot(): void
    {
        $this->cms()->whenAvailable(BlockRegistry::class, function (BlockRegistry $blocks): void {
            $blocks->feature('spotify/player', style: $this->file('spotify.css'));
            $blocks->feature('spotify/facade', script: $this->file('facade.js'));
            $blocks->register(SpotifyPlayer::definition());
        });

        // The site's content security policy (core `site.policy`): the player's frame.
        $this->cms()->hooks()->addFilter('site.policy', static function (array $policy): array {
            $policy['frame-src'] = [...($policy['frame-src'] ?? ["'self'"]), SpotifyPlayer::FRAME_HOST];

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
