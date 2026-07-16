<?php

namespace Sendtrap\Core\Tests\Feature;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Storage;
use Sendtrap\Core\Jobs\ProcessIncomingMessage;
use Sendtrap\Core\Models\Inbox;
use Sendtrap\Core\Models\Message;
use Sendtrap\Core\Models\Workspace;
use Sendtrap\Core\Tests\PackageTestCase;

/**
 * Characterization tests for storage on the s3 disk as the ACTIVE default
 * (`filesystems.default = s3`) — ingestion write path, message/attachment
 * download read path, and the `storage:migrate-to-s3` command's
 * success/dry-run paths. See Sendtrap\Core\Support\MessageStorage (single point of
 * truth: MessageStorage::disk() always resolves config('filesystems.default'))
 * and Sendtrap\Core\Console\Commands\MigrateStorageToS3.
 *
 * Plan 06 Phase 3b slice 9 (§5.1 bucket (b)): moved from the host's former
 * Tests\Feature\S3StoragePathTest — fixture rework only.
 */
class S3StoragePathTest extends PackageTestCase
{
    protected function makeInbox(): Inbox
    {
        $workspace = Workspace::factory()->create();
        $project = $workspace->projects()->create(['name' => 'P']);

        return $project->inboxes()->create(['name' => 'Inbox']);
    }

    protected function sampleEmlWithAttachment(): string
    {
        return implode("\r\n", [
            'From: Alice Sender <alice@example.com>',
            'To: Bob <bob@example.com>',
            'Subject: Hello',
            'MIME-Version: 1.0',
            'Content-Type: multipart/mixed; boundary="BOUND"',
            '',
            '--BOUND',
            'Content-Type: text/plain; charset="utf-8"',
            '',
            'Body content.',
            '--BOUND',
            'Content-Type: text/plain; name="note.txt"',
            'Content-Disposition: attachment; filename="note.txt"',
            '',
            'This is an attachment.',
            '--BOUND--',
            '',
        ]);
    }

    // --- Ingestion + download with s3 as the active default disk ----------

    public function test_ingestion_stores_the_raw_message_and_attachment_on_the_s3_disk_when_it_is_the_default(): void
    {
        config(['filesystems.default' => 's3']);
        Storage::fake('s3');
        Storage::fake('local'); // sentinel — nothing should land here

        $inbox = $this->makeInbox();

        ProcessIncomingMessage::dispatchSync($inbox->id, $this->sampleEmlWithAttachment());

        $message = $inbox->messages()->first();
        $attachment = $message->attachments()->first();

        $this->assertNotNull($message);
        $this->assertNotNull($attachment);

        Storage::disk('s3')->assertExists($message->raw_path);
        Storage::disk('s3')->assertExists($attachment->path);
        $this->assertSame('This is an attachment.', Storage::disk('s3')->get($attachment->path));

        // MessageStorage::disk() resolves the *default* disk only — nothing
        // is duplicated onto 'local' as a fallback.
        Storage::disk('local')->assertDirectoryEmpty('messages');
    }

    public function test_raw_message_download_streams_from_the_s3_disk_when_it_is_the_default(): void
    {
        config(['filesystems.default' => 's3']);
        Storage::fake('s3');

        $inbox = $this->makeInbox();
        ProcessIncomingMessage::dispatchSync($inbox->id, $this->sampleEmlWithAttachment());
        $message = $inbox->messages()->first();

        $this->withToken($inbox->api_token)
            ->get("/api/v1/messages/{$message->id}/raw")
            ->assertOk()
            ->assertHeader('Content-Type', 'text/plain; charset=utf-8')
            ->assertSee('Body content.', false);
    }

    public function test_attachment_download_streams_from_the_s3_disk_when_it_is_the_default(): void
    {
        config(['filesystems.default' => 's3']);
        Storage::fake('s3');

        $inbox = $this->makeInbox();
        ProcessIncomingMessage::dispatchSync($inbox->id, $this->sampleEmlWithAttachment());
        $message = $inbox->messages()->first();
        $attachment = $message->attachments()->first();

        $this->withToken($inbox->api_token)
            ->get("/api/v1/messages/{$message->id}/attachments/{$attachment->id}")
            ->assertOk()
            ->assertDownload('note.txt');
    }

