<?php

namespace Sendtrap\Core\Tests\Feature;

use Illuminate\Contracts\Filesystem\Filesystem;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Mockery;
use RuntimeException;
use Sendtrap\Core\Contracts\StorageQuota;
use Sendtrap\Core\Jobs\ProcessIncomingMessage;
use Sendtrap\Core\Models\Inbox;
use Sendtrap\Core\Models\Workspace;
use Sendtrap\Core\Storage\StorageAdmission;
use Sendtrap\Core\Storage\StorageReservation;
use Sendtrap\Core\Tests\Fakes\FakeStorageQuota;
use Sendtrap\Core\Tests\PackageTestCase;

/**
 * Plan 01a's explicit ingestion lifecycle, asserted against the
 * StorageQuota contract with a recording fake: reserve carries the maximum
 * prospective bytes, commit carries the bytes actually persisted (raw +
 * successfully stored attachments) minus nothing / plus retention's exact
 * removals, failures release, a barrier answer requeues instead of
 * dropping, and a commit-side quota failure never releases (or double
 * charges) an already-stored message.
 */
class MessageIngestionLifecycleTest extends PackageTestCase
{
    protected FakeStorageQuota $quota;

    protected function setUp(): void
    {
        parent::setUp();

        $this->quota = new FakeStorageQuota;
        $this->app->instance(StorageQuota::class, $this->quota);
    }

    protected function makeInbox(array $attributes = []): Inbox
    {
        $workspace = Workspace::factory()->create();
        $project = $workspace->projects()->create(['name' => 'P']);

        return $project->inboxes()->create(array_merge(['name' => 'Inbox'], $attributes));
    }

    protected function plainEml(string $subject = 'Hello'): string
    {
        return implode("\r\n", [
            'From: Alice <alice@example.com>',
            'To: Bob <bob@example.com>',
            'Subject: '.$subject,
            'MIME-Version: 1.0',
            'Content-Type: text/html; charset="utf-8"',
            '',
            '<html><body><h1>Hi</h1></body></html>',
            '',
        ]);
    }

    protected function multipartEml(): string
    {
        return implode("\r\n", [
            'From: Alice <alice@example.com>',
            'To: Bob <bob@example.com>',
            'Subject: Hello multipart',
            'MIME-Version: 1.0',
            'Content-Type: multipart/mixed; boundary="BOUND"',
            '',
            '--BOUND',
            'Content-Type: text/html; charset="utf-8"',
            '',
            '<html><body><h1>Hi</h1></body></html>',
            '--BOUND',
            'Content-Type: text/plain; name="note.txt"',
            'Content-Disposition: attachment; filename="note.txt"',
            '',
            'This is an attachment.',
            '--BOUND--',
            '',
        ]);
    }

    public function test_reserve_carries_raw_plus_decoded_attachment_bytes_and_commit_the_stored_bytes(): void
    {
        Storage::fake('local');
        $inbox = $this->makeInbox();
        $raw = $this->multipartEml();

        ProcessIncomingMessage::dispatchSync($inbox->id, $raw);

        $message = $inbox->messages()->first();
        $attachmentBytes = (int) $message->attachments()->sum('size');

        $this->assertSame(1, count($this->quota->reserves));
        $this->assertSame(strlen($raw) + $attachmentBytes, $this->quota->reserves[0]['bytes']);

        $this->assertSame([[
            'token' => 'op-1',
            'stored' => strlen($raw) + $attachmentBytes,
            'removed' => 0,
        ]], $this->quota->commits);
        $this->assertSame([], $this->quota->releases);
    }

    public function test_a_blocked_reservation_drops_the_message_without_commit_or_release(): void
    {
        $this->quota->admission = StorageAdmission::Blocked;
        Storage::fake('local');
        $inbox = $this->makeInbox();

        ProcessIncomingMessage::dispatchSync($inbox->id, $this->plainEml());

        $this->assertSame(0, $inbox->messages()->count());
        Storage::disk('local')->assertDirectoryEmpty('messages');
        $this->assertSame([], $this->quota->commits);
        $this->assertSame([], $this->quota->releases);
    }

