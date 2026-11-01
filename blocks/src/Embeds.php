<?php

declare(strict_types=1);

namespace Cms\Blocks;

/** Turns share URLs from trusted providers into embed URLs. Unknown hosts are refused. */
final class Embeds
{
    /** @return array{kind: string, src: string}|null */
    public static function video(string $url, bool $autoplay = false): ?array
    {
        $url = trim($url);

        if (preg_match('#^https://(?:www\.|m\.)?(?:youtube\.com/(?:watch\?v=|embed/|shorts/)|youtu\.be/)([\w-]{6,20})#', $url, $m)) {
            return ['kind' => 'iframe', 'src' => "https://www.youtube-nocookie.com/embed/{$m[1]}".($autoplay ? "?autoplay=1&mute=1&loop=1&playlist={$m[1]}" : '')];
        }

        if (preg_match('#^https://(?:www\.)?vimeo\.com/(\d+)#', $url, $m)) {
            return ['kind' => 'iframe', 'src' => "https://player.vimeo.com/video/{$m[1]}?dnt=1".($autoplay ? '&autoplay=1&muted=1&loop=1&background=1' : '')];
        }

        if (preg_match('#^(https://|/)[^\s"\'<>]+\.(mp4|webm|mov)(\?.*)?$#i', $url)) {
            return ['kind' => 'file', 'src' => $url];
        }

        return null;
    }

    public static function iframe(string $url): ?string
    {
        $url = trim($url);

        if ($video = self::video($url)) {
            return $video['kind'] === 'iframe' ? $video['src'] : null;
        }

        $patterns = [
            '#^https://www\.google\.com/maps/embed\?[^\s"\'<>]+$#' => static fn ($m) => $m[0],
            '#^https://open\.spotify\.com/(track|album|playlist|episode|show)/(\w+)#' => static fn ($m) => "https://open.spotify.com/embed/{$m[1]}/{$m[2]}",
            '#^https://(?:www\.)?loom\.com/share/(\w+)#' => static fn ($m) => "https://www.loom.com/embed/{$m[1]}",
            '#^https://codepen\.io/([\w-]+)/pen/(\w+)#' => static fn ($m) => "https://codepen.io/{$m[1]}/embed/{$m[2]}?default-tab=result",
            '#^https://(?:www\.)?figma\.com/(file|design|proto)/[\w-]+[^\s"\'<>]*$#' => static fn ($m) => 'https://www.figma.com/embed?embed_host=cms&url='.rawurlencode($m[0]),
            '#^https://calendly\.com/[\w-]+(/[\w-]+)?$#' => static fn ($m) => $m[0],
        ];

        foreach ($patterns as $pattern => $build) {
            if (preg_match($pattern, $url, $m)) {
                return $build($m);
            }
        }

        return null;
    }
}
