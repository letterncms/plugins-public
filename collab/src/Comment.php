<?php

declare(strict_types=1);

namespace Cms\Collab;

use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Cms\Users\User;

/**
 * @property string $id
 * @property string $entry_id
 * @property ?string $block_id
 * @property ?string $parent_id
 * @property ?string $user_id
 * @property string $body
 */
class Comment extends Model
{
    use HasUlids;

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['resolved_at' => 'datetime'];
    }

    public function author(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }

    public function replies(): HasMany
    {
        return $this->hasMany(self::class, 'parent_id')->oldest();
    }

    public function mentions(): BelongsToMany
    {
        return $this->belongsToMany(User::class, 'comment_mentions', 'comment_id', 'user_id');
    }

    public function toPublic(?User $viewer = null): array
    {
        return [
            'id' => $this->id,
            'entryId' => $this->entry_id,
            'blockId' => $this->block_id,
            'parentId' => $this->parent_id,
            'body' => $this->body,
            'author' => $this->author ? self::person($this->author) : null,
            'mentions' => $this->mentions->map(static fn (User $u) => self::person($u))->values()->all(),
            'resolved' => $this->resolved_at !== null,
            'resolvedAt' => $this->resolved_at?->toIso8601String(),
            'mine' => $viewer !== null && $viewer->id === $this->user_id,
            'createdAt' => $this->created_at?->toIso8601String(),
            'editedAt' => $this->updated_at && $this->created_at && $this->updated_at->gt($this->created_at->addSeconds(5)) ? $this->updated_at->toIso8601String() : null,
            'replies' => $this->relationLoaded('replies') ? $this->replies->map(static fn (self $r) => $r->toPublic($viewer))->values()->all() : [],
        ];
    }

    /** @return array{id: string, name: string, initials: string, avatar: ?string} */
    public static function person(User $user): array
    {
        return ['id' => $user->id, 'name' => $user->name, 'initials' => $user->initials(), 'avatar' => $user->avatar];
    }
}