    // --- storage:migrate-to-s3 success + dry-run paths ---------------------

    protected function makeMessageWithLocalFile(Inbox $inbox, string $content = 'raw bytes'): Message
    {
        $message = Message::factory()->for($inbox)->create([
            'raw_path' => 'messages/existing.eml',
        ]);
        Storage::disk('local')->put($message->raw_path, $content);

        return $message;
    }

    public function test_migrate_command_copies_local_files_to_s3_and_leaves_database_paths_unchanged(): void
    {
        Storage::fake('local');
        Storage::fake('s3');

        $inbox = $this->makeInbox();
        $message = $this->makeMessageWithLocalFile($inbox, 'raw bytes');
        $attachment = $message->attachments()->create([
            'filename' => 'note.txt',
            'content_type' => 'text/plain',
            'size' => 11,
            'path' => 'messages/attachments/'.$message->id.'/note.txt',
            'is_inline' => false,
        ]);
        Storage::disk('local')->put($attachment->path, 'hello world');

        $this->artisan('storage:migrate-to-s3')
            ->expectsOutputToContain('messages: 1 migrated, 0 already on s3, 0 missing locally, 0 failed.')
            ->expectsOutputToContain('attachments: 1 migrated, 0 already on s3, 0 missing locally, 0 failed.')
            ->assertExitCode(Command::SUCCESS);

        Storage::disk('s3')->assertExists($message->raw_path, 'raw bytes');
        Storage::disk('s3')->assertExists($attachment->path, 'hello world');

        // CHARACTERIZATION: the command copies bytes only — it never touches
        // the raw_path/path DB columns. It relies on the same relative path
        // being valid on both disks (both `local` and `s3` disks are
        // configured with no distinguishing root prefix in config/filesystems.php).
        $message->refresh();
        $attachment->refresh();
        $this->assertSame('messages/existing.eml', $message->raw_path);
        $this->assertSame('messages/attachments/'.$message->id.'/note.txt', $attachment->path);

        // Original local copies are left in place — this is a copy, not a move.
        Storage::disk('local')->assertExists($message->raw_path);
        Storage::disk('local')->assertExists($attachment->path);
    }

    public function test_migrate_command_dry_run_reports_counts_without_copying_anything(): void
    {
        Storage::fake('local');
        Storage::fake('s3');

        $inbox = $this->makeInbox();
        $message = $this->makeMessageWithLocalFile($inbox, 'raw bytes');

        $this->artisan('storage:migrate-to-s3', ['--dry-run' => true])
            // CHARACTERIZATION: the dry-run branch still increments and
            // reports the "migrated" counter (it only skips the actual
            // put()) — the "[dry-run] " prefix is the sole signal that
            // nothing was really copied.
            ->expectsOutputToContain('[dry-run] messages: 1 migrated, 0 already on s3, 0 missing locally, 0 failed.')
            ->assertExitCode(Command::SUCCESS);

        Storage::disk('s3')->assertMissing($message->raw_path);
        Storage::disk('local')->assertExists($message->raw_path);
    }

    public function test_migrate_command_skips_files_already_present_on_s3(): void
    {
        Storage::fake('local');
        Storage::fake('s3');

        $inbox = $this->makeInbox();
        $message = $this->makeMessageWithLocalFile($inbox, 'local version');
        // Pre-seed s3 with different bytes to prove the command does not
        // overwrite/re-copy an already-present file.
        Storage::disk('s3')->put($message->raw_path, 's3 version already there');

        $this->artisan('storage:migrate-to-s3')
            ->expectsOutputToContain('messages: 0 migrated, 1 already on s3, 0 missing locally, 0 failed.')
            ->assertExitCode(Command::SUCCESS);

        $this->assertSame('s3 version already there', Storage::disk('s3')->get($message->raw_path));
    }
}
