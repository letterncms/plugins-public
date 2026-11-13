<?php

declare(strict_types=1);

namespace Cms\Spotify;

use Cms\Support\Files;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Throwable;

/**
 * What Spotify says of a link, asked by the site, never by a visitor: whether it can be shown (Spotify's oEmbed
 * answers 404 for what is private or gone), its title, and its cover, copied into the site (public/_cms/spotify/)
 * with the colour it is mostly made of, so the card in the player's place shows it without contacting Spotify.
 * Kept for a week; a refusal and an unanswered question are asked again after ten minutes.
 */
final class Items
{
    public const string FOLDER = '_cms/spotify';

    /** @return array{status: 'ok'|'unavailable'|'unknown', title: ?string, cover: ?array{src: string, width: int, height: int, color: ?string}} */
    public function find(string $kind, string $id): array
    {
        $key = "cms-spotify.{$kind}.{$id}";
        $known = Cache::get($key);

        if (is_array($known) && array_key_exists('cover', $known) && ($known['cover'] === null || is_file(public_path(ltrim(strtok($known['cover']['src'], '?'), '/'))))) {
            return $known;
        }

        $cover = null;

        try {
            $answer = Http::timeout(4)->connectTimeout(3)->acceptJson()->get('https://open.spotify.com/oembed', ['url' => "https://open.spotify.com/{$kind}/{$id}"]);
            $status = match (true) {
                $answer->successful() => 'ok',
                in_array($answer->status(), [400, 401, 403, 404], true) => 'unavailable',
                default => 'unknown',
            };
            $title = $status === 'ok' && is_string($answer->json('title')) ? trim($answer->json('title')) : '';
            $thumbnail = $status === 'ok' ? $answer->json('thumbnail_url') : null;
            $cover = is_string($thumbnail) ? $this->copyCover($kind, $id, $thumbnail) : null;
        } catch (Throwable) {
            $status = 'unknown';
            $title = '';
        }

        // Without an answer, a cover copied before still serves.
        $found = ['status' => $status, 'title' => $title !== '' ? $title : null, 'cover' => $status === 'unavailable' ? null : ($cover ?? $this->copied($kind, $id))];
        Cache::put($key, $found, $status === 'ok' ? now()->addWeek() : now()->addMinutes(10));

        return $found;
    }

    /** Spotify's cover, written into the site: only from Spotify's own image host, and only a JPEG or PNG. */
    private function copyCover(string $kind, string $id, string $url): ?array
    {
        if (preg_match('#^https://(?:i|mosaic|image-cdn-[a-z]{2})\.scdn\.co/[A-Za-z0-9/_-]+$#D', $url) !== 1) {
            return null;
        }

        try {
            $answer = Http::timeout(6)->connectTimeout(3)->get($url);
        } catch (Throwable) {
            return null;
        }

        $size = $answer->successful() ? @getimagesizefromstring($answer->body()) : false;

        if ($size === false || ! in_array($size[2], [IMAGETYPE_JPEG, IMAGETYPE_PNG], true) || $size[0] < 64) {
            return null;
        }

        $image = @imagecreatefromstring($answer->body());

        if ($image === false) {
            return null;
        }

        // Always a JPEG, so one name serves whatever Spotify sent.
        ob_start();
        imagejpeg($image, null, 86);
        Files::put(public_path(self::FOLDER."/{$kind}-{$id}.jpg"), (string) ob_get_clean());

        return $this->copied($kind, $id);
    }

    private function copied(string $kind, string $id): ?array
    {
        $path = public_path(self::FOLDER."/{$kind}-{$id}.jpg");
        $size = is_file($path) ? @getimagesize($path) : false;

        if ($size === false) {
            return null;
        }

        return [
            'src' => '/'.self::FOLDER."/{$kind}-{$id}.jpg?v=".substr((string) hash_file('xxh3', $path), 0, 8),
            'width' => (int) $size[0],
            'height' => (int) $size[1],
            'color' => self::color($path),
        ];
    }

    /** The colour a cover is mostly made of, deepened as Spotify's player deepens it, so white text reads on it. */
    public static function color(string $path): ?string
    {
        $image = function_exists('imagecreatefromjpeg') ? @imagecreatefromjpeg($path) : false;

        if ($image === false) {
            return null;
        }

        $dot = imagecreatetruecolor(1, 1);
        imagecopyresampled($dot, $image, 0, 0, 0, 0, 1, 1, imagesx($image), imagesy($image));
        $rgb = imagecolorat($dot, 0, 0);
        [$r, $g, $b] = [($rgb >> 16) & 255, ($rgb >> 8) & 255, $rgb & 255];

        // At most this bright: white on it keeps a contrast of 4.5 to 1.
        $deepen = static fn (int $c) => (int) round($c * 0.55);
        [$r, $g, $b] = [$deepen($r), $deepen($g), $deepen($b)];
        $luminance = static fn (int $c) => ($v = $c / 255) <= 0.03928 ? $v / 12.92 : (($v + 0.055) / 1.055) ** 2.4;

        while ((0.2126 * $luminance($r) + 0.7152 * $luminance($g) + 0.0722 * $luminance($b)) > 0.18) {
            [$r, $g, $b] = [(int) ($r * 0.9), (int) ($g * 0.9), (int) ($b * 0.9)];
        }

        return sprintf('#%02x%02x%02x', $r, $g, $b);
    }
}
