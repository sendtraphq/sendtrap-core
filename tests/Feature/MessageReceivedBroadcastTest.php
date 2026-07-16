<?php

namespace Sendtrap\Core\Tests\Feature;

use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Storage;
use Sendtrap\Core\Events\MessageReceived;
use Sendtrap\Core\Jobs\ProcessIncomingMessage;
use Sendtrap\Core\Models\Inbox;
use Sendtrap\Core\Models\Message;
use Sendtrap\Core\Models\Workspace;
use Sendtrap\Core\Tests\PackageTestCase;

/**
 * Characterization tests for the Sendtrap\Core\Events\MessageReceived
 * broadcast event's own shape (broadcastOn/broadcastAs/broadcastWith) and
 * dispatch-on-ingestion.
 *
 * Plan 06 Phase 3b slice 9 (§5.1 bucket (c)): moved from the host's former
 * Tests\Feature\MessageReceivedBroadcastTest — fixture rework for the
 * dispatch-on-ingestion case. The host file's other two methods
 * (`test_a_team_member_is_authorized_on_their_inboxs_channel`,
 * `test_a_non_member_is_denied_on_another_teams_inbox_channel`) are NOT
 * ported here: they exercise the same `inbox.{inbox}` channel-
 * authorization closure that
 * Sendtrap\Core\Tests\Feature\InboxChannelAuthorizationTest already covers
 * independently package-side (slice 7), and the host's own
 * WorkspaceChannelIsolationTest already covers host-side (M-N2) — a third
 * copy of the same coverage here would be a diminished duplicate, not new
 * coverage, contrary to §5.1's "package tests pass independently... not a
 * diminished port" rule.
 */
class MessageReceivedBroadcastTest extends PackageTestCase
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
            'Subject: Hello',
            'MIME-Version: 1.0',
            'Content-Type: text/plain; charset="utf-8"',
            '',
            'Body content.',
            '',
        ]);
    }

    public function test_it_dispatches_message_received_on_ingestion(): void
    {
        Storage::fake('local');
        // Fake only MessageReceived: it's dispatched inline (not via the
        // queue), so a blanket Event::fake() risks masking other real
        // events the ingestion path/factories depend on.
        Event::fake([MessageReceived::class]);
        $inbox = $this->makeInbox();

        ProcessIncomingMessage::dispatchSync($inbox->id, $this->sampleEml());

        $message = $inbox->messages()->first();

        Event::assertDispatched(MessageReceived::class, fn (MessageReceived $event) => $event->message->is($message)
        );
    }

    public function test_broadcast_on_returns_a_private_channel_named_for_the_inbox(): void
    {
        $message = Message::factory()->make(['inbox_id' => 42]);

        $channels = (new MessageReceived($message))->broadcastOn();

        $this->assertCount(1, $channels);
        $this->assertInstanceOf(PrivateChannel::class, $channels[0]);
        $this->assertSame('private-inbox.42', $channels[0]->name);
    }

    public function test_broadcast_as_returns_the_message_received_alias(): void
    {
        // inbox_id overridden so make() doesn't persist the factory's
        // nested workspace-keyed inbox/project chain (Phase 3b slice 2,
        // M-2) — this test never needs a real inbox row.
        $message = Message::factory()->make(['inbox_id' => 42]);

        $this->assertSame('message.received', (new MessageReceived($message))->broadcastAs());
    }

    public function test_broadcast_with_contains_exactly_the_expected_summary_keys(): void
    {
        $message = Message::factory()->make([
            'inbox_id' => 42,
            'id' => 7,
            'subject' => 'Welcome aboard',
            'from_address' => 'alice@example.com',
            'from_name' => 'Alice Sender',
            'has_attachments' => true,
            'received_at' => now(),
        ]);

        $payload = (new MessageReceived($message))->broadcastWith();

        $this->assertSame([
            'id' => 7,
            'subject' => 'Welcome aboard',
            'from_address' => 'alice@example.com',
            'from_name' => 'Alice Sender',
            'has_attachments' => true,
            'received_at' => $message->received_at->toIso8601String(),
        ], $payload);
        $this->assertSame(
            ['id', 'subject', 'from_address', 'from_name', 'has_attachments', 'received_at'],
            array_keys($payload)
        );
    }
}
