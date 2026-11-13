<?php

declare(strict_types=1);

namespace Cms\Spotify\Tests;

use Cms\Modules\Blocks\BlockRegistry;
use Cms\Modules\Blocks\BlockRenderer;
use Cms\Modules\Blocks\Scope;
use Cms\Spotify\SpotifyPlayer;
use Cms\Tests\TestCase;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;

final class SpotifyTest extends TestCase
{
    private const string ID = '37i9dQZF1DXcBWIGoYBM5M';

    protected function plugins(): array
    {
        return ['letterncms/spotify'];
    }

    protected function collections(): array
    {
        return ['pages' => ['title' => 'Pages', 'route' => '/{slug}', 'fields' => [['handle' => 'content', 'type' => 'blocks', 'body' => true]]]];
    }

    protected function setUp(): void
    {
        parent::setUp();
        Http::preventStrayRequests();
    }

    private function spotify(int $status = 200): void
    {
        Http::fake(['open.spotify.com/oembed*' => $status === 200 ? Http::response(['title' => 'Today’s Top Hits', 'type' => 'rich']) : Http::response('Not found', $status)]);
    }

    private function render(array $props, bool $editor = false): string
    {
        $renderer = app(BlockRenderer::class);
        $renderer->begin();
        $renderer->editor = $editor;

        try {
            return $renderer->withEditorDocument($editor ? 'test-source' : null, fn () => $renderer->render([['id' => 's1', 'type' => 'spotify/player', 'props' => $props]], new Scope([])));
        } finally {
            $renderer->editor = false;
        }
    }

    public function test_every_shape_of_link_names_what_it_plays(): void
    {
        $id = self::ID;
        $links = [
            "https://open.spotify.com/playlist/{$id}" => 'playlist',
            "https://open.spotify.com/playlist/{$id}?si=a1b2c3" => 'playlist',
            "https://open.spotify.com/intl-de/track/{$id}?si=x" => 'track',
            "https://open.spotify.com/intl-pt-br/album/{$id}" => 'album',
            "open.spotify.com/artist/{$id}" => 'artist',
            "https://open.spotify.com/embed/episode/{$id}" => 'episode',
            "https://open.spotify.com/embed-podcast/show/{$id}" => 'show',
            "https://open.spotify.com/user/spotify/playlist/{$id}" => 'playlist',
            "https://play.spotify.com/album/{$id}" => 'album',
            "spotify:track:{$id}" => 'track',
            "spotify:user:spotify:playlist:{$id}" => 'playlist',
        ];

        foreach ($links as $link => $kind) {
            $this->assertSame(['kind' => $kind, 'id' => $id], SpotifyPlayer::parse($link), $link);
        }

        foreach (['', 'https://open.spotify.com/', "https://open.spotify.com/genre/{$id}", 'https://open.spotify.com/track/short', "https://open.spotify.com.evil.example/track/{$id}", "https://evil.example/open.spotify.com/track/{$id}", 'https://spotify.link/abc123', 'https://open.spotify.com/collection/tracks', "spotify:album:{$id}:extra", "javascript:alert('{$id}')"] as $link) {
            $this->assertNull(SpotifyPlayer::parse($link), $link);
        }
    }

    public function test_one_thing_to_play_gets_the_compact_player_and_a_list_the_tall_one(): void
    {
        $this->spotify();

        foreach (['track' => 152, 'episode' => 152, 'album' => 352, 'playlist' => 352, 'artist' => 352, 'show' => 352] as $kind => $height) {
            $this->assertStringContainsString('style="height:'.$height.'px"', $this->render(['url' => "https://open.spotify.com/{$kind}/".self::ID]), $kind);
        }

        $this->assertStringContainsString('style="height:80px"', $this->render(['url' => 'spotify:track:'.self::ID, 'size' => 'slim']));
        $this->assertStringContainsString('style="height:352px"', $this->render(['url' => 'spotify:track:'.self::ID, 'size' => 'tall']));
        $this->assertStringContainsString('style="height:152px"', $this->render(['url' => 'spotify:album:'.self::ID, 'size' => 'compact']));
    }

    public function test_the_player_is_spotify_s_own_in_its_theme_with_its_title_for_screen_readers(): void
    {
        $this->spotify();

        $html = $this->render(['url' => 'https://open.spotify.com/playlist/'.self::ID.'?si=abc']);
        $this->assertSame('<div class="cms-spotify-player"><iframe src="https://open.spotify.com/embed/playlist/'.self::ID.'" title="Today’s Top Hits" style="height:352px" loading="lazy" allow="autoplay; clipboard-write; encrypted-media; fullscreen; picture-in-picture"></iframe></div>', $html);
        $this->assertTrue(app(BlockRenderer::class)->features['spotify/player'] ?? false, 'its stylesheet comes with it');
        $this->assertArrayNotHasKey('spotify/facade', app(BlockRenderer::class)->features, 'and no script');

        $dark = $this->render(['url' => 'spotify:playlist:'.self::ID, 'theme' => 'dark', 'title' => 'Our "summer" <list>']);
        $this->assertStringContainsString('src="https://open.spotify.com/embed/playlist/'.self::ID.'?theme=0" title="Our &quot;summer&quot; &lt;list&gt;"', $dark);
    }

