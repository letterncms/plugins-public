<?php

declare(strict_types=1);

namespace Cms\Collab;

use Illuminate\Database\Eloquent\Builder;
use Cms\Abilities\AbilityException;
use Cms\Admin\Translations;
use Cms\Content\Entries\Entry;
use Cms\Contracts\MailMessage;
use Cms\Contracts\Mailer;
use Cms\Extend\Hooks;
use Cms\Cms;
use Cms\Users\User;
use Throwable;

/** Comment threads on entries, with @mentions that notify people by email. */
final class Comments
{
    public function __construct(private readonly Cms $cms, private readonly Hooks $hooks, private readonly Mailer $mailer) {}

    /** @return list<array> Threads (first comment + replies), open ones first. */
    public function threads(Entry $entry, User $viewer, bool $resolved = true): array
    {
        return Comment::query()
            ->where('entry_id', $entry->id)
            ->whereNull('parent_id')
            ->when(! $resolved, static fn (Builder $q) => $q->whereNull('resolved_at'))
            ->with(['author', 'mentions', 'replies.author', 'replies.mentions'])
            ->orderByRaw('resolved_at is not null')
            ->oldest()
            ->get()
            ->map(static fn (Comment $c) => $c->toPublic($viewer))
            ->all();
    }

    /** @param list<string> $mentions user ids */
    public function create(Entry $entry, User $author, string $body, array $mentions = [], ?string $parentId = null, ?string $blockId = null): Comment
    {
        $body = trim($body);

        if ($body === '') {
            throw AbilityException::invalid(__('Write something first.'));
        }

        $parent = null;

        if ($parentId !== null) {
            $parent = Comment::query()->where('entry_id', $entry->id)->find($parentId) ?? throw AbilityException::invalid(__('That thread does not exist on this entry.'));
            // Replies always attach to the thread's first comment.
            $parent = $parent->parent_id ? Comment::query()->findOrFail($parent->parent_id) : $parent;
        }

        $people = $this->mentionable($entry, $mentions);

        $comment = Comment::query()->create([
            'entry_id' => $entry->id,
            'block_id' => $parent ? $parent->block_id : $blockId,
            'parent_id' => $parent?->id,
            'user_id' => $author->id,
            'body' => mb_substr($body, 0, 5000),
        ]);
        $comment->mentions()->sync(array_map(static fn (User $u) => $u->id, $people));

        // A reply reopens a resolved thread: someone still has something to say.
        if ($parent?->resolved_at) {
            $parent->update(['resolved_at' => null, 'resolved_by' => null]);
        }

        $this->notify($comment, $entry, $author, $people, $parent);
        $this->hooks->dispatch('comment.created', $comment, $entry, $author);

        return $comment->load(['author', 'mentions']);
    }

    /** People who may be mentioned on an entry: admin users who can see it. */
    public function people(?Entry $entry = null): array
    {
        return User::query()->orderBy('name')->get()
            ->filter(fn (User $u) => $u->hasPermission('admin.access') && (! $entry || $u->hasPermission("entries.{$entry->collection}.view")))
            ->map(static fn (User $u) => Comment::person($u))
            ->values()
            ->all();
    }

    /** @return list<User> */
    private function mentionable(Entry $entry, array $ids): array
    {
        $ids = array_values(array_unique(array_filter($ids, 'is_string')));

        if ($ids === []) {
            return [];
        }

        $users = User::query()->whereIn('id', $ids)->get()->all();
        $allowed = array_filter($users, static fn (User $u) => $u->hasPermission('admin.access') && $u->hasPermission("entries.{$entry->collection}.view"));

        if (count($allowed) !== count($ids)) {
            throw AbilityException::invalid(__('You can only mention people who can see this entry.'));
        }

        return array_values($allowed);
    }

    /** Email mentioned people and, for replies, everyone already in the thread. */
    private function notify(Comment $comment, Entry $entry, User $author, array $mentioned, ?Comment $thread): void
    {
        $recipients = [];

        foreach ($mentioned as $user) {
            $recipients[$user->id] = [$user, 'mention'];
        }

        if ($thread) {
            $ids = Comment::query()->where('id', $thread->id)->orWhere('parent_id', $thread->id)->pluck('user_id')->filter()->unique()->all();

            foreach (User::query()->whereIn('id', $ids)->get() as $user) {
                $recipients[$user->id] ??= [$user, 'reply'];
            }
        }

        unset($recipients[$author->id]);

        $url = rtrim((string) config('app.url'), '/').'/admin/content/'.$entry->collection.'/'.$entry->id;

        $translations = $this->cms->app()->make(Translations::class);
        $previous = $translations->current();

        foreach ($recipients as [$user, $kind]) {
            // In the recipient's language: their own, else the admin's default.
            $translations->apply($translations->locale($user));
            $names = ['name' => $author->name, 'title' => $entry->title];
            $headline = $kind === 'mention' ? __(':name mentioned you on “:title”', $names) : __(':name replied on “:title”', $names);

            try {
                $this->mailer->send(new MailMessage(
                    to: $user->email,
                    subject: $headline,
                    html: '<p>'.e($headline).'</p>'
                        .'<blockquote style="margin:16px 0;padding:8px 16px;border-left:3px solid #ddd;color:#333">'.nl2br(e($comment->body)).'</blockquote>'
                        .'<p><a href="'.e($url).'">'.e(__('Open in :brand')).'</a></p>',
                    text: "{$headline}\n\n{$comment->body}\n\n{$url}",
                    tag: 'comment',
                ));
            } catch (Throwable $e) {
                report($e); // A failed email never loses the comment.
            } finally {
                $translations->apply($previous);
            }
        }
    }
}
