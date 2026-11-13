<?php

declare(strict_types=1);

namespace Cms\Youtube;

use Cms\Modules\Blocks\Elements;
use Cms\Modules\Blocks\Icons;
use Cms\Modules\Blocks\Media;

/**
 * The YouTube block: a video by its link or id. By default the page shows the video's poster with a play button, a
 * link to the video on YouTube; the facade script turns a click on it into YouTube's player, already playing. So
 * nothing on the page contacts YouTube, and no cookie is set, until the visitor chooses to watch.
 */
final class YoutubeVideo
{
    /** Where the player's frame comes from: YouTube's privacy-enhanced host, or its own. */
    public const array FRAME_HOSTS = ['www.youtube-nocookie.com', 'www.youtube.com'];

    /** The shapes it offers besides Automatic: 16:9 for a video, 9:16 for a Short. */
    public const array ASPECTS = ['16/9', '4/3', '1/1', '9/16'];

    /** A video's id: eleven letters, digits, dashes and underscores. */
    private const string ID = '/^[A-Za-z0-9_-]{11}$/';

    public static function definition(): array
    {
        return [
            'name' => 'youtube/video', 'title' => 'YouTube', 'icon' => 'youtube-logo', 'category' => 'media',
            'description' => 'A YouTube video or Short that shows its poster and loads YouTube only when played.',
            'keywords' => ['youtube', 'video', 'shorts', 'clip', 'film', 'movie', 'trailer', 'tutorial', 'vlog', 'stream', 'embed', 'player', 'privacy'],
            'uses' => ['youtube/video'],
            'fields' => [
                ['handle' => 'url', 'type' => 'text', 'label' => 'Video Link', 'placeholder' => 'https://www.youtube.com/watch?v=…'],
                ['handle' => 'title', 'type' => 'text', 'label' => 'Title for Screen Readers', 'placeholder' => 'The video’s title on YouTube'],
                ['handle' => 'start', 'type' => 'text', 'label' => 'Start At', 'placeholder' => '0:00', 'max' => 12],
                ['handle' => 'aspect', 'type' => 'select', 'label' => 'Aspect Ratio', 'options' => ['auto' => 'Automatic', '16/9' => '16:9', '4/3' => '4:3', '1/1' => 'Square', '9/16' => 'Vertical'], 'default' => 'auto'],
                ['handle' => 'captions', 'type' => 'toggle', 'label' => 'Show Subtitles', 'default' => true],
                ['handle' => 'privacy', 'type' => 'toggle', 'label' => 'Privacy-Enhanced Mode', 'default' => true],
                ['handle' => 'load_on_click', 'type' => 'toggle', 'label' => 'Load When Played', 'default' => true],
                ['handle' => 'poster', 'type' => 'media', 'label' => 'Poster Image', 'max' => 1, 'accept' => 'image/*', 'if' => ['load_on_click' => [true, null]]],
            ],
            'render' => self::render(...),
            'example' => ['url' => 'https://www.youtube.com/watch?v=aqz-KE-bpKQ', 'title' => 'Big Buck Bunny', 'start' => '0:30'],
        ];
    }

    /**
     * The video a link or id names, in any of the shapes YouTube gives out: watch, youtu.be, Shorts, embed and live
     * links, with or without https:// and www., and the start time a link carries (`t=1m30s`, `start=90`).
     *
     * @return array{id: string, short: bool, start: ?int}|null
     */
    public static function parse(string $value): ?array
    {
        $value = trim($value);

        if (preg_match(self::ID, $value)) {
            return ['id' => $value, 'short' => false, 'start' => null];
        }

        $parts = parse_url(preg_match('#^https?://#i', $value) ? $value : 'https://'.$value);

        if (! is_array($parts) || ! isset($parts['host']) || isset($parts['user']) || isset($parts['port'])) {
            return null;
        }

        $host = (string) preg_replace('/^(www|m|music)\./', '', strtolower($parts['host']));
        $path = rtrim((string) ($parts['path'] ?? '/'), '/');
        parse_str((string) ($parts['query'] ?? ''), $query);
        parse_str((string) ($parts['fragment'] ?? ''), $fragment);
        $id = null;
        $short = false;

        if ($host === 'youtu.be') {
            $id = substr($path, 1);
        } elseif (in_array($host, ['youtube.com', 'youtube-nocookie.com'], true)) {
            if ($path === '/watch') {
                $id = $query['v'] ?? null;
            } elseif (preg_match('#^/(shorts|embed|live|v|e)/([^/]+)$#', $path, $m)) {
                [$id, $short] = [$m[2], $m[1] === 'shorts'];
            }
        }

        if (! is_string($id) || ! preg_match(self::ID, $id)) {
            return null;
        }

        $start = $query['t'] ?? $query['start'] ?? $fragment['t'] ?? null;

        return ['id' => $id, 'short' => $short, 'start' => is_string($start) ? self::seconds($start) : null];
    }

