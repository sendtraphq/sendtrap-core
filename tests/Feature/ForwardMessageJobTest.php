<?php

namespace Sendtrap\Core\Tests\Feature;

use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Sendtrap\Core\Contracts\UsageMeter;
use Sendtrap\Core\Jobs\ForwardMessage;
use Sendtrap\Core\Jobs\ProcessIncomingMessage;
use Sendtrap\Core\Models\Inbox;
use Sendtrap\Core\Models\Message;
use Sendtrap\Core\Models\Workspace;
use Sendtrap\Core\Tests\Fakes\FakeUsageMeter;
use Sendtrap\Core\Tests\PackageTestCase;

/**
 * Characterization tests for Sendtrap\Core\Jobs\ForwardMessage.
 *
 * Plan 06 Phase 3b slice 9 (§5.1 bucket (c)): moved from the host's former
 * Tests\Feature\ForwardMessageJobTest — `config(['billing.plans.free.
 * limits.forwards_per_month' => …])` + `(new SendingLimiter)->hitForward()`
 * calls are replaced by binding a FakeUsageMeter with the wanted
 * canForward() decision directly (the package has no billing config tree;
 * UsageMeter::canForward() is the contract ProcessIncomingMessage actually
 * consults). One method — the "0 vs null" Cloud/SendingLimiter
 * characterization — is Cloud-implementation-specific and stays host-side
 * (still named ForwardMessageJobTest there); not a diminished port, a
 * genuine split.
 */
class ForwardMessageJobTest extends PackageTestCase
{
    protected function makeInbox(array $attributes = []): Inbox
    {
        $workspace = Workspace::factory()->create();
        $project = $workspace->projects()->create(['name' => 'P']);

        return $project->inboxes()->create(array_merge(['name' => 'Inbox'], $attributes));
    }

    protected function storedMessage(Inbox $inbox, string $raw): Message
    {
        $path = 'messages/forward-test.eml';
        Storage::disk('local')->put($path, $raw);

        return $inbox->messages()->create([
            'from_address' => 'alice@example.com',
            'to' => [['address' => 'bob@example.com', 'name' => null]],
            'cc' => [],
            'subject' => 'Hello',
            'size' => strlen($raw),
            'has_html' => false,
            'has_text' => true,
            'has_attachments' => false,
            'raw_path' => $path,
            'received_at' => now(),
        ]);
    }

    public function test_it_relays_the_original_raw_mime_verbatim_to_the_given_recipient(): void
    {
        Storage::fake('local');
        $inbox = $this->makeInbox();
        $raw = implode("\r\n", [
            'From: Alice Sender <alice@example.com>',
            'To: Bob <bob@example.com>',
            'Subject: Hello',
            'MIME-Version: 1.0',
            'Content-Type: text/plain; charset="utf-8"',
            '',
            'Body content.',
            '',
        ]);
        $message = $this->storedMessage($inbox, $raw);

        ForwardMessage::dispatchSync($message->id, 'destination@example.com');

        $sent = Mail::mailer()->getSymfonyTransport()->messages();
        $this->assertCount(1, $sent);

        $sentMessage = $sent->first();
        $envelope = $sentMessage->getEnvelope();

        $this->assertSame(['destination@example.com'], array_map(
            fn ($address) => $address->getAddress(),
            $envelope->getRecipients()
        ));
        $this->assertSame(config('mail.from.address', 'sandbox@localhost'), $envelope->getSender()->getAddress());

        // The raw RFC822 source is relayed byte-for-byte, not re-encoded.
        $this->assertSame($raw, $sentMessage->getOriginalMessage()->toString());
    }

    public function test_it_does_nothing_when_the_message_no_longer_exists(): void
    {
        Storage::fake('local');

        ForwardMessage::dispatchSync(999999, 'destination@example.com');

        $this->assertCount(0, Mail::mailer()->getSymfonyTransport()->messages());
    }

    public function test_it_does_nothing_when_the_raw_source_is_missing_from_storage(): void
    {
        Storage::fake('local');
        $inbox = $this->makeInbox();
        // raw_path points at a file that was never written to the fake disk,
        // so Message::raw() resolves to ''.
        $message = $inbox->messages()->create([
            'from_address' => 'alice@example.com',
            'to' => [],
            'cc' => [],
            'subject' => 'Hello',
            'size' => 0,
            'has_html' => false,
            'has_text' => false,
            'has_attachments' => false,
            'raw_path' => 'messages/does-not-exist.eml',
            'received_at' => now(),
        ]);

        ForwardMessage::dispatchSync($message->id, 'destination@example.com');

        $this->assertCount(0, Mail::mailer()->getSymfonyTransport()->messages());
    }

    public function test_ingestion_dispatches_forward_message_when_auto_forward_is_set_and_quota_available(): void
    {
        Storage::fake('local');
        // Fake only ForwardMessage, not the whole queue: ProcessIncomingMessage
        // is itself ShouldQueue, and dispatchSync() on a ShouldQueue job routes
        // through the (sync) queue connection (Bus\Dispatcher::dispatchSync()
        // -> dispatchToQueue()). A blanket Queue::fake() would intercept that
        // dispatch too, so ProcessIncomingMessage::handle() would never
        // actually run and no message would be created.
        Queue::fake([ForwardMessage::class]);
        // PackageTestCase's default UsageMeter binding (UnlimitedUsageMeter)
        // already answers canForward() === true — no fake needed to isolate
        // the "auto_forward_to is set" dispatch path.
        $inbox = $this->makeInbox(['auto_forward_to' => 'forward-target@example.com']);

        ProcessIncomingMessage::dispatchSync($inbox->id, $this->sampleEml());

        $message = $inbox->messages()->first();

        Queue::assertPushed(ForwardMessage::class, function (ForwardMessage $job) use ($message) {
            return $job->messageId === $message->id
                && $job->to === 'forward-target@example.com';
        });
    }

    public function test_ingestion_does_not_dispatch_forward_message_when_the_inbox_has_no_auto_forward(): void
    {
        Storage::fake('local');
        Queue::fake([ForwardMessage::class]);
        $inbox = $this->makeInbox();

        ProcessIncomingMessage::dispatchSync($inbox->id, $this->sampleEml());

        Queue::assertNotPushed(ForwardMessage::class);
    }

    public function test_ingestion_does_not_dispatch_forward_message_when_over_the_forward_quota(): void
    {
        Storage::fake('local');
        Queue::fake([ForwardMessage::class]);
        // The host's equivalent characterization test consumes a real
        // durable counter to force this decision; the package's contract
        // test only cares that ProcessIncomingMessage respects a "no" from
        // UsageMeter::canForward() — bind a fake that says no directly.
        $this->app->instance(UsageMeter::class, new FakeUsageMeter(canForward: false));
        $inbox = $this->makeInbox(['auto_forward_to' => 'forward-target@example.com']);

        ProcessIncomingMessage::dispatchSync($inbox->id, $this->sampleEml());

        Queue::assertNotPushed(ForwardMessage::class);
    }

    protected function sampleEml(): string
    {
        return implode("\r\n", [
            'From: Alice Sender <alice@example.com>',
            'To: Bob <bob@example.com>',
            'Subject: Hello',
            'MIME-Version: 1.0',
            'Content-Type: text/plain; charset="utf-8"',
            '',
            'Body content.',
            '',
        ]);
    }
}
