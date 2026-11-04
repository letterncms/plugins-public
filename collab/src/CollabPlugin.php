<?php

declare(strict_types=1);

namespace Cms\Collab;

use Cms\Abilities\AbilityContext;
use Cms\Abilities\AbilityException;
use Cms\Content\Entries\Entry;
use Cms\Packages\Plugin;
use Cms\Users\User;

final class CollabPlugin extends Plugin
{
    public function register(): void
    {
        $this->app->singleton(Presence::class);
        $this->app->singleton(Comments::class);
    }

    public function boot(): void
    {
        $p = $this->cms()->permissions();
        $p->register('comments.view', __('View Comments'), __('Comments'));
        $p->register('comments.create', __('Write and Resolve Comments'), __('Comments'));
        $p->register('comments.moderate', __('Edit and Delete Anyone’s Comments'), __('Comments'));

        $this->abilities();

        $this->cms()->hooks()->on('entry.deleted', static function (Entry $entry): void {
            $ids = Comment::query()->where('entry_id', $entry->id)->pluck('id');
            \Illuminate\Support\Facades\DB::table('comment_mentions')->whereIn('comment_id', $ids)->delete();
            Comment::query()->where('entry_id', $entry->id)->delete();
        });

        $this->cms()->admin()->widget('comments-inbox', ['label' => 'Comments', 'description' => __('Open threads that mention you or sit on your entries.'), 'icon' => 'comment', 'column' => 'main', 'order' => 30, 'permission' => 'comments.view']);
        $this->adminModule(import: '@letterncms/collab');
    }

    private function abilities(): void
    {
        $a = $this->cms()->abilities();
        $comments = fn () => $this->app->make(Comments::class);
        $entry = fn (string $id) => $this->cms()->entries()->findOrFail($id);
        // Comment abilities need both the comment permission and access to the entry itself.
        $on = fn (string $permission) => function (?User $user, array $in) use ($permission) {
            $id = $in['entryId'] ?? Comment::query()->find($in['id'] ?? '')?->entry_id;
            $target = $id ? $this->cms()->entries()->find((string) $id) : null;

            return $user !== null && $target !== null && $user->hasPermission($permission) && $user->hasPermission("entries.{$target->collection}.view");
        };
        $id = ['type' => 'string'];

        $a->register('collab/presence', [
            'label' => 'Presence Heartbeat',
            'description' => 'Say you have an entry open (and whether you are editing); returns who else has it open and when it was last saved.',
            'permission' => fn (?User $u, array $in) => $u !== null && ($t = $this->cms()->entries()->find((string) $in['entryId'])) !== null && $u->hasPermission("entries.{$t->collection}.view"),
            'annotations' => ['readOnly' => true],
            'expose' => ['rest' => false, 'mcp' => false, 'cli' => false, 'assistant' => false],
            'package' => $this->manifest->name,
            'input' => ['type' => 'object', 'properties' => ['entryId' => $id, 'editing' => ['type' => 'boolean', 'default' => false], 'leave' => ['type' => 'boolean', 'default' => false]], 'required' => ['entryId']],
            'handler' => function (array $in, AbilityContext $ctx) use ($entry) {
                $presence = $this->app->make(Presence::class);
                $target = $entry($in['entryId']);

                if ($in['leave']) {
                    $presence->leave($target->id, $ctx->user);

                    return ['others' => []];
                }

                $last = $this->app->make(\Cms\Content\Revisions\Revisions::class)->list($target->id, 1)[0] ?? null;

                return [
                    'others' => $presence->beat($target->id, $ctx->user, $in['editing']),
                    'updatedAt' => $target->updatedAt?->toIso8601String(),
                    'updatedBy' => $last['user'] ?? null,
                ];
            },
        ]);

        $a->register('comments/list', [
            'label' => 'List Comments',
            'description' => 'Comment threads on an entry (open threads first), with replies and mentions.',
            'permission' => $on('comments.view'),
            'annotations' => ['readOnly' => true],
            'package' => $this->manifest->name,
            'input' => ['type' => 'object', 'properties' => ['entryId' => $id, 'resolved' => ['type' => 'boolean', 'default' => true, 'description' => 'Include resolved threads']], 'required' => ['entryId']],
            'handler' => fn (array $in, AbilityContext $ctx) => $comments()->threads($entry($in['entryId']), $ctx->user, $in['resolved']),
        ]);

        $a->register('comments/create', [
            'label' => 'Comment',
            'description' => 'Comment on an entry, or reply to a thread with parentId. mentions are user ids (see comments/people); they get an email.',
            'permission' => $on('comments.create'),
            'package' => $this->manifest->name,
            'input' => [
                'type' => 'object',
                'properties' => [
                    'entryId' => $id,
                    'body' => ['type' => 'string', 'minLength' => 1, 'maxLength' => 5000],
                    'parentId' => $id,
                    'blockId' => ['type' => 'string', 'maxLength' => 40],
                    'mentions' => ['type' => 'array', 'items' => $id, 'maxItems' => 20, 'default' => []],
                ],
                'required' => ['entryId', 'body'],
            ],
            'handler' => fn (array $in, AbilityContext $ctx) => $comments()->create($entry($in['entryId']), $ctx->user, $in['body'], $in['mentions'], $in['parentId'] ?? null, $in['blockId'] ?? null)->toPublic($ctx->user),
        ]);

        $a->register('comments/update', [
            'label' => 'Edit Comment',
            'description' => 'Change the text of your comment.',
            'permission' => $on('comments.create'),
            'annotations' => ['idempotent' => true],
            'package' => $this->manifest->name,
            'input' => ['type' => 'object', 'properties' => ['id' => $id, 'body' => ['type' => 'string', 'minLength' => 1, 'maxLength' => 5000]], 'required' => ['id', 'body']],
            'handler' => function (array $in, AbilityContext $ctx) {
                $comment = $this->own(Comment::query()->findOrFail($in['id']), $ctx->user);
                $comment->update(['body' => trim($in['body'])]);

                return $comment->load(['author', 'mentions'])->toPublic($ctx->user);
            },
        ]);

        $a->register('comments/resolve', [
            'label' => 'Resolve Thread',
            'description' => 'Mark a thread as resolved (or reopen it with resolved: false).',
            'permission' => $on('comments.create'),
            'package' => $this->manifest->name,
            'input' => ['type' => 'object', 'properties' => ['id' => $id, 'resolved' => ['type' => 'boolean', 'default' => true]], 'required' => ['id']],
            'handler' => function (array $in, AbilityContext $ctx) {
                $comment = Comment::query()->findOrFail($in['id']);
                $thread = $comment->parent_id ? Comment::query()->findOrFail($comment->parent_id) : $comment;
                $thread->update(['resolved_at' => $in['resolved'] ? now() : null, 'resolved_by' => $in['resolved'] ? $ctx->user?->id : null]);

                return ['id' => $thread->id, 'resolved' => (bool) $in['resolved']];
            },
        ]);

        $a->register('comments/delete', [
            'label' => 'Delete Comment',
            'description' => 'Delete your comment (moderators can delete any). Deleting a thread’s first comment deletes its replies.',
            'permission' => $on('comments.create'),
            'annotations' => ['destructive' => true],
            'package' => $this->manifest->name,
            'input' => ['type' => 'object', 'properties' => ['id' => $id], 'required' => ['id']],
            'handler' => function (array $in, AbilityContext $ctx) {
                $comment = $this->own(Comment::query()->findOrFail($in['id']), $ctx->user);
                $ids = Comment::query()->where('id', $comment->id)->orWhere('parent_id', $comment->id)->pluck('id');
                \Illuminate\Support\Facades\DB::table('comment_mentions')->whereIn('comment_id', $ids)->delete();
                Comment::query()->whereIn('id', $ids)->delete();

                return ['deleted' => count($ids)];
            },
        ]);

        $a->register('comments/people', [
            'label' => 'People to Mention',
            'description' => 'Admin users who can see an entry and can be @mentioned on it.',
            'permission' => $on('comments.create'),
            'annotations' => ['readOnly' => true],
            'package' => $this->manifest->name,
            'input' => ['type' => 'object', 'properties' => ['entryId' => $id], 'required' => ['entryId']],
            'handler' => fn (array $in) => $comments()->people($entry($in['entryId'])),
        ]);

        $a->register('comments/inbox', [
            'label' => 'Comments for Me',
            'description' => 'Open threads that mention you or sit on entries you created, newest first.',
            'permission' => 'comments.view',
            'annotations' => ['readOnly' => true],
            'package' => $this->manifest->name,
            'input' => ['type' => 'object', 'properties' => ['limit' => ['type' => 'integer', 'minimum' => 1, 'maximum' => 50, 'default' => 10]]],
            'handler' => fn (array $in, AbilityContext $ctx) => $this->inbox($ctx->user, $in['limit']),
        ]);
    }

