<?php

namespace Sendtrap\Core\Tests\Feature;

use Illuminate\Cache\RateLimiter;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Sendtrap\Core\Models\Inbox;
use Sendtrap\Core\Models\Message;
use Sendtrap\Core\Models\Workspace;
use Sendtrap\Core\Tests\PackageTestCase;

/**
 * Moved to the package from a host suite — the fixture rework
 * is the one mechanical edit: a host-side user/team fixture becomes
 * `Workspace::factory()->create()` +
 * `$workspace->projects()`, no Team involved.
 */
class InboxApiTest extends PackageTestCase
{
    protected function makeInbox(): Inbox
    {
        $workspace = Workspace::factory()->create();
        $project = $workspace->projects()->create(['name' => 'P']);

        return $project->inboxes()->create(['name' => 'Inbox']);
    }

    public function test_it_rejects_requests_without_a_token(): void
    {
        $this->getJson('/api/v1/messages')->assertUnauthorized();
    }

    public function test_it_rejects_an_invalid_token(): void
    {
        $this->withToken('nope')->getJson('/api/v1/messages')->assertUnauthorized();
    }

    public function test_it_lists_messages_for_the_authenticated_inbox(): void
    {
        $inbox = $this->makeInbox();
        Message::factory()->count(3)->for($inbox)->create();

        $this->withToken($inbox->api_token)
            ->getJson('/api/v1/messages')
            ->assertOk()
            ->assertJsonCount(3, 'data');
    }

    public function test_it_cannot_read_messages_from_another_inbox(): void
    {
        $a = $this->makeInbox();
        $b = $this->makeInbox();
        $message = Message::factory()->for($b)->create();

        $this->withToken($a->api_token)
            ->getJson("/api/v1/messages/{$message->id}")
            ->assertNotFound();
    }

    public function test_it_bulk_deletes_all_messages_in_the_inbox(): void
    {
        $inbox = $this->makeInbox();
        Message::factory()->count(3)->for($inbox)->create();
        $other = $this->makeInbox();
        Message::factory()->for($other)->create();

        $this->withToken($inbox->api_token)
            ->deleteJson('/api/v1/messages')
            ->assertOk()
            ->assertJson(['deleted' => 3]);

        $this->assertSame(0, $inbox->messages()->count());
        $this->assertSame(1, $other->messages()->count());
    }

    public function test_it_downloads_an_attachment(): void
    {
        Storage::fake('local');
        $inbox = $this->makeInbox();
        $message = Message::factory()->for($inbox)->create();
        Storage::disk('local')->put('messages/attachments/'.$message->id.'/note.txt', 'hello world');
        $attachment = $message->attachments()->create([
            'filename' => 'note.txt',
            'content_type' => 'text/plain',
            'size' => 11,
            'path' => 'messages/attachments/'.$message->id.'/note.txt',
            'is_inline' => false,
        ]);

        $this->withToken($inbox->api_token)
            ->get("/api/v1/messages/{$message->id}/attachments/{$attachment->id}")
            ->assertOk()
            ->assertDownload('note.txt');
    }

    public function test_it_cannot_download_an_attachment_from_another_inbox(): void
    {
        Storage::fake('local');
        $a = $this->makeInbox();
        $b = $this->makeInbox();
        $message = Message::factory()->for($b)->create();
        $attachment = $message->attachments()->create([
            'filename' => 'note.txt',
            'content_type' => 'text/plain',
            'size' => 11,
            'path' => 'messages/attachments/'.$message->id.'/note.txt',
            'is_inline' => false,
        ]);

        $this->withToken($a->api_token)
            ->get("/api/v1/messages/{$message->id}/attachments/{$attachment->id}")
            ->assertNotFound();
    }

    public function test_message_detail_urls_point_at_the_token_api(): void
    {
        $inbox = $this->makeInbox();
        $message = Message::factory()->for($inbox)->create();

        $response = $this->withToken($inbox->api_token)
            ->getJson("/api/v1/messages/{$message->id}")
            ->assertOk();

        $response->assertJsonPath('data.urls.raw', route('api.messages.raw', $message));
        $response->assertJsonPath('data.urls.html', route('api.messages.html', $message));
        $this->assertStringContainsString('/api/v1/messages/', $response->json('data.urls.raw'));
    }

    public function test_the_rate_limit_is_scaled_by_plan(): void
    {
        // Package's own UnlimitedEntitlements reference fake (§5.3) answers
        // apiRequestsPerMinute() with null (unlimited) for every workspace —
        // the closure's own null-coalesce to 300 is what's under test here,
        // not a specific plan tier (which has no package-side equivalent).
        $inbox = $this->makeInbox()->load('project.workspace');

        $request = Request::create('/api/v1/messages');
        $request->headers->set('Authorization', 'Bearer '.$inbox->api_token);

        $limit = call_user_func(
            app(RateLimiter::class)->limiter('inbox-api'),
            $request
        );

        $this->assertSame(300, $limit->maxAttempts);
    }

