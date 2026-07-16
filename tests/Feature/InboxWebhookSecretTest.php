<?php

namespace Sendtrap\Core\Tests\Feature;

use Illuminate\Http\Client\Request as ClientRequest;
use Illuminate\Support\Facades\Http;
use Sendtrap\Core\Jobs\DeliverWebhook;
use Sendtrap\Core\Models\Inbox;
use Sendtrap\Core\Models\Workspace;
use Sendtrap\Core\Tests\PackageTestCase;

/**
 * Webhook signing hardening: a webhook_secret must exist whenever a
 * webhook_url is set — including the normal host flow where the inbox is
 * created first and the webhook URL is added later via a settings update.
 * Without this, DeliverWebhook falls back to hash_hmac(..., '') — an empty
 * key — and the signature provides no authentication at all.
 */
class InboxWebhookSecretTest extends PackageTestCase
{
    protected function makeInbox(array $attributes = []): Inbox
    {
        $workspace = Workspace::factory()->create();
        $project = $workspace->projects()->create(['name' => 'P']);

        return $project->inboxes()->create(array_merge(['name' => 'Inbox'], $attributes));
    }

    public function test_adding_a_webhook_url_on_update_backfills_a_secret(): void
    {
        $inbox = $this->makeInbox();

        $this->assertEmpty($inbox->webhook_secret);

        $inbox->update(['webhook_url' => 'http://93.184.216.34/hook']);

        $inbox->refresh();
        $this->assertNotEmpty($inbox->webhook_secret);
        $this->assertSame(40, strlen($inbox->webhook_secret));
    }

    public function test_the_secret_is_stable_across_subsequent_updates(): void
    {
        $inbox = $this->makeInbox();

        $inbox->update(['webhook_url' => 'http://93.184.216.34/hook']);
        $secret = $inbox->fresh()->webhook_secret;
        $this->assertNotEmpty($secret);

        $inbox->update(['name' => 'Renamed', 'webhook_url' => 'http://93.184.216.34/hook2']);

        $this->assertSame($secret, $inbox->fresh()->webhook_secret);
    }

    public function test_a_secret_provided_at_creation_is_never_regenerated(): void
    {
        $inbox = $this->makeInbox([
            'webhook_url' => 'http://93.184.216.34/hook',
            'webhook_secret' => 'pre-set-secret',
        ]);

        $inbox->update(['name' => 'Renamed']);

        $this->assertSame('pre-set-secret', $inbox->fresh()->webhook_secret);
    }

    public function test_smtp_and_api_credentials_are_untouched_by_updates(): void
    {
        $inbox = $this->makeInbox();

        $username = $inbox->smtp_username;
        $password = $inbox->smtp_password;
        $token = $inbox->api_token;

        $this->assertNotEmpty($username);
        $this->assertNotEmpty($password);
        $this->assertNotEmpty($token);

        $inbox->update(['webhook_url' => 'http://93.184.216.34/hook']);
        $inbox->refresh();

        $this->assertSame($username, $inbox->smtp_username);
        $this->assertSame($password, $inbox->smtp_password);
        $this->assertSame($token, $inbox->api_token);
    }

    public function test_deliver_webhook_signs_with_the_backfilled_secret_not_an_empty_key(): void
    {
        Http::fake(['*' => Http::response('ok', 200)]);

        $inbox = $this->makeInbox();
        $inbox->update(['webhook_url' => 'http://93.184.216.34/hook']);
        $inbox->refresh();

        $secret = $inbox->webhook_secret;
        $this->assertNotEmpty($secret);

        $message = $inbox->messages()->create([
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
        ]);

        DeliverWebhook::dispatchSync($message->id);

        Http::assertSent(function (ClientRequest $request) use ($secret) {
            $signature = $request->header('X-Sendtrap-Signature')[0] ?? null;

            return $signature === hash_hmac('sha256', $request->body(), $secret)
                && $signature !== hash_hmac('sha256', $request->body(), '');
        });
    }
}
