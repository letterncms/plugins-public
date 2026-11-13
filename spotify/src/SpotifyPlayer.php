<?php

declare(strict_types=1);

namespace Cms\Spotify;

use Cms\Modules\Blocks\Elements;
use Cms\Modules\Blocks\Icons;

/**
 * The Spotify block: Spotify's player for a track, album, playlist, artist, podcast episode or show, by its link.
 * One thing to play gets the compact player and a list the tall one, unless a size is chosen. With Load on Click,
 * the page shows a card in the player's place, a link to Spotify, and the facade script loads the player when it
 * is pressed; nothing on the page contacts Spotify before.
 */
final class SpotifyPlayer
{
    public const string FRAME_HOST = 'open.spotify.com';

    public const array KINDS = ['track', 'album', 'playlist', 'artist', 'episode', 'show'];

    /** The player's heights Spotify designs for, in pixels: one row, the compact card and the tall player with its list. */
    public const array HEIGHTS = ['slim' => 80, 'compact' => 152, 'tall' => 352];

    /** An id: 22 letters and digits. */
    private const string ID = '[A-Za-z0-9]{22}';

    public static function definition(): array
    {
        return [
            'name' => 'spotify/player', 'title' => 'Spotify', 'icon' => 'spotify-logo', 'category' => 'media',
            'description' => 'A Spotify track, album, playlist, artist, podcast episode or show, with Spotify’s player.',
            'keywords' => ['spotify', 'music', 'song', 'track', 'album', 'playlist', 'artist', 'podcast', 'episode', 'show', 'audio', 'listen', 'player', 'embed'],
            'uses' => ['spotify/player'],
            'fields' => [
                ['handle' => 'url', 'type' => 'text', 'label' => 'Spotify Link', 'placeholder' => 'https://open.spotify.com/…'],
                ['handle' => 'title', 'type' => 'text', 'label' => 'Title for Screen Readers', 'placeholder' => 'Its title on Spotify'],
                ['handle' => 'size', 'type' => 'select', 'label' => 'Size', 'options' => ['auto' => 'Automatic', 'slim' => 'Slim', 'compact' => 'Compact', 'tall' => 'Tall'], 'default' => 'auto'],
                ['handle' => 'theme', 'type' => 'select', 'label' => 'Colors', 'options' => ['auto' => 'From the Artwork', 'dark' => 'Dark'], 'default' => 'auto', 'display' => 'segmented'],
                ['handle' => 'load_on_click', 'type' => 'toggle', 'label' => 'Load on Click', 'default' => false],
            ],
            'render' => self::render(...),
            'example' => ['url' => 'https://open.spotify.com/playlist/37i9dQZF1DXcBWIGoYBM5M', 'title' => 'Today’s Top Hits', 'size' => 'auto', 'theme' => 'dark'],
        ];
    }

    /**
     * What a link or URI names, in the shapes Spotify gives out: open.spotify.com links (with a language, `intl-de/`,
     * or as an embed), old play.spotify.com and user playlist links, and spotify: URIs.
     *
     * @return array{kind: string, id: string}|null
     */
    public static function parse(string $value): ?array
    {
        $value = trim($value);
        $kinds = implode('|', self::KINDS);

        if (preg_match('#^spotify:(?:user:[^:\s]+:)?('.$kinds.'):('.self::ID.')$#', $value, $m)) {
            return ['kind' => $m[1], 'id' => $m[2]];
        }

        if (preg_match('#^(?:https?://)?(?:open|play)\.spotify\.com/(?:intl-[a-z]{2}(?:-[a-z]{2})?/)?(?:embed(?:-podcast)?/)?(?:user/[^/?\#\s]+/)?('.$kinds.')/('.self::ID.')(?:[/?\#]\S*)?$#i', $value, $m)) {
            return ['kind' => strtolower($m[1]), 'id' => $m[2]];
        }

        return null;
    }

    /** Why a link that names nothing Spotify can show here doesn't, for the editor. */
    public static function problem(string $value): string
    {
        $value = trim($value);

        return match (true) {
            (bool) preg_match('#^(https?://)?(spotify\.link|spoti\.fi)/#i', $value) => __('Short links can’t be shown here. Open it, then copy the open.spotify.com link.'),
            (bool) preg_match('#^(spotify:(user:[^:\s]+:)?collection|(https?://)?open\.spotify\.com/(intl-[a-z-]+/)?(collection|user)/)#i', $value) => __('Your library and profile are private. Share a public playlist instead.'),
            default => __('This isn’t a link to a track, album, playlist, artist, episode or show on Spotify.'),
        };
    }

