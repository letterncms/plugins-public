<?php

declare(strict_types=1);

namespace Cms\Spotify;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Throwable;

/**
 * What Spotify says of a link, asked by the site, never by a visitor: whether it can be shown (Spotify's oEmbed
 * answers 404 for what is private or gone) and its title. Kept for a week; a refusal and an unanswered question
 * are asked again after ten minutes.
 */
final class Items
{
    /** @return array{status: 'ok'|'unavailable'|'unknown', title: ?string} */
    public function find(string $kind, string $id): array
    {
        $key = "cms-spotify.{$kind}.{$id}";
        $known = Cache::get($key);

        if (is_array($known)) {
            return $known;
        }

        try {
            $answer = Http::timeout(4)->connectTimeout(3)->acceptJson()->get('https://open.spotify.com/oembed', ['url' => "https://open.spotify.com/{$kind}/{$id}"]);
            $status = match (true) {
                $answer->successful() => 'ok',
                in_array($answer->status(), [400, 401, 403, 404], true) => 'unavailable',
                default => 'unknown',
            };
            $title = $status === 'ok' && is_string($answer->json('title')) ? trim($answer->json('title')) : '';
        } catch (Throwable) {
            $status = 'unknown';
            $title = '';
        }

        $found = ['status' => $status, 'title' => $title !== '' ? $title : null];
        Cache::put($key, $found, $status === 'ok' ? now()->addWeek() : now()->addMinutes(10));

        return $found;
    }
}