    /** A link to a list of videos rather than to one. */
    public static function isPlaylist(string $value): bool
    {
        return (bool) preg_match('#^(https?://)?(www\.|m\.|music\.)?youtube\.com/playlist\?#i', trim($value));
    }

    /** A time as people write it, `90`, `90s`, `1:30`, `1m30s` or `1:02:03`, in seconds; null for none or nothing readable. */
    public static function seconds(string $time): ?int
    {
        $time = strtolower(trim($time));

        $seconds = match (true) {
            (bool) preg_match('/^\d+s?$/', $time) => (int) $time,
            (bool) preg_match('/^(?:(\d+)h)?(?:(\d+)m)?(?:(\d+)s)?$/', $time, $m) => (int) ($m[1] ?? 0) * 3600 + (int) ($m[2] ?? 0) * 60 + (int) ($m[3] ?? 0),
            (bool) preg_match('/^(?:(\d+):)?(\d{1,2}):(\d{2})$/', $time, $m) => (int) $m[1] * 3600 + (int) $m[2] * 60 + (int) $m[3],
            default => 0,
        };

        return $seconds > 0 ? $seconds : null;
    }

    /** The player's address: privacy-enhanced unless asked otherwise, with captions in the site's language when they are on. */
    public static function embed(string $id, bool $privacy = true, bool $captions = true, ?int $start = null, bool $autoplay = false): string
    {
        $language = str_replace('_', '-', app()->getLocale());
        $language = preg_match('/^[a-z]{2,3}(-[A-Za-z]{2})?$/', $language) ? $language : null;

        $query = array_filter([
            'autoplay' => $autoplay ? 1 : null,
            'start' => $start,
            'cc_load_policy' => $captions ? 1 : null,
            'cc_lang_pref' => $captions ? $language : null,
            'hl' => $language,
            'rel' => 0,
            'playsinline' => 1,
        ], static fn ($value) => $value !== null);

        return 'https://'.($privacy ? 'www.youtube-nocookie.com' : 'www.youtube.com')."/embed/{$id}?".http_build_query($query);
    }

