<?php

namespace Sendtrap\Core\Tests\Feature;

use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Sendtrap\Core\Contracts\UsageMeter;
use Sendtrap\Core\Jobs\ForwardMessage;
use Sendtrap\Core\Jobs\ProcessIncomingMessage;
use Sendtrap\Core\Models\Inbox;
use Sendtrap\Core\Models\Workspace;
use Sendtrap\Core\Tests\Fakes\FakeUsageMeter;
use Sendtrap\Core\Tests\PackageTestCase;

/**
 * The package-side
 * equivalent of a host-level message-ingestion suite, covering the
 * cases such a suite exercises which no existing package test already
 * covers (authored fresh, not a diminished port).
 *
 * Multipart parsing, attachment extraction/checksums, and the JSON detail
 * shape are already covered package-side by
 * Sendtrap\Core\Tests\Feature\MailtrapCompatApiTest and
 * Sendtrap\Core\Tests\Feature\MessageDetailJsonShapeTest (both dispatch
 * ProcessIncomingMessage::dispatchSync() against a multipart/mixed sample
 * with an attachment part and assert on the stored Message/Attachment rows)
 * — this file does not duplicate that ground; it adds what was missing:
 * the X-Sendtrap-Test-Id
 * header extraction, envelope_to's bcc capture, the storage-quota rejection
 * path (Sendtrap\Core\Jobs\ProcessIncomingMessage::handle(),
 * src/Jobs/ProcessIncomingMessage.php:64, gated by
 * app(UsageMeter::class)->wouldExceedStorage()), and the auto-forward gate
 * (same file, :148, gated by app(UsageMeter::class)->canForward()) — both
 * driven via a bound FakeUsageMeter rather than a config('billing.plans...')
 * mutation, since the package has no billing config tree.
 *
 * Fixture: Workspace::factory()->create() -> project -> inbox, no Team, no
 * billing config — mirrors WorkspaceAllowedIpsTest's package-side pattern.
 *
 * Mirrors: MessageIngestionTest::test_it_extracts_the_test_id_header,
 * test_test_id_is_null_when_the_header_is_absent,
 * test_envelope_to_captures_a_bcc_address_absent_from_headers,
 * test_storage_quota_reserves_space_for_extracted_attachments (the
 * wouldExceedStorage half — the host's own case is a raw-vs-decoded-size
 * characterization the package's UsageMeter contract abstracts away behind
 * a plain bool, so this file asserts the *decision*, not the byte-counting
 * mechanics). The auto-forward-gate cases have no host sentinel of their
 * own (uncovered gap, not a moved test) — added directly per the H-2(b)
 * instruction to cover ProcessIncomingMessage's forward gate.
 */
class MessageIngestionJobTest extends PackageTestCase
{
    protected function makeInbox(array $attributes = []): Inbox
    {
        $workspace = Workspace::factory()->create();
        $project = $workspace->projects()->create(['name' => 'P']);

        return $project->inboxes()->create(array_merge(['name' => 'Inbox'], $attributes));
    }

    protected function sampleEml(): string
    {
        return implode("\r\n", [
            'From: Alice Sender <alice@example.com>',
            'To: Bob <bob@example.com>',
            'Subject: Hello',
            'Message-ID: <abc123@example.com>',
            'MIME-Version: 1.0',
            'Content-Type: text/html; charset="utf-8"',
            '',
            '<html><body><h1>Hi</h1></body></html>',
            '',
        ]);
    }

    protected function emlWithHeader(string $name, string $value): string
    {
        return implode("\r\n", [
            'From: Alice Sender <alice@example.com>',
            'To: Bob <bob@example.com>',
            $name.': '.$value,
            'Subject: Hello',
            'MIME-Version: 1.0',
            'Content-Type: text/html; charset="utf-8"',
            '',
            '<html><body><h1>Hi</h1></body></html>',
            '',
        ]);
    }

