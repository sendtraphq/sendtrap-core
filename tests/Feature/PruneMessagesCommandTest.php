<?php

namespace Sendtrap\Core\Tests\Feature;

use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Sendtrap\Core\Contracts\Entitlements;
use Sendtrap\Core\Contracts\Workspace as WorkspaceContract;
use Sendtrap\Core\Contracts\WorkspaceContext;
use Sendtrap\Core\Contracts\WorkspaceEntitlements;
use Sendtrap\Core\Exceptions\UnresolvedWorkspaceOwnerException;
use Sendtrap\Core\Models\Attachment;
use Sendtrap\Core\Models\Inbox;
use Sendtrap\Core\Models\Message;
use Sendtrap\Core\Models\Workspace;
use Sendtrap\Core\Tests\Fakes\AllWorkspacesContext;
use Sendtrap\Core\Tests\Fakes\FakeWorkspaceEntitlements;
use Sendtrap\Core\Tests\PackageTestCase;

/**
 * The package-side
 * equivalent of a host-level prune suite, covering the
 * workspace-rooted retention loop of
 * `Sendtrap\Core\Console\Commands\PruneMessages::pruneByRetention()`
 * (src/Console/Commands/PruneMessages.php:65-131) —
 * this file's whole run stays on that loop, never the
 * `Project::whereNull('workspace_id')->exists()` legacy-fallback gate at
 * the top of the method, since every fixture project here is created via
 * `$workspace->projects()->create()` and so always has a workspace_id.
 *
 * retentionDays is supplied per-workspace via a small anonymous
 * Entitlements binding wrapping FakeWorkspaceEntitlements (retentionDays
 * isn't itself configurable through the package's shared FakeEntitlements,
 * which answers with one fixed WorkspaceEntitlements for every workspace —
 * the orphan-skip case here needs two workspaces to answer differently: one
 * throws, one doesn't). WorkspaceContext is overridden to
 * AllWorkspacesContext (new this finding, tests/Fakes/AllWorkspacesContext
 * .php) so the outer loop actually iterates more than the package's
 * Testbench-default single fixed workspace.
 *
 * Mirrors: PruneMessagesTest::
 * test_it_deletes_messages_past_the_teams_plan_retention_window,
 * test_it_keeps_messages_within_the_retention_window,
 * test_it_removes_the_raw_and_attachment_files_for_a_pruned_message,
 * test_a_null_retention_plan_keeps_every_message,
 * test_dry_run_deletes_nothing. The orphan-workspace-skip case
 * (`prune.orphan_workspace_skipped`, PruneMessages.php:118) has no host
 * sentinel of its own (the host's Team-rooted implementation had no
 * equivalent branch) — added directly per the H-2(c) instruction.
 */
class PruneMessagesCommandTest extends PackageTestCase
{
    /**
     * PackageTestCase's own sqlite :memory: connection config (§5.3) leaves
     * `foreign_key_constraints` unset, which — per Illuminate's own
     * SQLiteConnector::configureForeignKeyConstraints() — means the pragma
     * is never touched and stock sqlite's own foreign-key enforcement
     * default (off) applies. The `attachments.message_id` FK's
     * `cascadeOnDelete()` (database/migrations/2026_06_25_100004_create_
     * attachments_table.php:13) that Message::booted()'s deleting hook
     * relies on (`// attachment DB rows cascade via FK`, src/Models/
     * Message.php:84) is therefore inert under the package harness unless a
     * test turns enforcement on for itself — every host application's own
     * real `database.php` config defaults `foreign_key_constraints` to
     * `true`, so this override reproduces production behavior for this
     * file's own attachment-cascade assertion rather than a package-harness
     * quirk.
     */
    protected function getEnvironmentSetUp($app): void
    {
        parent::getEnvironmentSetUp($app);

        $app['config']->set('database.connections.testbench.foreign_key_constraints', true);
    }

    protected function makeWorkspace(): Workspace
    {
        return Workspace::factory()->create();
    }

    protected function makeInbox(Workspace $workspace): Inbox
    {
        $project = $workspace->projects()->create(['name' => 'P']);

        return $project->inboxes()->create(['name' => 'Inbox']);
    }

    protected function makeMessage(Inbox $inbox, \DateTimeInterface $receivedAt): Message
    {
        $path = 'messages/'.Str::uuid()->toString().'.eml';
        Storage::disk('local')->put($path, 'raw contents');

        return $inbox->messages()->create([
            'from_address' => 'a@example.com',
            'to' => [],
            'cc' => [],
            'subject' => 'Test',
            'size' => 12,
            'has_html' => false,
            'has_text' => true,
            'has_attachments' => false,
            'raw_path' => $path,
            'received_at' => $receivedAt,
        ]);
    }

