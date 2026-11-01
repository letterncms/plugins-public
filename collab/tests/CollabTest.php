<?php

declare(strict_types=1);

namespace Cms\Collab\Tests;

use Cms\Abilities\AbilityContext;
use Cms\Abilities\AbilityException;
use Cms\Collab\Comment;
use Cms\Collab\Comments;
use Cms\Contracts\MailMessage;
use Cms\Contracts\Mailer;
use Cms\Tests\TestCase;
use Cms\Users\User;

final class CollabTest extends TestCase
{
    /** @var list<MailMessage> */
    private array $sent = [];

    protected function plugins(): array
    {
        return ['letterncms/collab'];
    }

    protected function setUp(): void
    {
        parent::setUp();
        $sent = &$this->sent;
        $this->app->instance(Mailer::class, new class($sent) implements Mailer
        {
            public function __construct(private array &$sent) {}

            public function send(MailMessage $message): void
            {
                $this->sent[] = $message;
            }

            public function configured(): bool
            {
                return true;
            }
        });
        $this->app->forgetInstance(Comments::class);
    }

    private function as(User $user, string $ability, array $input): mixed
    {
        return $this->cms()->abilities()->execute($ability, $input, AbilityContext::forUser($user, 'admin'));
    }

    private function page(): string
    {
        return $this->cms()->content()->create('pages', ['title' => 'Launch plan'])->id;
    }

    public function test_presence_shows_who_else_is_here(): void
    {
        $id = $this->page();
        $ada = $this->admin(['name' => 'Ada Lovelace']);
        $bob = $this->userWithRole('editor');

        $this->assertSame([], $this->as($ada, 'collab/presence', ['entryId' => $id])['others']);

        $seen = $this->as($bob, 'collab/presence', ['entryId' => $id, 'editing' => true]);
        $this->assertSame(['Ada Lovelace'], array_column($seen['others'], 'name'));
        $this->assertNotNull($seen['updatedAt']);

        $others = $this->as($ada, 'collab/presence', ['entryId' => $id])['others'];
        $this->assertSame('Editor', $others[0]['name']);
        $this->assertTrue($others[0]['editing']);

        $this->as($bob, 'collab/presence', ['entryId' => $id, 'leave' => true]);
        $this->assertSame([], $this->as($ada, 'collab/presence', ['entryId' => $id])['others']);

        $this->travel(2)->minutes();
        $this->as($bob, 'collab/presence', ['entryId' => $id]);
        $this->travel(1)->minutes();
        $this->assertSame([], $this->as($ada, 'collab/presence', ['entryId' => $id])['others'], 'missed heartbeats drop people');
    }

    public function test_threads_mentions_and_notifications(): void
    {
        $id = $this->page();
        $ada = $this->admin(['name' => 'Ada']);
        $bob = $this->userWithRole('editor');
        $cy = $this->userWithRole('author');

        $people = array_column($this->as($ada, 'comments/people', ['entryId' => $id]), 'id');
        $this->assertContains($bob->id, $people);

        $thread = $this->as($ada, 'comments/create', ['entryId' => $id, 'body' => '@Editor can you check the pricing?', 'mentions' => [$bob->id]]);
        $this->assertSame('Editor', $thread['mentions'][0]['name']);
        $this->assertCount(1, $this->sent);
        $this->assertSame($bob->email, $this->sent[0]->to);
        $this->assertSame('Ada mentioned you on “Launch plan”', $this->sent[0]->subject);

        // Replies attach to the thread (even when answering a reply) and notify everyone in it.
        $reply = $this->as($bob, 'comments/create', ['entryId' => $id, 'body' => 'Done.', 'parentId' => $thread['id']]);
        $this->as($cy, 'comments/create', ['entryId' => $id, 'body' => 'Looks good', 'parentId' => $reply['id']]);
        $this->assertSame([$bob->email, $ada->email, $ada->email, $bob->email], array_map(static fn ($m) => $m->to, $this->sent));

        $this->as($ada, 'comments/resolve', ['id' => $reply['id']]);
        $threads = $this->as($ada, 'comments/list', ['entryId' => $id]);
        $this->assertTrue($threads[0]['resolved']);
        $this->assertCount(2, $threads[0]['replies']);
        $this->assertSame([], $this->as($ada, 'comments/list', ['entryId' => $id, 'resolved' => false]));

        $this->as($bob, 'comments/create', ['entryId' => $id, 'body' => 'One more thing', 'parentId' => $thread['id']]);
        $this->assertFalse($this->as($ada, 'comments/list', ['entryId' => $id])[0]['resolved'], 'a new reply reopens the thread');
    }

    public function test_inbox_lists_open_threads_that_need_me(): void
    {
        $id = $this->page();
        $ada = $this->admin();
        $bob = $this->userWithRole('editor');

        $this->as($ada, 'comments/create', ['entryId' => $id, 'body' => 'Please review', 'mentions' => [$bob->id]]);
        $inbox = $this->as($bob, 'comments/inbox', []);

        $this->assertCount(1, $inbox);
        $this->assertSame('mention', $inbox[0]['reason']);
        $this->assertSame('Launch plan', $inbox[0]['entry']['title']);
        $this->assertSame([], $this->as($ada, 'comments/inbox', []), 'your own comments are not in your inbox');
    }

    public function test_only_people_with_access_can_be_mentioned_or_change_comments(): void
    {
        $id = $this->page();
        $ada = $this->admin();
        $author = $this->userWithRole('author');
        $outsider = User::query()->create(['name' => 'Visitor', 'email' => 'v@example.com', 'password' => 'secret-pass-123', 'roles' => []]);

        try {
            $this->as($ada, 'comments/create', ['entryId' => $id, 'body' => 'Hi', 'mentions' => [$outsider->id]]);
            $this->fail('Mentioning someone without access must fail.');
        } catch (AbilityException $e) {
            $this->assertStringContainsString('can see this entry', $e->getMessage());
        }

        $mine = $this->as($ada, 'comments/create', ['entryId' => $id, 'body' => 'Mine']);

        $this->expectException(AbilityException::class);
        $this->as($author, 'comments/delete', ['id' => $mine['id']]);
    }

    public function test_deleting_an_entry_deletes_its_comments(): void
    {
        $id = $this->page();
        $ada = $this->admin();
        $bob = $this->userWithRole('editor');
        $this->as($ada, 'comments/create', ['entryId' => $id, 'body' => 'Note', 'mentions' => [$bob->id]]);

        $this->cms()->content()->delete($id);

        $this->assertSame(0, Comment::query()->count());
        $this->assertDatabaseCount('comment_mentions', 0);
    }
}