    private function own(Comment $comment, ?User $user): Comment
    {
        if (! $user || ($comment->user_id !== $user->id && ! $user->hasPermission('comments.moderate'))) {
            throw AbilityException::forbidden(__('Only the author or a moderator can change this comment.'));
        }

        return $comment;
    }

    private function inbox(User $user, int $limit): array
    {
        $mentioned = \Illuminate\Support\Facades\DB::table('comment_mentions')->where('user_id', $user->id)->pluck('comment_id');
        $authored = $this->cms()->entries()->query()->where('author_id', $user->id)->limit(500)->get();
        $entryIds = array_map(static fn (Entry $e) => $e->id, $authored);

        $comments = Comment::query()
            ->with(['author', 'mentions'])
            ->where(fn ($q) => $q->whereIn('id', $mentioned)->orWhereIn('entry_id', $entryIds))
            ->where(fn ($q) => $q->whereNull('user_id')->orWhere('user_id', '!=', $user->id))
            ->latest()
            ->limit($limit * 3)
            ->get();

        $entries = [];
        foreach ($this->cms()->entries()->findMany($comments->pluck('entry_id')->unique()->all()) as $e) {
            $entries[$e->id] = $e;
        }

        $out = [];

        foreach ($comments as $comment) {
            $thread = $comment->parent_id ? Comment::query()->find($comment->parent_id) : $comment;
            $target = $entries[$comment->entry_id] ?? null;

            if (! $thread || $thread->resolved_at || ! $target || ! $user->hasPermission("entries.{$target->collection}.view") || isset($out[$thread->id])) {
                continue;
            }

            $out[$thread->id] = [
                ...$comment->toPublic($user),
                'threadId' => $thread->id,
                'entry' => ['id' => $target->id, 'title' => $target->title, 'collection' => $target->collection],
                'reason' => $comment->mentions->contains('id', $user->id) ? 'mention' : 'yours',
            ];

            if (count($out) >= $limit) {
                break;
            }
        }

        return array_values($out);
    }
}