    /**
     * Binds Entitlements::for($workspace)->retentionDays() to a fixed answer
     * per workspace id, keyed by the id AllWorkspacesContext's Workspace::all()
     * will actually yield — throwing UnresolvedWorkspaceOwnerException for
     * any workspace id listed in $orphanIds instead (the fallback trigger
     * PruneMessages::pruneByRetention() catches at the per-workspace level,
     * see the class docblock).
     *
     * @param  array<int|string, ?int>  $retentionByWorkspaceId
     * @param  array<int, int|string>  $orphanIds
     */
    protected function bindPerWorkspaceEntitlements(array $retentionByWorkspaceId, array $orphanIds = []): void
    {
        $this->app->instance(WorkspaceContext::class, new AllWorkspacesContext);

        $this->app->instance(Entitlements::class, new class($retentionByWorkspaceId, $orphanIds) implements Entitlements
        {
            public function __construct(
                private readonly array $retentionByWorkspaceId,
                private readonly array $orphanIds,
            ) {}

            public function for(WorkspaceContract $workspace): WorkspaceEntitlements
            {
                if (in_array($workspace->id(), $this->orphanIds, true)) {
                    throw new UnresolvedWorkspaceOwnerException($workspace);
                }

                return new FakeWorkspaceEntitlements(
                    retentionDays: $this->retentionByWorkspaceId[$workspace->id()] ?? null,
                );
            }
        });
    }

    public function test_it_deletes_messages_past_the_workspaces_retention_window_and_keeps_the_rest(): void
    {
        Storage::fake('local');
        $workspace = $this->makeWorkspace();
        $inbox = $this->makeInbox($workspace);
        $this->bindPerWorkspaceEntitlements([$workspace->id() => 7]);

        $old = $this->makeMessage($inbox, now()->subDays(10));
        $recent = $this->makeMessage($inbox, now()->subDays(2));

        $this->artisan('mail:prune')->assertSuccessful();

        $this->assertNull(Message::find($old->id));
        $this->assertNotNull(Message::find($recent->id));
    }

    public function test_it_removes_the_raw_and_attachment_files_for_a_pruned_message(): void
    {
        Storage::fake('local');
        $workspace = $this->makeWorkspace();
        $inbox = $this->makeInbox($workspace);
        $this->bindPerWorkspaceEntitlements([$workspace->id() => 7]);

        $message = $this->makeMessage($inbox, now()->subDays(10));
        $attachmentPath = 'messages/attachments/'.$message->id.'/note.txt';
        Storage::disk('local')->put($attachmentPath, 'attachment bytes');
        $message->attachments()->create([
            'filename' => 'note.txt',
            'content_type' => 'text/plain',
            'size' => 17,
            'path' => $attachmentPath,
            'is_inline' => false,
        ]);

        $this->artisan('mail:prune')->assertSuccessful();

        Storage::disk('local')->assertMissing($message->raw_path);
        Storage::disk('local')->assertMissing($attachmentPath);
        $this->assertSame(0, Attachment::count());
    }

    public function test_a_null_retention_keeps_every_message(): void
    {
        Storage::fake('local');
        $workspace = $this->makeWorkspace();
        $inbox = $this->makeInbox($workspace);
        $this->bindPerWorkspaceEntitlements([$workspace->id() => null]);

        $message = $this->makeMessage($inbox, now()->subYears(5));

        $this->artisan('mail:prune')->assertSuccessful();

        $this->assertNotNull(Message::find($message->id));
    }

    public function test_dry_run_deletes_nothing(): void
    {
        Storage::fake('local');
        $workspace = $this->makeWorkspace();
        $inbox = $this->makeInbox($workspace);
        $this->bindPerWorkspaceEntitlements([$workspace->id() => 7]);

        $old = $this->makeMessage($inbox, now()->subDays(10));

        $this->artisan('mail:prune', ['--dry-run' => true])->assertSuccessful();

        $this->assertNotNull(Message::find($old->id));
        Storage::disk('local')->assertExists($old->raw_path);
    }

    public function test_an_orphan_workspace_is_skipped_without_halting_the_loop_for_other_workspaces(): void
    {
        Storage::fake('local');
        $orphanWorkspace = $this->makeWorkspace();
        $orphanInbox = $this->makeInbox($orphanWorkspace);
        $orphanMessage = $this->makeMessage($orphanInbox, now()->subDays(10));

        $healthyWorkspace = $this->makeWorkspace();
        $healthyInbox = $this->makeInbox($healthyWorkspace);
        $healthyOldMessage = $this->makeMessage($healthyInbox, now()->subDays(10));

        $this->bindPerWorkspaceEntitlements(
            retentionByWorkspaceId: [$healthyWorkspace->id() => 7],
            orphanIds: [$orphanWorkspace->id()],
        );

        $this->artisan('mail:prune')
            ->expectsOutputToContain('1 orphan workspace(s) skipped')
            ->assertSuccessful();

        // The orphan workspace's message is untouched (its whole workspace
        // was skipped), while the healthy workspace's own retention pass
        // still ran and pruned its own overdue message.
        $this->assertNotNull(Message::find($orphanMessage->id));
        $this->assertNull(Message::find($healthyOldMessage->id));
    }
}