    public function test_it_extracts_the_test_id_header(): void
    {
        Storage::fake('local');
        $inbox = $this->makeInbox();

        ProcessIncomingMessage::dispatchSync(
            $inbox->id,
            $this->emlWithHeader('X-Sendtrap-Test-Id', 'run-42'),
        );

        $this->assertSame('run-42', $inbox->messages()->first()->test_id);
    }

    public function test_test_id_is_null_when_the_header_is_absent(): void
    {
        Storage::fake('local');
        $inbox = $this->makeInbox();

        ProcessIncomingMessage::dispatchSync($inbox->id, $this->sampleEml());

        $this->assertNull($inbox->messages()->first()->test_id);
    }

    public function test_envelope_to_captures_a_bcc_address_absent_from_headers(): void
    {
        Storage::fake('local');
        $inbox = $this->makeInbox();

        ProcessIncomingMessage::dispatchSync(
            $inbox->id,
            $this->sampleEml(),
            'alice@example.com',
            ['bob@example.com', 'bcc-secret@example.com'],
        );

        $message = $inbox->messages()->first();
        $toAddresses = collect($message->to)->pluck('address')->all();

        $this->assertNotContains('bcc-secret@example.com', $toAddresses);
        $this->assertContains('bcc-secret@example.com', $message->envelope_to);
    }

    public function test_storage_quota_rejection_drops_the_message(): void
    {
        $this->app->instance(UsageMeter::class, new FakeUsageMeter(wouldExceedStorage: true));
        Storage::fake('local');
        $inbox = $this->makeInbox();

        ProcessIncomingMessage::dispatchSync($inbox->id, $this->sampleEml());

        $this->assertSame(0, $inbox->messages()->count());
        Storage::disk('local')->assertDirectoryEmpty('messages');
    }

    public function test_a_send_within_the_storage_quota_is_stored(): void
    {
        $this->app->instance(UsageMeter::class, new FakeUsageMeter(wouldExceedStorage: false));
        Storage::fake('local');
        $inbox = $this->makeInbox();

        ProcessIncomingMessage::dispatchSync($inbox->id, $this->sampleEml());

        $this->assertSame(1, $inbox->messages()->count());
    }

    public function test_the_forward_gate_denies_and_no_forward_message_job_is_dispatched(): void
    {
        Queue::fake([ForwardMessage::class]);
        $this->app->instance(UsageMeter::class, new FakeUsageMeter(canForward: false));
        Storage::fake('local');
        $inbox = $this->makeInbox(['auto_forward_to' => 'forward-target@example.com']);

        ProcessIncomingMessage::dispatchSync($inbox->id, $this->sampleEml());

        $this->assertSame(1, $inbox->messages()->count());
        Queue::assertNotPushed(ForwardMessage::class);
    }

    public function test_the_forward_gate_allows_and_records_the_forward(): void
    {
        Queue::fake([ForwardMessage::class]);
        $meter = new FakeUsageMeter(canForward: true);
        $this->app->instance(UsageMeter::class, $meter);
        Storage::fake('local');
        $inbox = $this->makeInbox(['auto_forward_to' => 'forward-target@example.com']);

        ProcessIncomingMessage::dispatchSync($inbox->id, $this->sampleEml());

        Queue::assertPushed(ForwardMessage::class, function (ForwardMessage $job) use ($inbox) {
            return $job->to === 'forward-target@example.com'
                && $job->messageId === $inbox->messages()->first()->id;
        });
        $this->assertSame(1, $meter->forwardsRecorded);
    }

    public function test_a_null_auto_forward_to_never_dispatches_a_forward(): void
    {
        Queue::fake([ForwardMessage::class]);
        Storage::fake('local');
        $inbox = $this->makeInbox(); // auto_forward_to left null

        ProcessIncomingMessage::dispatchSync($inbox->id, $this->sampleEml());

        Queue::assertNotPushed(ForwardMessage::class);
    }
}
