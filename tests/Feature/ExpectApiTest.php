<?php

namespace Sendtrap\Core\Tests\Feature;

use Closure;
use Illuminate\Support\Facades\Storage;
use Sendtrap\Core\Contracts\MessageWaiter;
use Sendtrap\Core\Jobs\ProcessIncomingMessage;
use Sendtrap\Core\Models\Inbox;
use Sendtrap\Core\Models\Message;
use Sendtrap\Core\Models\Workspace;
use Sendtrap\Core\Tests\PackageTestCase;

class ExpectApiTest extends PackageTestCase
{
    protected function makeInbox(): Inbox
    {
        $workspace = Workspace::factory()->create();
        $project = $workspace->projects()->create(['name' => 'P']);

        return $project->inboxes()->create(['name' => 'Inbox']);
    }

    protected function expectJson(Inbox $inbox, array $body)
    {
        return $this->withToken($inbox->api_token)->postJson('/api/v1/expect', $body);
    }

    public function test_it_matches_on_metadata_and_reports_diagnostics(): void
    {
        $inbox = $this->makeInbox();
        Message::factory()->for($inbox)->create(['subject' => 'Welcome to Acme', 'test_id' => 'run-1']);
        Message::factory()->for($inbox)->create(['subject' => 'Password reset', 'test_id' => 'run-1']);

        $this->expectJson($inbox, [
            'match' => [
                ['field' => 'subject', 'op' => 'contains', 'value' => 'Welcome'],
                ['field' => 'test_id', 'op' => 'equals', 'value' => 'run-1'],
            ],
        ])
            ->assertOk()
            ->assertJsonPath('matched', true)
            ->assertJsonPath('status', 'matched')
            ->assertJsonPath('candidates_seen', 2)
            ->assertJsonPath('count.actual', 1)
            ->assertJsonPath('messages.0.subject', 'Welcome to Acme')
            ->assertJsonPath('conditions.0.passed', true)
            ->assertJsonPath('conditions.1.passed', true);
    }

    public function test_a_miss_distinguishes_no_candidates_from_no_match(): void
    {
        $inbox = $this->makeInbox();

        // Empty inbox: nothing in scope at all.
        $this->expectJson($inbox, [
            'match' => [['field' => 'subject', 'op' => 'contains', 'value' => 'Welcome']],
        ])
            ->assertOk()
            ->assertJsonPath('matched', false)
            ->assertJsonPath('status', 'no_candidates')
            ->assertJsonPath('conditions.0.passed', null);

        // A message exists but doesn't match: diagnostics evaluate against it.
        Message::factory()->for($inbox)->create(['subject' => 'Goodbye']);

        $this->expectJson($inbox, [
            'match' => [['field' => 'subject', 'op' => 'contains', 'value' => 'Welcome']],
        ])
            ->assertOk()
            ->assertJsonPath('status', 'no_match')
            ->assertJsonPath('candidates_seen', 1)
            ->assertJsonPath('conditions.0.passed', false)
            ->assertJsonPath('conditions.0.actual', 'Goodbye');
    }

    public function test_assertions_distinguish_arrived_but_wrong(): void
    {
        $inbox = $this->makeInbox();
        $message = Message::factory()->for($inbox)->create([
            'subject' => 'Welcome',
            'to' => [['address' => 'alice@example.com', 'name' => null]],
        ]);

        $response = $this->expectJson($inbox, [
            'match' => [['field' => 'subject', 'op' => 'equals', 'value' => 'Welcome']],
            'assert' => [
                ['field' => 'to', 'op' => 'contains', 'value' => 'bob@example.com'],
            ],
        ]);

        $response->assertOk()
            ->assertJsonPath('matched', false)
            ->assertJsonPath('status', 'assertions_failed')
            ->assertJsonPath('assertions_failed_on', $message->id)
            ->assertJsonPath('conditions.1.type', 'assert')
            ->assertJsonPath('conditions.1.passed', false)
            ->assertJsonPath('conditions.1.actual.0', 'alice@example.com');
    }

    public function test_body_link_header_attachment_and_check_conditions(): void
    {
        Storage::fake('local');
        $inbox = $this->makeInbox();
        $raw = implode("\r\n", [
            'From: Sender <sender@example.com>',
            'To: alice@example.com',
            'Subject: Verify your account',
            'X-Campaign: onboarding',
            'MIME-Version: 1.0',
            'Content-Type: multipart/mixed; boundary="b1"',
            '',
            '--b1',
            'Content-Type: text/html; charset=utf-8',
            '',
            '<html><body><a href="https://example.com/verify?code=123">Verify</a></body></html>',
            '--b1',
            'Content-Type: text/plain; name="report.csv"',
            'Content-Disposition: attachment; filename="report.csv"',
            '',
            'a,b',
            '--b1--',
            '',
        ]);
        (new ProcessIncomingMessage($inbox->id, $raw, 'sender@example.com', ['alice@example.com']))->handle();

        $this->expectJson($inbox, [
            'match' => [['field' => 'subject', 'op' => 'starts_with', 'value' => 'Verify']],
            'assert' => [
                ['field' => 'html', 'op' => 'contains', 'value' => 'Verify'],
                ['field' => 'links', 'op' => 'matches', 'value' => 'example\.com/verify\?code=\d+'],
                ['field' => 'header.X-Campaign', 'op' => 'equals', 'value' => 'onboarding'],
                ['field' => 'attachments.count', 'op' => 'equals', 'value' => 1],
                ['field' => 'attachments.filename', 'op' => 'ends_with', 'value' => '.csv'],
                ['field' => 'checks.missing_text_part', 'op' => 'equals', 'value' => false],
                ['field' => 'has_unresolved_merge_tags', 'op' => 'equals', 'value' => false],
            ],
        ])
            ->assertOk()
            ->assertJsonPath('matched', true)
            ->assertJsonPath('status', 'matched');
    }