    public static function render(array $d): string
    {
        $b = $d['block'];
        $url = trim((string) ($b['url'] ?? ''));
        $video = self::parse($url);
        $chosen = in_array($b['aspect'] ?? null, self::ASPECTS, true) ? $b['aspect'] : null;
        $aspect = $chosen ?? (($video['short'] ?? false) ? '9/16' : '16/9');

        if ($url === '') {
            return $d['editor'] ? Elements::mediaPlaceholder($d, 'youtube-logo', $aspect, 'div') : '';
        }

        if ($video === null) {
            return $d['editor'] ? self::message($d, $aspect, self::isPlaylist($url)
                ? __('This is a playlist. Use the link of one video in it.')
                : __('This isn’t a link to a YouTube video. Use the link from the video’s Share button.')) : '';
        }

        $found = app(Videos::class)->find($video['id']);

        if ($found['status'] === 'unavailable') {
            return $d['editor'] ? self::message($d, $aspect, __('This video is private, gone, or its owner doesn’t allow it on other sites.')) : '';
        }

        $title = trim((string) ($b['title'] ?? '')) ?: ($found['title'] ?? __('YouTube video'));
        $start = self::seconds((string) ($b['start'] ?? '')) ?? $video['start'];
        $privacy = (bool) ($b['privacy'] ?? true);
        $captions = (bool) ($b['captions'] ?? true);
        $ratio = '<div class="cms-ratio" style="aspect-ratio:'.$aspect.'">';

        if (! ($b['load_on_click'] ?? true)) {
            return '<div '.$d['attrs'].'>'.$ratio.'<iframe src="'.e(self::embed($video['id'], $privacy, $captions, $start)).'" title="'.e($title).'" loading="lazy" allow="autoplay; encrypted-media; picture-in-picture; fullscreen" allowfullscreen referrerpolicy="strict-origin-when-cross-origin"></iframe></div></div>';
        }

        $d['editor'] || $d['renderer']->uses('youtube/facade');
        $watch = $video['short'] ? "https://www.youtube.com/shorts/{$video['id']}" : "https://www.youtube.com/watch?v={$video['id']}".($start ? "&t={$start}s" : '');

        return '<div '.$d['attrs'].'>'.$ratio
            .'<a class="cms-youtube-video__play" href="'.e($watch).'" data-cms-youtube="'.e(self::embed($video['id'], $privacy, $captions, $start, autoplay: true)).'" data-cms-youtube-title="'.e($title).'">'
            .'<span class="cms-youtube-video__poster">'.self::poster($b['poster'] ?? null, $video['id'], $found['poster'], $aspect).'</span>'
            .self::button()
            .'<span class="cms-youtube-video__label">'.e(__('Play video: :title', ['title' => $title])).'</span>'
            .'</a></div></div>';
    }

    /** The poster chosen from the library, else YouTube's own as the site copied it, else YouTube's own from YouTube. */
    private static function poster(mixed $chosen, string $id, ?array $copied, string $aspect): string
    {
        $img = $chosen !== null ? Media::img($chosen, '', ['aspect' => $aspect, 'sizes' => '(min-width: 72rem) 72rem, 100vw']) : '';

        if ($img !== '') {
            return $img;
        }

        if ($copied !== null) {
            return '<img src="'.e($copied['src']).'" alt="" width="'.$copied['width'].'" height="'.$copied['height'].'" loading="lazy" decoding="async">';
        }

        // YouTube couldn't be asked when the page was made: its image host serves the poster, and the site's security policy names it.
        $src = "https://i.ytimg.com/vi/{$id}/hqdefault.jpg";
        cms()->hooks()->dispatch('site.policy.source', 'img', $src);

        return '<img src="'.e($src).'" alt="" width="480" height="360" loading="lazy" decoding="async">';
    }

    /** YouTube's play button, which people know to press. */
    private static function button(): string
    {
        return '<svg class="cms-youtube-video__button" viewBox="0 0 68 48" width="68" height="48" aria-hidden="true" focusable="false">'
            .'<path class="cms-youtube-video__shape" d="M66.5 7.7c-.8-2.9-2.5-5.4-5.4-6.2C55.8.1 34 0 34 0S12.2.1 6.9 1.6C4 2.4 2.3 4.8 1.5 7.7 0 13.1 0 24 0 24s0 10.9 1.5 16.3c.8 2.9 2.5 5.4 5.4 6.2C12.2 47.9 34 48 34 48s21.8-.1 27.1-1.6c2.9-.8 4.6-3.3 5.4-6.2C68 34.9 68 24 68 24s0-10.9-1.5-16.3z"/>'
            .'<path fill="#fff" d="M45 24 27 14v20z"/></svg>';
    }

    /** Editor only: why there is no video to show, in the shape it would have. */
    private static function message(array $d, string $aspect, string $text): string
    {
        return '<div '.$d['attrs'].' data-cms-media><span class="cms-media-placeholder cms-youtube-video__message" style="aspect-ratio:'.$aspect.'">'
            .Icons::svg('youtube-logo').'<span class="cms-placeholder">'.e($text).'</span></span></div>';
    }
}
