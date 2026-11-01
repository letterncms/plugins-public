<?php

declare(strict_types=1);

namespace Cms\Collab;

use Illuminate\Contracts\Cache\Repository;
use Illuminate\Support\Facades\Cache;
use Cms\Users\User;

/**
 * Who has an entry open, kept in the cache from browser heartbeats (no
 * websockets needed, so it works on shared hosting). People disappear after
 * a missed heartbeat or two.
 */
final class Presence
{
    public const int TTL = 45;

    /** @return list<array{id: string, name: string, initials: string, avatar: ?string, editing: bool, since: int}> Others, not $user */
    public function beat(string $entryId, User $user, bool $editing): array
    {
        return $this->change($entryId, static function (array $people) use ($user, $editing) {
            $people[$user->id] = [...Comment::person($user), 'editing' => $editing, 'at' => now()->getTimestamp(), 'since' => $people[$user->id]['since'] ?? now()->getTimestamp()];

            return $people;
        }, $user->id);
    }

    public function leave(string $entryId, User $user): void
    {
        $this->change($entryId, static function (array $people) use ($user) {
            unset($people[$user->id]);

            return $people;
        }, $user->id);
    }

    private function change(string $entryId, callable $fn, string $self): array
    {
        $key = 'cms-presence:'.$entryId;
        $people = [];

        // Serialize read-modify-write so simultaneous heartbeats don't drop each other.
        Cache::lock($key.':lock', 5)->block(3, function () use ($key, $fn, &$people) {
            $now = now()->getTimestamp();
            $people = array_filter((array) $this->store()->get($key, []), static fn ($p) => ($p['at'] ?? 0) > $now - self::TTL);
            $people = $fn($people);
            $this->store()->put($key, $people, self::TTL * 2);
        });

        return array_values(array_map(
            static fn ($p) => array_diff_key($p, ['at' => 1]),
            array_filter($people, static fn ($p) => $p['id'] !== $self),
        ));
    }

    private function store(): Repository
    {
        return Cache::store();
    }
}
