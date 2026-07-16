<?php

namespace Sendtrap\Core\Tests\Feature;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Broadcast;
use ReflectionMethod;
use Sendtrap\Core\Contracts\Workspace as WorkspaceContract;
use Sendtrap\Core\Contracts\WorkspaceAccess;
use Sendtrap\Core\Models\Inbox;
use Sendtrap\Core\Models\Workspace;
use Sendtrap\Core\Tests\PackageTestCase;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;

/**
 * Plan 06 Phase 3b slice 7 (§5.1, M-N2 addition): the package-side,
 * independently-authored twin of the host's WorkspaceChannelIsolationTest —
 * same inbox.{inbox} channel-authorization behavior, against a Team-less
 * Workspace+Project+Inbox fixture with no `CreatesWorkspaceFixtures`
 * involved at all (that trait is Cloud/Jetstream-shaped by design and
 * doesn't exist in the package). The host's own test stays host-side
 * (M-N2's "stays host-side... cross-workspace isolation test" bucket) and
 * is unaffected by this slice's move of the channel *registration* itself
 * (§1.6) — this is new coverage, not a port.
 *
 * Uses the same verifyUserCanAccessChannel() reflection approach as the
 * host's version: NullBroadcaster::auth() never invokes the channels.php
 * closure, so an HTTP round trip can't exercise it directly.
 */
class InboxChannelAuthorizationTest extends PackageTestCase
{
    protected function invokeChannelAuth(string $channel, object $user): mixed
    {
        $broadcaster = Broadcast::driver();

        $method = new ReflectionMethod($broadcaster, 'verifyUserCanAccessChannel');
        $method->setAccessible(true);

        $request = Request::create('/broadcasting/auth');
        $request->setUserResolver(fn () => $user);

        return $method->invoke($broadcaster, $request, $channel);
    }

    protected function makeInbox(): Inbox
    {
        $workspace = Workspace::factory()->create();
        $project = $workspace->projects()->create(['name' => 'P']);

        return $project->inboxes()->create(['name' => 'Inbox']);
    }

    public function test_a_workspace_member_is_authorized_on_their_inboxs_channel(): void
    {
        $inbox = $this->makeInbox();

        // NullBroadcaster's validAuthenticationResponse() returns null, so
        // assert not-false (a false/denied result throws instead) — same
        // assertion style as the host's WorkspaceChannelIsolationTest.
        $this->assertNotFalse($this->invokeChannelAuth('inbox.'.$inbox->id, (object) []));
    }

    public function test_a_user_the_bound_workspace_access_denies_is_rejected(): void
    {
        $inbox = $this->makeInbox();

        // The package's default PackageTestCase binding (AllowAllWorkspaceAccess)
        // always allows, so this test rebinds a discriminating fake for the
        // one assertion that actually needs WorkspaceAccess to say no —
        // proving the channel closure's `canView()` result really does gate
        // the outcome, not just the null-workspace branch below.
        $this->app->instance(WorkspaceAccess::class, new class implements WorkspaceAccess
        {
            public function canView(object $user, WorkspaceContract $workspace): bool
            {
                return false;
            }

            public function canManage(object $user, WorkspaceContract $workspace): bool
            {
                return false;
            }
        });

        $this->expectException(AccessDeniedHttpException::class);

        $this->invokeChannelAuth('inbox.'.$inbox->id, (object) []);
    }

    public function test_a_null_workspace_denies_even_though_workspace_access_would_allow(): void
    {
        $inbox = $this->makeInbox();
        $inbox->project->workspace_id = null;
        $inbox->project->save();

        $this->expectException(AccessDeniedHttpException::class);

        $this->invokeChannelAuth('inbox.'.$inbox->id, (object) []);
    }
}
