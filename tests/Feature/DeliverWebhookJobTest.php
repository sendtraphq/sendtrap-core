<?php

namespace Sendtrap\Core\Tests\Feature;

use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Sendtrap\Core\Jobs\DeliverWebhook;
use Sendtrap\Core\Jobs\ProcessIncomingMessage;
use Sendtrap\Core\Models\Inbox;
use Sendtrap\Core\Models\Message;
use Sendtrap\Core\Models\Workspace;
use Sendtrap\Core\Tests\PackageTestCase;

/**
 * Characterization tests for Sendtrap\Core\Jobs\DeliverWebhook.
 *
 * Plan 06 Phase 3b slice 9 (§5.1 bucket (b)): moved from the host's former
 * Tests\Feature\DeliverWebhookJobTest — fixture rework only.
 */
class DeliverWebhookJobTest extends PackageTestCase
{
    protected function makeInbox(array $attributes = []): Inbox
    {
        $workspace = Workspace::factory()->create();
        $project = $workspace->projects()->create(['name' => 'P']);

        return $project->inboxes()->create(array_merge(['name' => 'Inbox'], $attributes));
    }

    protected function makeMessage(Inbox $inbox, array $attributes = []): Message
    {
        return $inbox->messages()->create(array_merge([
            'message_id' => 'msg-1@example.com',
            'from_address' => 'alice@example.com',
            'from_name' => 'Alice Sender',
            'to' => [['address' => 'bob@example.com', 'name' => null]],
            'cc' => [],
            'subject' => 'Hello',
            'size' => 123,
            'has_html' => false,
            'has_text' => true,
            'has_attachments' => false,
            'raw_path' => 'messages/does-not-matter.eml',
            'received_at' => now(),
        ], $attributes));
    }

    public function test_it_posts_the_expected_json_payload_shape_to_the_webhook_url(): void
    {
        Http::fake(['*' => Http::response('ok', 200)]);
        // Use a literal public IP as the host so the job's SSRF resolution
        // step (parse_url + filter_var) is exercised without a real DNS
        // lookup — see App\Jobs\DeliverWebhook::handle().
        $inbox = $this->makeInbox([
            'webhook_url' => 'http://93.184.216.34/hook',
            'webhook_secret' => 'super-secret',
        ]);
        $message = $this->makeMessage($inbox);

        DeliverWebhook::dispatchSync($message->id);

        Http::assertSent(function (Request $request) use ($inbox, $message) {
            $data = $request->data();

            return $request->url() === $inbox->webhook_url
                && $request->method() === 'POST'
                && $data['event'] === 'message.received'
                && $data['inbox_id'] === $inbox->id
                && $data['message']['id'] === $message->id
                && $data['message']['message_id'] === $message->message_id
                && $data['message']['from'] === ['address' => 'alice@example.com', 'name' => 'Alice Sender']
                && $data['message']['to'] === $message->to
                && $data['message']['cc'] === $message->cc
                && $data['message']['subject'] === 'Hello'
                && $data['message']['size'] === 123
                && $data['message']['has_attachments'] === false
                && $data['message']['received_at'] === $message->received_at->toIso8601String()
                && array_keys($data) === ['event', 'inbox_id', 'message']
                && array_keys($data['message']) === [
                    'id', 'message_id', 'from', 'to', 'cc', 'subject', 'size', 'has_attachments', 'received_at',
                ];
        });
    }

    public function test_it_signs_the_payload_with_hmac_sha256_of_the_webhook_secret(): void
    {
        Http::fake(['*' => Http::response('ok', 200)]);
        $inbox = $this->makeInbox([
            'webhook_url' => 'http://93.184.216.34/hook',
            'webhook_secret' => 'super-secret',
        ]);
        $message = $this->makeMessage($inbox);

        DeliverWebhook::dispatchSync($message->id);

        Http::assertSent(function (Request $request) use ($inbox) {
            $expected = hash_hmac('sha256', $request->body(), $inbox->webhook_secret);

            return $request->hasHeader('X-Sendtrap-Signature', $expected)
                && $request->hasHeader('Content-Type', 'application/json');
        });
    }

    public function test_job_retry_configuration(): void
    {
        $job = new DeliverWebhook(1);

        $this->assertSame(3, $job->tries);
        $this->assertSame([10, 60, 300], $job->backoff);
    }

    public function test_it_does_not_deliver_when_the_webhook_url_resolves_to_a_loopback_address(): void
    {
        Log::spy();
        Http::fake();
        $inbox = $this->makeInbox([
            'webhook_url' => 'http://127.0.0.1/hook',
            'webhook_secret' => 'super-secret',
        ]);
        $message = $this->makeMessage($inbox);

        DeliverWebhook::dispatchSync($message->id);

        Http::assertNothingSent();
        Log::shouldHaveReceived('warning')->once()->withArgs(
            fn ($message, array $context = []) => str_contains($message, 'webhook.blocked')
        );
    }

    public function test_it_does_not_deliver_when_the_webhook_url_resolves_to_a_private_rfc1918_address(): void
    {
        Http::fake();
        $inbox = $this->makeInbox([
            'webhook_url' => 'http://10.0.0.1/hook',
            'webhook_secret' => 'super-secret',
        ]);
        $message = $this->makeMessage($inbox);

        DeliverWebhook::dispatchSync($message->id);

        Http::assertNothingSent();
    }

    public function test_it_does_not_deliver_when_the_webhook_url_resolves_to_the_cloud_metadata_link_local_range(): void
    {
        Http::fake();
        $inbox = $this->makeInbox([
            'webhook_url' => 'http://169.254.169.254/latest/meta-data/',
            'webhook_secret' => 'super-secret',
        ]);
        $message = $this->makeMessage($inbox);

        DeliverWebhook::dispatchSync($message->id);

        Http::assertNothingSent();
    }

    public function test_it_does_nothing_when_the_message_no_longer_exists(): void
    {
        Http::fake();

        DeliverWebhook::dispatchSync(999999);

        Http::assertNothingSent();
    }

    public function test_it_does_nothing_when_the_inbox_has_no_webhook_url(): void
    {
        Http::fake();
        $inbox = $this->makeInbox();
        $message = $this->makeMessage($inbox);

        DeliverWebhook::dispatchSync($message->id);

        Http::assertNothingSent();
    }

    public function test_ingestion_dispatches_deliver_webhook_when_the_inbox_has_a_webhook_url(): void
    {
        Storage::fake('local');
        // Fake only DeliverWebhook, not the whole queue: ProcessIncomingMessage
        // is itself ShouldQueue and dispatchSync() on a ShouldQueue job routes
        // through the (sync) queue connection, so a blanket Queue::fake() would
        // also intercept ProcessIncomingMessage's own dispatch and it would
        // never actually run.
        Queue::fake([DeliverWebhook::class]);
        $inbox = $this->makeInbox([
            'webhook_url' => 'http://93.184.216.34/hook',
            'webhook_secret' => 'super-secret',
        ]);

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

        ProcessIncomingMessage::dispatchSync($inbox->id, $raw);

        $message = $inbox->messages()->first();

        Queue::assertPushed(DeliverWebhook::class, fn (DeliverWebhook $job) => $job->messageId === $message->id);
    }

    public function test_ingestion_does_not_dispatch_deliver_webhook_when_the_inbox_has_no_webhook_url(): void
    {
        Storage::fake('local');
        Queue::fake([DeliverWebhook::class]);
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

        ProcessIncomingMessage::dispatchSync($inbox->id, $raw);

        Queue::assertNotPushed(DeliverWebhook::class);
    }
}