    public function test_count_exactly_and_at_least_semantics(): void
    {
        $inbox = $this->makeInbox();
        Message::factory()->count(2)->for($inbox)->create(['test_id' => 'run-9']);

        $this->expectJson($inbox, [
            'match' => [['field' => 'test_id', 'op' => 'equals', 'value' => 'run-9']],
            'count' => ['at_least' => 2],
        ])->assertOk()->assertJsonPath('matched', true)->assertJsonPath('count.actual', 2);

        $this->expectJson($inbox, [
            'match' => [['field' => 'test_id', 'op' => 'equals', 'value' => 'run-9']],
            'count' => ['exactly' => 1],
        ])
            ->assertOk()
            ->assertJsonPath('matched', false)
            ->assertJsonPath('status', 'count_mismatch')
            ->assertJsonPath('count.actual', 2);
    }

    public function test_scope_cursors_exclude_old_messages(): void
    {
        $inbox = $this->makeInbox();
        $old = Message::factory()->for($inbox)->create(['subject' => 'Welcome']);

        $this->expectJson($inbox, [
            'match' => [['field' => 'subject', 'op' => 'equals', 'value' => 'Welcome']],
            'scope' => ['after_message_id' => $old->id],
        ])
            ->assertOk()
            ->assertJsonPath('matched', false)
            ->assertJsonPath('status', 'no_candidates');
    }

    public function test_strict_mode_returns_422_on_an_unmet_expectation(): void
    {
        $inbox = $this->makeInbox();

        $this->expectJson($inbox, [
            'match' => [['field' => 'subject', 'op' => 'contains', 'value' => 'Welcome']],
            'mode' => 'strict',
        ])
            ->assertStatus(422)
            ->assertJsonPath('matched', false)
            ->assertJsonPath('status', 'no_candidates');
    }

    public function test_validation_rejects_unknown_fields_operators_and_bad_regexes(): void
    {
        $inbox = $this->makeInbox();

        $this->expectJson($inbox, [
            'match' => [['field' => 'body', 'op' => 'contains', 'value' => 'x']],
        ])->assertStatus(422)->assertJsonPath('message', 'Unknown field "body".');

        $this->expectJson($inbox, [
            'match' => [['field' => 'size', 'op' => 'contains', 'value' => 'x']],
        ])->assertStatus(422);

        $this->expectJson($inbox, [
            'match' => [['field' => 'subject', 'op' => 'matches', 'value' => '([unclosed']],
        ])->assertStatus(422);

        $this->expectJson($inbox, [])->assertStatus(422);

        $this->expectJson($inbox, [
            'match' => [['field' => 'subject', 'op' => 'contains', 'value' => 'x']],
            'count' => ['at_least' => 1, 'exactly' => 2],
        ])->assertStatus(422);
    }

    public function test_a_message_arriving_mid_wait_satisfies_the_expectation(): void
    {
        $inbox = $this->makeInbox();

        // Deterministic waiter: create the message between polls — this is
        // exactly the seam a notification-backed waiter will later fill.
        $this->app->bind(MessageWaiter::class, fn () => new class($inbox) implements MessageWaiter
        {
            public function __construct(private Inbox $inbox) {}

            public function wait(int $timeoutMs, Closure $poll): bool
            {
                Message::factory()->for($this->inbox)->create(['subject' => 'Welcome late']);

                return $poll();
            }
        });

        $this->expectJson($inbox, [
            'match' => [['field' => 'subject', 'op' => 'contains', 'value' => 'Welcome']],
            'wait' => ['timeout_ms' => 5000],
        ])
            ->assertOk()
            ->assertJsonPath('matched', true)
            ->assertJsonPath('messages.0.subject', 'Welcome late');
    }

    public function test_mark_read_consumes_matched_messages(): void
    {
        $inbox = $this->makeInbox();
        $message = Message::factory()->for($inbox)->create(['subject' => 'Welcome', 'is_read' => false]);

        $this->expectJson($inbox, [
            'match' => [['field' => 'subject', 'op' => 'equals', 'value' => 'Welcome']],
            'mark_read' => true,
        ])->assertOk()->assertJsonPath('matched', true);

        $this->assertTrue($message->fresh()->is_read);

        // unread_only scoping then no longer sees it.
        $this->expectJson($inbox, [
            'match' => [['field' => 'subject', 'op' => 'equals', 'value' => 'Welcome']],
            'scope' => ['unread_only' => true],
        ])->assertOk()->assertJsonPath('status', 'no_candidates');
    }

    public function test_it_cannot_see_another_inboxes_messages(): void
    {
        $a = $this->makeInbox();
        $b = $this->makeInbox();
        Message::factory()->for($b)->create(['subject' => 'Welcome']);

        $this->expectJson($a, [
            'match' => [['field' => 'subject', 'op' => 'equals', 'value' => 'Welcome']],
        ])->assertOk()->assertJsonPath('status', 'no_candidates');
    }

    public function test_the_wait_route_sits_behind_the_wait_limiter(): void
    {
        $inbox = $this->makeInbox();

        $this->withToken($inbox->api_token)
            ->postJson('/api/v1/expect', ['match' => [['field' => 'subject', 'op' => 'exists']]])
            ->assertHeader('X-RateLimit-Limit', 15);
    }
}