    /** One thing to play fits the compact player; a list needs the tall one. */
    public static function height(string $kind, mixed $size): int
    {
        return self::HEIGHTS[is_string($size) ? $size : ''] ?? (in_array($kind, ['track', 'episode'], true) ? self::HEIGHTS['compact'] : self::HEIGHTS['tall']);
    }

    public static function embed(array $item, bool $dark = false): string
    {
        return "https://open.spotify.com/embed/{$item['kind']}/{$item['id']}".($dark ? '?theme=0' : '');
    }

    public static function render(array $d): string
    {
        $b = $d['block'];
        $url = trim((string) ($b['url'] ?? ''));
        $item = self::parse($url);
        $height = self::height($item['kind'] ?? 'playlist', $b['size'] ?? 'auto');

        if ($url === '') {
            return $d['editor'] ? '<div '.$d['attrs'].' data-cms-media><span class="cms-media-placeholder" style="height:'.$height.'px">'.Icons::svg('spotify-logo').'</span></div>' : '';
        }

        if ($item === null) {
            return $d['editor'] ? self::message($d, $height, self::problem($url)) : '';
        }

        $found = app(Items::class)->find($item['kind'], $item['id']);

        if ($found['status'] === 'unavailable') {
            return $d['editor'] ? self::message($d, $height, __('This is private or no longer on Spotify.')) : '';
        }

        $what = self::kind($item['kind']);
        $title = trim((string) ($b['title'] ?? '')) ?: ($found['title'] ?? $what);
        $dark = ($b['theme'] ?? 'auto') === 'dark';
        $src = self::embed($item, $dark);

        // The canvas runs no scripts, so neither would the player: it shows the card, which names what plays.
        if (! ($b['load_on_click'] ?? false) && ! $d['editor']) {
            return '<div '.$d['attrs'].'><iframe src="'.e($src).'" title="'.e($title).'" style="height:'.$height.'px" loading="lazy" allow="autoplay; clipboard-write; encrypted-media; fullscreen; picture-in-picture"></iframe></div>';
        }

        $d['editor'] || $d['renderer']->uses('spotify/facade');
        $link = "https://open.spotify.com/{$item['kind']}/{$item['id']}";
        $named = $title !== $what;

        // The cover the site copied, on the colour it is mostly made of, as Spotify's player shows it; dark when asked.
        $cover = $found['cover'] ?? null;
        $color = ! $dark && is_string($cover['color'] ?? null) ? ' background-color:'.$cover['color'].';' : '';
        $size = $height < self::HEIGHTS['compact'] ? 'slim' : ($height < self::HEIGHTS['tall'] ? 'compact' : 'tall');
        $art = is_array($cover) ? '<img class="cms-spotify-player__art" src="'.e($cover['src']).'" width="'.$cover['width'].'" height="'.$cover['height'].'" alt="" loading="lazy" decoding="async">' : '';

        return '<div '.$d['attrs'].'>'
            .'<a class="cms-spotify-player__card cms-spotify-player__card--'.$size.($dark || $color === '' ? ' cms-spotify-player__card--dark' : '').'" href="'.e($link).'" style="height:'.$height.'px;'.$color.'" data-cms-spotify="'.e($src).'" data-cms-spotify-title="'.e($title).'">'
            .$art
            .'<span class="cms-spotify-player__text">'.($named ? '<span class="cms-spotify-player__title">'.e($title).'</span>' : '').'<span class="cms-spotify-player__kind">'.e($what).'</span></span>'
            .'<span class="cms-spotify-player__logo">'.Icons::svg('spotify-logo-fill').'</span>'
            .'<span class="cms-spotify-player__play">'.Icons::svg('play-fill').'<span class="cms-spotify-player__label">'.e(__('Load the Spotify player')).'</span></span>'
            .'</a></div>';
    }

    /** What a link plays, as the card names it. */
    private static function kind(string $kind): string
    {
        return match ($kind) {
            'track' => __('Song on Spotify'),
            'album' => __('Album on Spotify'),
            'playlist' => __('Playlist on Spotify'),
            'artist' => __('Artist on Spotify'),
            'episode' => __('Podcast episode on Spotify'),
            default => __('Podcast on Spotify'),
        };
    }

    /** Editor only: why there is nothing to play, at the player's height. */
    private static function message(array $d, int $height, string $text): string
    {
        return '<div '.$d['attrs'].' data-cms-media><span class="cms-media-placeholder cms-spotify-player__message" style="min-height:'.$height.'px">'
            .Icons::svg('spotify-logo').'<span class="cms-placeholder">'.e($text).'</span></span></div>';
    }
}
