<?php

namespace Sendtrap\Core\Tests\Feature;

use Sendtrap\Core\Models\Inbox;
use Sendtrap\Core\Models\Message;
use Sendtrap\Core\Models\Workspace;
use Sendtrap\Core\Tests\PackageTestCase;

/**
 * Plan 06 Phase 3b slice 9 (§5.1 bucket (b), split not moved whole): the
 * public-consumption half of the host's former Tests\Feature\InboxShareTest
 * — exercises Sendtrap\Core\Http\Controllers\InboxShareController (package-
 * owned since slice 8) directly. The share token is seeded by creating an
 * InboxShare record directly rather than via the `inboxes.share` route
 * (InboxController::share() stays host-side per H-5, §1.6 — there is no
 * package route to hit for that half), which the host's own
 * InboxShareTest::test_a_team_member_can_create_a_share_link and its
 * siblings still cover.
 */
class InboxShareConsumptionTest extends PackageTestCase
{
    protected function makeInbox(): Inbox
    {
        $workspace = Workspace::factory()->create();
        $project = $workspace->projects()->create(['name' => 'P']);

        return $project->inboxes()->create(['name' => 'Inbox']);
    }

    public function test_anyone_with_the_token_can_view_the_shared_inbox_without_auth(): void
    {
        $inbox = $this->makeInbox();
        Message::factory()->count(2)->for($inbox)->create();
        $token = $inbox->shares()->create(['expires_at' => now()->addDays(7)])->token;

        $this->getJson(route('share.inbox.messages', $token))
            ->assertOk()
            ->assertJsonCount(2, 'data');
    }

    public function test_it_cannot_see_messages_from_another_inbox_via_the_token(): void
    {
        $inbox = $this->makeInbox();
        $other = $this->makeInbox();
        $foreignMessage = Message::factory()->for($other)->create();
        $token = $inbox->shares()->create(['expires_at' => now()->addDays(7)])->token;

        $this->getJson(route('share.inbox.message', [$token, $foreignMessage]))
            ->assertNotFound();
    }

    public function test_an_expired_share_link_is_rejected(): void
    {
        $inbox = $this->makeInbox();
        $token = $inbox->shares()->create(['expires_at' => now()->subDay()])->token;

        $this->getJson(route('share.inbox.messages', $token))
            ->assertStatus(410);
    }
}
