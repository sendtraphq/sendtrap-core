<?php

namespace Sendtrap\Core\Tests\Feature;

use Illuminate\Support\Facades\Schema;
use Sendtrap\Core\Models\Attachment;
use Sendtrap\Core\Models\Inbox;
use Sendtrap\Core\Models\InboxShare;
use Sendtrap\Core\Models\Message;
use Sendtrap\Core\Models\MessageShare;
use Sendtrap\Core\Models\Project;
use Sendtrap\Core\Models\Workspace;
use Sendtrap\Core\Tests\PackageTestCase;

/**
 * Plan 06 Phase 3b slice 2 (§6 slice-2 verification / §7): the package's own
 * migrations produce the complete core schema from an empty database under
 * Testbench — no host, no teams table, no Cloud columns.
 */
class MigrationsFromEmptyTest extends PackageTestCase
{
    public function test_core_tables_migrate_from_an_empty_database(): void
    {
        foreach ([
            'workspaces',
            'projects',
            'inboxes',
            'messages',
            'attachments',
            'message_shares',
            'inbox_shares',
            'message_html_checks',
        ] as $table) {
            $this->assertTrue(Schema::hasTable($table), "missing core table {$table}");
        }

        // The package never creates any Cloud/host table.
        $this->assertFalse(Schema::hasTable('teams'));
        $this->assertFalse(Schema::hasTable('users'));
    }

    public function test_projects_carries_no_team_concept_but_keeps_its_core_columns(): void
    {
        $this->assertTrue(Schema::hasColumns('projects', [
            'id', 'name', 'slug', 'workspace_id', 'allowed_ips', 'created_at', 'updated_at',
        ]));

        // §7.3: the split core create_projects_table has no team_id — that
        // column is Cloud's own guarded migration, which Testbench never
        // loads.
        $this->assertFalse(Schema::hasColumn('projects', 'team_id'));

        $columns = collect(Schema::getColumns('projects'))->keyBy('name');
        $this->assertTrue($columns['workspace_id']['nullable']);

        $workspaceFk = collect(Schema::getForeignKeys('projects'))
            ->first(fn ($fk) => in_array('workspace_id', $fk['columns'], true));
        $this->assertNotNull($workspaceFk, 'projects.workspace_id has no foreign key');
        $this->assertSame('workspaces', $workspaceFk['foreign_table']);
        $this->assertSame('set null', strtolower($workspaceFk['on_delete']));
    }

    public function test_later_core_migrations_landed_their_columns(): void
    {
        $this->assertTrue(Schema::hasColumns('messages', [
            'envelope_from', 'envelope_to', 'spam_score', 'test_id',
            'has_unresolved_merge_tags', 'unresolved_merge_tags',
        ]));
        $this->assertTrue(Schema::hasColumn('attachments', 'checksum'));
        $this->assertTrue(Schema::hasColumn('inboxes', 'allowed_ips'));
        $this->assertTrue(Schema::hasColumn('workspaces', 'allowed_ips'));
    }

    public function test_the_package_factories_create_a_full_domain_fixture(): void
    {
        $workspace = Workspace::factory()->create();
        $project = Project::factory()->for($workspace)->create();
        $inbox = Inbox::factory()->for($project)->create();
        $message = Message::factory()->for($inbox)->create();
        $attachment = Attachment::factory()->for($message)->create();
        $inboxShare = InboxShare::factory()->for($inbox)->create();
        $messageShare = MessageShare::factory()->for($message)->create();

        $this->assertSame($workspace->id, $project->workspace->id);
        $this->assertSame($project->id, $inbox->project->id);
        $this->assertSame($inbox->id, $message->inbox->id);
        $this->assertSame($message->id, $attachment->message->id);
        $this->assertSame($inbox->id, $inboxShare->inbox->id);
        $this->assertSame($message->id, $messageShare->message->id);

        // Core model hooks still fire on package-side creation.
        $this->assertNotEmpty($project->slug);
        $this->assertNotEmpty($inbox->api_token);
    }

    public function test_an_intentional_slug_collision_does_not_crash(): void
    {
        // §7.3 item 1 / §9 risk table: DB-level slug uniqueness (previously
        // per-team) is dropped from the core schema — asserting no crash
        // (not uniqueness) on an intentional collision.
        $workspace = Workspace::factory()->create();

        $a = $workspace->projects()->create(['name' => 'Dup', 'slug' => 'dup-1']);
        $b = $workspace->projects()->create(['name' => 'Dup', 'slug' => 'dup-1']);

        $this->assertNotSame($a->id, $b->id);
        $this->assertSame(2, $workspace->projects()->count());
    }

    public function test_effective_allowed_ips_degrades_to_empty_without_a_team_concept(): void
    {
        // M-N1/LOW-1 pin (§1.2 Inbox row): under the package's own Testbench
        // context no Project::team resolver is registered and strict
        // missing-attribute mode is off (PackageTestCase's normative pin),
        // so effectiveAllowedIps()'s verbatim last-resort chain
        // `$this->project?->team?->allowed_ips ?? []` degrades to [] for a
        // project with no workspace — never a throw.
        $project = Project::factory()->state(['workspace_id' => null])->create();
        $inbox = Inbox::factory()->for($project)->create();

        $this->assertSame([], $inbox->effectiveAllowedIps());
    }
}