    public function test_a_retry_answer_requeues_the_job_instead_of_dropping(): void
    {
        $this->quota->admission = StorageAdmission::Retry;
        Queue::fake();
        Storage::fake('local');
        $inbox = $this->makeInbox();

        (new ProcessIncomingMessage($inbox->id, $this->plainEml(), 'alice@example.com', ['bob@example.com']))->handle();

        $this->assertSame(0, $inbox->messages()->count());
        Queue::assertPushed(ProcessIncomingMessage::class, function (ProcessIncomingMessage $job) use ($inbox) {
            return $job->inboxId === $inbox->id
                && $job->raw === $this->plainEml()
                && $job->envelopeFrom === 'alice@example.com'
                && $job->delay !== null;
        });
    }

    public function test_a_raw_store_failure_releases_the_reservation(): void
    {
        $disk = Mockery::mock(Filesystem::class);
        $disk->shouldReceive('put')->andReturn(false);
        Storage::shouldReceive('disk')->andReturn($disk);

        $inbox = $this->makeInbox();

        ProcessIncomingMessage::dispatchSync($inbox->id, $this->plainEml());

        $this->assertSame(0, $inbox->messages()->count());
        $this->assertSame([], $this->quota->commits);
        $this->assertSame(['op-1'], $this->quota->releases);
    }

    public function test_a_persistence_exception_releases_the_reservation_and_fails_the_job(): void
    {
        $disk = Mockery::mock(Filesystem::class);
        $disk->shouldReceive('put')->with(Mockery::pattern('/\.eml$/'), Mockery::any())->andReturn(true);
        $disk->shouldReceive('put')->andThrow(new RuntimeException('disk exploded'));
        Storage::shouldReceive('disk')->andReturn($disk);

        $inbox = $this->makeInbox();

        try {
            ProcessIncomingMessage::dispatchSync($inbox->id, $this->multipartEml());
            $this->fail('The persistence exception should have propagated (retryable job failure).');
        } catch (RuntimeException) {
            // expected
        }

        $this->assertSame([], $this->quota->commits);
        $this->assertSame(['op-1'], $this->quota->releases);
    }

    public function test_a_failed_attachment_write_is_not_charged(): void
    {
        $disk = Mockery::mock(Filesystem::class);
        $disk->shouldReceive('put')->with(Mockery::pattern('/\.eml$/'), Mockery::any())->andReturn(true);
        $disk->shouldReceive('put')->andReturn(false); // every attachment write fails
        Storage::shouldReceive('disk')->andReturn($disk);

        $inbox = $this->makeInbox();
        $raw = $this->multipartEml();

        ProcessIncomingMessage::dispatchSync($inbox->id, $raw);

        $message = $inbox->messages()->first();
        $this->assertNotNull($message);
        $this->assertSame(0, $message->attachments()->count());

        // Reserved raw+attachment, committed raw only — the failed
        // attachment's prospective bytes are returned, not left charged.
        $this->assertGreaterThan(strlen($raw), $this->quota->reserves[0]['bytes']);
        $this->assertSame(strlen($raw), $this->quota->commits[0]['stored']);
        $this->assertSame([], $this->quota->releases);
    }

    public function test_retention_removals_fold_into_the_ingestion_commit(): void
    {
        Storage::fake('local');
        $inbox = $this->makeInbox(['max_messages' => 1]);

        $first = $this->plainEml('First');
        ProcessIncomingMessage::dispatchSync($inbox->id, $first);

        ProcessIncomingMessage::dispatchSync($inbox->id, $this->plainEml('Second'));

        $this->assertSame(1, $inbox->messages()->count());
        $this->assertSame('Second', $inbox->messages()->first()->subject);

        // The second commit reports the first message's exact bytes as
        // removed — retention accounting costs no extra quota round trips.
        $this->assertSame(strlen($first), $this->quota->commits[1]['removed']);
    }

    public function test_a_commit_failure_neither_fails_the_job_nor_releases_the_stored_message(): void
    {
        Storage::fake('local');
        $inbox = $this->makeInbox();

        $this->app->instance(StorageQuota::class, $quota = new class extends FakeStorageQuota
        {
            public function commit(StorageReservation $reservation, int $storedBytes, int $removedBytes = 0): void
            {
                throw new RuntimeException('redis went away');
            }
        });

        ProcessIncomingMessage::dispatchSync($inbox->id, $this->plainEml());

        // Message stored; the broken commit was swallowed (logged) and the
        // reservation was NOT released — its token expires into
        // reconciliation instead of un-charging a stored message.
        $this->assertSame(1, $inbox->messages()->count());
        $this->assertSame([], $quota->releases);
    }
}