    public function test_with_load_on_click_nothing_comes_from_spotify_until_the_card_is_pressed(): void
    {
        $this->spotify();

        $html = $this->render(['url' => 'https://open.spotify.com/track/'.self::ID, 'load_on_click' => true, 'theme' => 'dark']);

        $this->assertStringNotContainsString('<iframe', $html);
        $this->assertStringContainsString('<a class="cms-spotify-player__card cms-spotify-player__card--dark" href="https://open.spotify.com/track/'.self::ID.'" style="height:152px" data-cms-spotify="https://open.spotify.com/embed/track/'.self::ID.'?theme=0" data-cms-spotify-title="Today’s Top Hits">', $html, 'without scripts, a link to it on Spotify');
        $this->assertStringContainsString('<span class="cms-spotify-player__title">Today’s Top Hits</span><span class="cms-spotify-player__kind">Song on Spotify</span>', $html);
        $this->assertStringContainsString('<span class="cms-spotify-player__label">Load the Spotify player</span>', $html);
        $this->assertTrue(app(BlockRenderer::class)->features['spotify/facade'] ?? false, 'the script that loads it');
        $this->assertStringContainsString('cms-spotify-player__card--slim', $this->render(['url' => 'spotify:track:'.self::ID, 'load_on_click' => true, 'size' => 'slim']));
    }

    public function test_without_something_to_play_the_site_shows_nothing_and_the_canvas_says_why(): void
    {
        $this->spotify();

        foreach (['', 'https://example.com/track/'.self::ID, 'https://spotify.link/abc123', 'https://open.spotify.com/collection/tracks'] as $url) {
            $this->assertSame('', $this->render(['url' => $url]), $url);
        }

        $this->assertMatchesRegularExpression('#^<div class="cms-spotify-player" data-cms-document="test-source" data-cms-node="s1" data-cms-type="spotify/player" data-cms-media><span class="cms-media-placeholder" style="height:352px"><svg#', $this->render([], editor: true));
        $this->assertStringContainsString('This isn’t a link to a track, album, playlist, artist, episode or show on Spotify.', $this->render(['url' => 'https://example.com/track/1'], editor: true));
        $this->assertStringContainsString('Short links can’t be shown here. Open it, then copy the open.spotify.com link.', $this->render(['url' => 'https://spotify.link/abc123'], editor: true));
        $this->assertStringContainsString('Your library and profile are private. Share a public playlist instead.', $this->render(['url' => 'https://open.spotify.com/collection/tracks'], editor: true));
        Http::assertNothingSent();
    }

    public function test_something_private_or_gone_shows_nothing_and_the_canvas_says_so(): void
    {
        $this->spotify(404);

        $this->assertSame('', $this->render(['url' => 'spotify:playlist:'.self::ID]));
        $this->assertStringContainsString('This is private or no longer on Spotify.', $this->render(['url' => 'spotify:playlist:'.self::ID], editor: true));
        Http::assertSentCount(1);
    }

    public function test_when_spotify_cannot_be_asked_the_player_still_shows(): void
    {
        Http::fake(static fn () => throw new ConnectionException('offline'));

        $this->assertStringContainsString('<iframe src="https://open.spotify.com/embed/album/'.self::ID.'" title="Album on Spotify"', $this->render(['url' => 'spotify:album:'.self::ID]));
    }

    public function test_the_security_policy_allows_the_players_frame_and_the_block_is_in_the_catalog(): void
    {
        $policy = $this->cms()->hooks()->filter('site.policy', ['frame-src' => ["'self'"]]);
        $this->assertSame(["'self'", 'open.spotify.com'], $policy['frame-src']);

        $registry = app(BlockRegistry::class);
        $type = $registry->get('spotify/player');
        $this->assertSame('Spotify', $type->title);
        $this->assertSame('spotify-logo', $type->icon);
        $this->assertSame('letterncms/spotify', $type->package);
        $this->assertSame(['spotify/player'], $type->uses);
        $this->assertNotEmpty($type->example);
        $this->assertArrayHasKey('spotify/player', $registry->features());
        $this->assertArrayHasKey('spotify/facade', $registry->features());
    }

    public function test_a_page_saves_it_with_a_uri(): void
    {
        $context = \Cms\Abilities\AbilityContext::forUser($this->admin(), 'admin');
        $entry = $this->cms()->abilities()->execute('entries/create', ['collection' => 'pages', 'title' => 'Listen', 'data' => ['content' => [['type' => 'spotify/player', 'props' => ['url' => 'spotify:album:37i9dQZF1DXcBWIGoYBM5M']]]]], $context);

        $this->assertSame('spotify:album:37i9dQZF1DXcBWIGoYBM5M', $this->cms()->entries()->find($entry['id'])->data['content'][0]['props']['url'], 'a spotify: URI is no web address, and is kept');
    }
}
