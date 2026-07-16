<?php

namespace Sendtrap\Core\Tests\Feature;

use Illuminate\Support\Facades\Storage;
use Sendtrap\Core\Jobs\ProcessIncomingMessage;
use Sendtrap\Core\Models\Inbox;
use Sendtrap\Core\Models\Message;
use Sendtrap\Core\Models\Workspace;
use Sendtrap\Core\Tests\PackageTestCase;

/**
 * Moved to the package in Plan 06 Phase 3b slice 7 (§5.1 bucket (b)) — same
 * Workspace-rooted fixture rework as InboxApiTest.
 */
class MailtrapCompatApiTest extends PackageTestCase
{
    protected function makeInbox(): Inbox
    {
        $workspace = Workspace::factory()->create();
        $project = $workspace->projects()->create(['name' => 'P']);

        return $project->inboxes()->create(['name' => 'Inbox']);
    }

    protected function sampleEml(): string
    {
        return implode("\r\n", [
            'From: Alice Sender <alice@example.com>',
            'To: Bob <bob@example.com>',
            'Subject: Hello multipart',
            'Message-ID: <abc123@example.com>',
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

    public function test_it_rejects_requests_without_a_token(): void
    {
        $this->getJson('/api/sandboxes/anything/messages')->assertUnauthorized();
    }

    public function test_the_sandbox_path_segment_is_accepted_but_ignored(): void
    {
        $inbox = $this->makeInbox();
        Message::factory()->count(2)->for($inbox)->create();

        $this->withToken($inbox->api_token)
            ->getJson('/api/sandboxes/whatever-mailtrap-had/messages')
            ->assertOk()
            ->assertJsonCount(2);
    }

    public function test_it_lists_messages_as_a_bare_array(): void
    {
        $inbox = $this->makeInbox();
        Message::factory()->count(3)->for($inbox)->create();

        $response = $this->withToken($inbox->api_token)
            ->getJson('/api/sandboxes/x/messages')
            ->assertOk();

        $this->assertIsArray($response->json());
        $this->assertCount(3, $response->json());
        $this->assertArrayHasKey('from_email', $response->json()[0]);
        $this->assertArrayNotHasKey('data', $response->json());
    }

    public function test_it_cannot_read_a_message_from_another_inbox(): void
    {
        $a = $this->makeInbox();
        $b = $this->makeInbox();
        $message = Message::factory()->for($b)->create();

        $this->withToken($a->api_token)
            ->getJson("/api/sandboxes/x/messages/{$message->id}")
            ->assertNotFound();
    }

    public function test_it_updates_is_read_via_nested_message_body(): void
    {
        $inbox = $this->makeInbox();
        $message = Message::factory()->for($inbox)->create(['is_read' => false]);

        $this->withToken($inbox->api_token)
            ->patchJson("/api/sandboxes/x/messages/{$message->id}", [
                'message' => ['is_read' => true],
            ])
            ->assertOk()
            ->assertJsonPath('is_read', true);

        $this->assertTrue($message->fresh()->is_read);
    }

    public function test_it_deletes_a_single_message(): void
    {
        $inbox = $this->makeInbox();
        $message = Message::factory()->for($inbox)->create();

        $this->withToken($inbox->api_token)
            ->deleteJson("/api/sandboxes/x/messages/{$message->id}")
            ->assertOk()
            ->assertJsonPath('id', $message->id);

        $this->assertSame(0, $inbox->messages()->count());
    }

    public function test_clean_deletes_every_message_and_returns_a_sandbox_object(): void
    {
        $inbox = $this->makeInbox();
        Message::factory()->count(3)->for($inbox)->create();
        $other = $this->makeInbox();
        Message::factory()->for($other)->create();

        $this->withToken($inbox->api_token)
            ->patchJson('/api/sandboxes/x/clean')
            ->assertOk()
            ->assertJsonPath('emails_count', 0);

        $this->assertSame(0, $inbox->messages()->count());
        $this->assertSame(1, $other->messages()->count());
    }

    public function test_all_read_marks_every_message_read(): void
    {
        $inbox = $this->makeInbox();
        Message::factory()->count(2)->for($inbox)->create(['is_read' => false]);

        $this->withToken($inbox->api_token)
            ->patchJson('/api/sandboxes/x/all_read')
            ->assertOk()
            ->assertJsonPath('emails_unread_count', 0);

        $this->assertSame(0, $inbox->messages()->where('is_read', false)->count());
    }

    public function test_it_serves_body_txt_body_html_and_mail_headers(): void
    {
        Storage::fake('local');
        $inbox = $this->makeInbox();
        ProcessIncomingMessage::dispatchSync($inbox->id, $this->sampleEml());
        $message = $inbox->messages()->first();

        $this->withToken($inbox->api_token)
            ->get("/api/sandboxes/x/messages/{$message->id}/body.html")
            ->assertOk()
            ->assertSee('Hi', false);

        $this->withToken($inbox->api_token)
            ->get("/api/sandboxes/x/messages/{$message->id}/body.raw")
            ->assertOk()
            ->assertSee('Hello multipart');

        $headers = $this->withToken($inbox->api_token)
            ->getJson("/api/sandboxes/x/messages/{$message->id}/mail_headers")
            ->assertOk()
            ->json('headers');

        $this->assertSame('Hello multipart', $headers['Subject']);
    }

    public function test_it_lists_and_downloads_attachments(): void
    {
        Storage::fake('local');
        $inbox = $this->makeInbox();
        ProcessIncomingMessage::dispatchSync($inbox->id, $this->sampleEml());
        $message = $inbox->messages()->first();
        $attachment = $message->attachments()->first();

        $list = $this->withToken($inbox->api_token)
            ->getJson("/api/sandboxes/x/messages/{$message->id}/attachments")
            ->assertOk()
            ->json();

        $this->assertCount(1, $list);
        $this->assertSame('note.txt', $list[0]['filename']);
        $this->assertArrayHasKey('download_path', $list[0]);

        $this->withToken($inbox->api_token)
            ->get("/api/sandboxes/x/messages/{$message->id}/attachments/{$attachment->id}/download")
            ->assertOk()
            ->assertDownload('note.txt');
    }
}
