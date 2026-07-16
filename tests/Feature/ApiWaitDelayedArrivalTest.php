<?php

namespace Sendtrap\Core\Tests\Feature;

use Illuminate\Cache\RateLimiter;
use Illuminate\Support\Facades\DB;
use Sendtrap\Core\Models\Inbox;
use Sendtrap\Core\Models\Message;
use Sendtrap\Core\Models\Workspace;
use Sendtrap\Core\Tests\PackageTestCase;

/**
 * Characterization tests for the genuine "message arrives mid-wait" path of
 * GET /api/v1/messages?wait=N, plus the inbox-api-wait limiter's actual
 * consumption behavior. See
 * Sendtrap\Core\Http\Controllers\Api\MessageController — busyWaitUntil()
 * uses raw usleep() (no Sleep::fake()-compatible seam), so the
 * delayed-arrival test below uses a DB::listen side effect to create the
 * matching row in-process, between the query pair of the request's *first*
 * busy-wait re-check — this is real, not simulated: at the moment the
 * request starts, the message genuinely does not exist yet.
 *
 * Moved to the package in Plan 06 Phase 3b slice 7 (§5.1 bucket (b)).
 */
class ApiWaitDelayedArrivalTest extends PackageTestCase
{
    protected function makeInbox(): Inbox
    {
        $workspace = Workspace::factory()->create();
        $project = $workspace->projects()->create(['name' => 'P']);

        return $project->inboxes()->create(['name' => 'Inbox']);
    }

    public function test_a_message_created_mid_wait_is_returned_without_waiting_out_the_full_timeout(): void
    {
        $inbox = $this->makeInbox();

        $queriesAgainstMessages = 0;
        $created = false;

        // index()'s pre-loop check runs filteredMessages()->paginate(), which
        // fires 2 queries (COUNT + SELECT) against the messages table and
        // finds nothing. The first busyWaitUntil() re-check (after its first
        // real usleep(250ms)) repeats the same pair as queries #3/#4. Create
        // the message right after query #3 (the COUNT) fires, so the SELECT
        // that immediately follows within that same paginate() call — and so
        // the request itself — picks it up. This is the crude, in-process
        // "option (a)" seam: no Sleep::fake() exists on this raw-usleep path.
        $listener = function ($query) use (&$queriesAgainstMessages, &$created, $inbox) {
            if ($created || ! str_contains($query->sql, 'messages')) {
                return;
            }

            $queriesAgainstMessages++;

            if ($queriesAgainstMessages === 3) {
                $created = true;
                Message::factory()->for($inbox)->create(['test_id' => 'run-1']);
            }
        };

        DB::listen($listener);

        $started = microtime(true);

        $response = $this->withToken($inbox->api_token)
            ->getJson('/api/v1/messages?test_id=run-1&wait=5');

        $elapsed = microtime(true) - $started;

        $response->assertOk()->assertJsonCount(1, 'data');

        // The message did not exist at request start (unlike
        // test_wait_returns_immediately_when_a_match_already_exists in
        // InboxApiTest, which asserts <1.0s) — the request had to fall
        // through into busyWaitUntil() and sleep through at least its first
        // ~250ms backoff interval before the row existed.
        $this->assertGreaterThanOrEqual(0.2, $elapsed);
        $this->assertLessThan(4.0, $elapsed);
        $this->assertTrue($created, 'the DB::listen side effect never fired — query shape assumption is stale');
    }

    // --- inbox-api-wait limiter: actually consumed vs not ------------------

    public function test_a_wait_request_that_times_out_consumes_the_wait_limiter(): void
    {
        $inbox = $this->makeInbox();
        $limiter = app(RateLimiter::class);

        $this->assertSame(0, $limiter->attempts($inbox->api_token));

        $this->withToken($inbox->api_token)
            ->getJson('/api/v1/messages?test_id=nope&wait=1')
            ->assertOk()
            ->assertJsonCount(0, 'data');

        $this->assertSame(1, $limiter->attempts($inbox->api_token));
    }

    public function test_a_wait_request_that_matches_immediately_does_not_consume_the_wait_limiter(): void
    {
        // CHARACTERIZATION: index() only calls enforceWaitRateLimit() when
        // the *first* (pre-loop) query comes back empty — a wait request
        // that finds a match on that first check never touches the
        // inbox-api-wait limiter at all, even though it asked to wait.
        $inbox = $this->makeInbox();
        Message::factory()->for($inbox)->create(['test_id' => 'run-1']);
        $limiter = app(RateLimiter::class);

        $this->withToken($inbox->api_token)
            ->getJson('/api/v1/messages?test_id=run-1&wait=5')
            ->assertOk()
            ->assertJsonCount(1, 'data');

        $this->assertSame(0, $limiter->attempts($inbox->api_token));
    }

    public function test_a_plain_list_request_without_wait_does_not_consume_the_wait_limiter(): void
    {
        $inbox = $this->makeInbox();
        Message::factory()->for($inbox)->create();
        $limiter = app(RateLimiter::class);

        $this->withToken($inbox->api_token)
            ->getJson('/api/v1/messages')
            ->assertOk();

        $this->assertSame(0, $limiter->attempts($inbox->api_token));
    }
}
