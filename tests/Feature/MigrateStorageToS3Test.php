<?php

namespace Sendtrap\Core\Tests\Feature;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Storage;
use Sendtrap\Core\Models\Message;
use Sendtrap\Core\Models\Workspace;
use Sendtrap\Core\Tests\PackageTestCase;

/**
 * Plan 06 Phase 3b slice 9 (§5.1 bucket (b)): moved from the host's former
 * Tests\Feature\MigrateStorageToS3Test — fixture rework only.
 */
class MigrateStorageToS3Test extends PackageTestCase
{
    public function test_it_returns_failure_when_a_database_file_is_missing_locally(): void
    {
        Storage::fake('local');
        Storage::fake('s3');

        $workspace = Workspace::factory()->create();
        $project = $workspace->projects()->create(['name' => 'P']);
        $inbox = $project->inboxes()->create(['name' => 'Inbox']);
        Message::factory()->for($inbox)->create(['raw_path' => 'messages/missing.eml']);

        $this->artisan('storage:migrate-to-s3')
            ->expectsOutputToContain('1 missing locally')
            ->assertExitCode(Command::FAILURE);
    }
}
