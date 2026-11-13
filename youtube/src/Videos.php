<?php

declare(strict_types=1);

namespace Cms\Youtube;

use Cms\Support\Files;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Throwable;

/**
 * What YouTube says of a video, asked by the site, never by a visitor: whether it can be shown on other sites
 * (YouTube's oEmbed answers 401 for a private video or one whose owner doesn't allow it, 404 for one that is
 * gone), its title, and its poster, copied into the site (public/_cms/youtube/) so a page shows it without
 * contacting YouTube. Kept for a week; a refusal and an unanswered question are asked again after ten minutes.
 */
final class Videos
{
    public const string FOLDER = '_cms/youtube';

    /** The posters YouTube may have, largest first: the first two only for videos uploaded in HD. */
    private const array POSTERS = ['maxresdefault', 'sddefault', 'hqdefault'];

    /** @return array{status: 'ok'|'unavailable'|'unknown', title: ?string, poster: ?array{src: string, width: int, height: int}} */
    public function find(string $id): array
    {
        $key = 'cms-youtube.video.'.$id;
        $known = Cache::get($key);

        if (is_array($known) && ($known['status'] !== 'ok' || $known['poster'] === null || is_file(public_path(ltrim(strtok($known['poster']['src'], '?'), '/'))))) {
            return $known;
        }

        $found = $this->ask($id);
        Cache::put($key, $found, $found['status'] === 'ok' ? now()->addWeek() : now()->addMinutes(10));

        return $found;
    }

    private function ask(string $id): array
    {
        try {
            $answer = Http::timeout(4)->connectTimeout(3)->acceptJson()->get('https://www.youtube.com/oembed', ['url' => 'https://www.youtube.com/watch?v='.$id, 'format' => 'json']);
            $status = match (true) {
                $answer->successful() => 'ok',
                in_array($answer->status(), [400, 401, 403, 404], true) => 'unavailable',
                default => 'unknown',
            };
            $title = $status === 'ok' && is_string($answer->json('title')) ? trim($answer->json('title')) : null;
        } catch (Throwable) {
            $status = 'unknown';
            $title = null;
        }

        return [
            'status' => $status,
            'title' => $title !== '' ? $title : null,
            // Without an answer, a poster copied before still serves; nothing is fetched while YouTube can't be reached.
            'poster' => match ($status) {
                'ok' => $this->copyPoster($id) ?? $this->copied($id),
                'unknown' => $this->copied($id),
                default => null,
            },
        ];
    }

    /** The largest poster YouTube has for the video, written into the site. */
    private function copyPoster(string $id): ?array
    {
        foreach (self::POSTERS as $name) {
            try {
                $answer = Http::timeout(6)->connectTimeout(3)->get("https://i.ytimg.com/vi/{$id}/{$name}.jpg");
            } catch (Throwable) {
                return null;
            }

            $size = $answer->successful() ? @getimagesizefromstring($answer->body()) : false;

            // A poster that isn't there comes as a 404 with a small grey picture.
            if ($size !== false && $size[2] === IMAGETYPE_JPEG && $size[0] >= 320) {
                Files::put(public_path(self::FOLDER.'/'.$id.'.jpg'), $answer->body());

                return $this->copied($id);
            }
        }

        return null;
    }

    private function copied(string $id): ?array
    {
        $path = public_path(self::FOLDER.'/'.$id.'.jpg');
        $size = is_file($path) ? @getimagesize($path) : false;

        return $size === false ? null : ['src' => '/'.self::FOLDER.'/'.$id.'.jpg?v='.substr((string) hash_file('xxh3', $path), 0, 8), 'width' => (int) $size[0], 'height' => (int) $size[1]];
    }
}