    public function test_the_rate_limit_falls_back_when_no_inbox_is_bound(): void
    {
        $request = Request::create('/api/v1/messages');

        $limit = call_user_func(
            app(RateLimiter::class)->limiter('inbox-api'),
            $request
        );

        $this->assertSame(60, $limit->maxAttempts);
    }

    public function test_invalid_tokens_share_an_ip_scoped_rate_limit(): void
    {
        $first = Request::create('/api/v1/messages', server: ['REMOTE_ADDR' => '203.0.113.10']);
        $first->headers->set('Authorization', 'Bearer random-token-one');

        $second = Request::create('/api/v1/messages', server: ['REMOTE_ADDR' => '203.0.113.10']);
        $second->headers->set('Authorization', 'Bearer random-token-two');

        $limiter = app(RateLimiter::class)->limiter('inbox-api');
        $firstLimit = call_user_func($limiter, $first);
        $secondLimit = call_user_func($limiter, $second);

        $this->assertSame('ip:203.0.113.10', $firstLimit->key);
        $this->assertSame($firstLimit->key, $secondLimit->key);
        $this->assertSame(60, $firstLimit->maxAttempts);
    }

    public function test_it_filters_messages_by_recipient(): void
    {
        $inbox = $this->makeInbox();
        Message::factory()->for($inbox)->create(['to' => [['address' => 'match@example.com', 'name' => null]]]);
        Message::factory()->for($inbox)->create(['to' => [['address' => 'other@example.com', 'name' => null]]]);

        $this->withToken($inbox->api_token)
            ->getJson('/api/v1/messages?to=match@example.com')
            ->assertOk()
            ->assertJsonCount(1, 'data');
    }

    public function test_it_filters_messages_by_a_bcc_only_envelope_recipient(): void
    {
        $inbox = $this->makeInbox();
        Message::factory()->for($inbox)->create([
            'to' => [['address' => 'primary@example.com', 'name' => null]],
            'envelope_to' => ['primary@example.com', 'bcc-secret@example.com'],
        ]);

        $this->withToken($inbox->api_token)
            ->getJson('/api/v1/messages?to=bcc-secret@example.com')
            ->assertOk()
            ->assertJsonCount(1, 'data');
    }

    public function test_it_filters_messages_by_test_id(): void
    {
        $inbox = $this->makeInbox();
        Message::factory()->for($inbox)->create(['test_id' => 'run-1']);
        Message::factory()->for($inbox)->create(['test_id' => 'run-2']);

        $this->withToken($inbox->api_token)
            ->getJson('/api/v1/messages?test_id=run-1')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.test_id', 'run-1');
    }

    public function test_wait_returns_immediately_when_a_match_already_exists(): void
    {
        $inbox = $this->makeInbox();
        Message::factory()->for($inbox)->create(['test_id' => 'run-1']);

        $started = microtime(true);

        $this->withToken($inbox->api_token)
            ->getJson('/api/v1/messages?test_id=run-1&wait=5')
            ->assertOk()
            ->assertJsonCount(1, 'data');

        $this->assertLessThan(1.0, microtime(true) - $started);
    }

    public function test_wait_times_out_and_returns_no_messages(): void
    {
        $inbox = $this->makeInbox();

        $this->withToken($inbox->api_token)
            ->getJson('/api/v1/messages?test_id=nope&wait=1')
            ->assertOk()
            ->assertJsonCount(0, 'data');
    }

    public function test_assert_reports_a_match(): void
    {
        $inbox = $this->makeInbox();
        Message::factory()->for($inbox)->create(['subject' => 'Welcome aboard']);

        $this->withToken($inbox->api_token)
            ->postJson('/api/v1/assert', ['subject_contains' => 'Welcome', 'timeout' => 0])
            ->assertOk()
            ->assertJson(['matched' => true]);
    }

    public function test_assert_reports_no_match_without_blocking_past_a_zero_timeout(): void
    {
        $inbox = $this->makeInbox();

        $started = microtime(true);

        $this->withToken($inbox->api_token)
            ->postJson('/api/v1/assert', ['subject_contains' => 'nope', 'timeout' => 0])
            ->assertOk()
            ->assertJson(['matched' => false, 'message' => null]);

        $this->assertLessThan(1.0, microtime(true) - $started);
    }

    public function test_the_wait_rate_limit_is_tighter_than_the_general_api_limit(): void
    {
        $request = Request::create('/api/v1/assert');

        $limit = call_user_func(
            app(RateLimiter::class)->limiter('inbox-api-wait'),
            $request
        );

        $this->assertSame(15, $limit->maxAttempts);
    }
}
