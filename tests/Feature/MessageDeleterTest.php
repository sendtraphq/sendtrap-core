<?php

namespace Sendtrap\Core\Tests\Feature;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use RuntimeException;
use Sendtrap\Core\Contracts\StorageQuota;
use Sendtrap\Core\Models\Attachment;
use Sendtrap\Core\Models\Inbox;
use Sendtrap\Core\Models\Message;
use Sendtrap\Core\Models\Workspace;
use Sendtrap\Core\Storage\MessageDeleter;
use Sendtrap\Core\Tests\Fakes\FakeStorageQuota;
use Sendtrap\Core\Tests\PackageTestCase;

/**
 * MessageDeleter (Plan 01a §5): the one deletion path feeds the exact
 * removed bytes — message size plus attachment sizes — into the
 * StorageQuota removal lifecycle, groups mixed batches per workspace,
 * skips accounting (but never deletion) for workspace-less messages, and
 * treats a broken quota backend as log-and-proceed rather than a blocked
 * delete.
 */
class MessageDeleterTest extends PackageTestCase
{
    protected FakeStorageQuota $quota;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('local');
        $this->quota = new FakeStorageQuota;
        $this->app->instance(StorageQuota::class, $this->quota);
    }

    protected function makeInbox(): Inbox
    {
        $workspace = Workspace::factory()->create();
        $project = $workspace->projects()->create(['name' => 'P']);

        return $project->inboxes()->create(['name' => 'Inbox']);
    }

    public function test_it_commits_the_exact_removed_bytes_for_messages_and_attachments(): void
    {
        $inbox = $this->makeInbox();
        $message = Message::factory()->for($inbox, 'inbox')->create(['size' => 1_000]);
        Attachment::factory()->for($message, 'message')->create(['size' => 300]);
        Attachment::factory()->for($message, 'message')->create(['size' => 200]);

        $deleted = app(MessageDeleter::class)->delete($message->fresh());

        $this->assertSame(1, $deleted);
        $this->assertSame(0, $inbox->messages()->count());

        $workspaceId = $inbox->project->workspace_id;
        $this->assertSame([['workspace' => $workspaceId, 'bytes' => 1_500]], array_map(
            fn ($removal) => ['workspace' => $removal['workspace'], 'bytes' => $removal['bytes']],
            $this->quota->removals,
        ));
        $this->assertSame([['token' => 'rm-1', 'stored' => 0, 'removed' => 1_500]], $this->quota->commits);
    }

    public function test_a_batch_spanning_workspaces_is_accounted_per_workspace(): void
    {
        $inboxA = $this->makeInbox();
        $inboxB = $this->makeInbox();
        Message::factory()->for($inboxA, 'inbox')->create(['size' => 100]);
        Message::factory()->for($inboxA, 'inbox')->create(['size' => 150]);
        Message::factory()->for($inboxB, 'inbox')->create(['size' => 900]);

        $deleted = app(MessageDeleter::class)->delete(Message::query()->get());

        $this->assertSame(3, $deleted);
        $this->assertSame(0, Message::query()->count());

        $bytesByWorkspace = collect($this->quota->removals)
            ->mapWithKeys(fn ($removal) => [$removal['workspace'] => $removal['bytes']])
            ->all();

        $this->assertSame(250, $bytesByWorkspace[$inboxA->project->workspace_id]);
        $this->assertSame(900, $bytesByWorkspace[$inboxB->project->workspace_id]);
        $this->assertCount(2, $this->quota->commits);
    }

    public function test_workspace_less_messages_delete_without_accounting(): void
    {
        $inbox = $this->makeInbox();
        $message = Message::factory()->for($inbox, 'inbox')->create(['size' => 500]);
        DB::table('projects')->where('id', $inbox->project_id)->update(['workspace_id' => null]);

        $deleted = app(MessageDeleter::class)->delete($message->fresh());

        $this->assertSame(1, $deleted);
        $this->assertSame(0, Message::query()->count());
        $this->assertSame([], $this->quota->removals);
        $this->assertSame([], $this->quota->commits);
    }

    public function test_a_broken_quota_backend_never_blocks_the_deletion(): void
    {
        $this->quota->throwOnRemoval = new RuntimeException('redis down');
        $inbox = $this->makeInbox();
        $message = Message::factory()->for($inbox, 'inbox')->create(['size' => 500]);

        $deleted = app(MessageDeleter::class)->delete($message->fresh());

        $this->assertSame(1, $deleted);
        $this->assertSame(0, Message::query()->count());
        $this->assertSame([], $this->quota->commits);
    }

    public function test_an_empty_batch_is_a_no_op(): void
    {
        $this->assertSame(0, app(MessageDeleter::class)->delete(Message::query()->get()));
        $this->assertSame([], $this->quota->removals);
    }
}
