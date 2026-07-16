<?php

namespace Sendtrap\Core\Tests\Feature;

use Illuminate\Support\Facades\Gate;
use Sendtrap\Core\Contracts\Workspace as WorkspaceContract;
use Sendtrap\Core\Contracts\WorkspaceAccess;
use Sendtrap\Core\Models\Message;
use Sendtrap\Core\Models\Project;
use Sendtrap\Core\Models\Workspace;
use Sendtrap\Core\Tests\PackageTestCase;

/**
 * Plan 06 Phase 3b slice 9 (§5.1 bucket (c), split not moved whole): the
 * MessagePolicy half of the host's former ProjectAuthorizationTest —
 * `delete` is core-only, WorkspaceAccess-based (D-18), and MessagePolicy
 * already lives in the package (slice 8). The original host test drove
 * this through the session `DELETE /messages/{message}` route
 * (MessageController's `messages.destroy`); that route is registered in
 * the HOST's own routes/web.php even though the controller class itself
 * moved (H-5 cascade, same as messages.htmlcheck — see
 * MessageHtmlCheckTest's docblock), so a package-standalone test can't hit
 * it. This tests the registered Gate policy directly instead — the same
 * decision `$this->authorize('delete', $message)` resolves to.
 */
class MessagePolicyAuthorizationTest extends PackageTestCase
{
    protected function makeMessage(): Message
    {
        $workspace = Workspace::factory()->create();
        $project = $workspace->projects()->create(['name' => 'P']);
        $inbox = $project->inboxes()->create(['name' => 'Inbox']);

        return Message::factory()->for($inbox)->create();
    }

    protected function denyWorkspaceAccess(): void
    {
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
    }

    public function test_a_user_the_bound_workspace_access_denies_cannot_delete_the_message(): void
    {
        $message = $this->makeMessage();
        $this->denyWorkspaceAccess();

        $this->assertFalse(Gate::forUser((object) [])->allows('delete', $message));
    }

    public function test_a_user_the_bound_workspace_access_allows_can_delete_the_message(): void
    {
        // PackageTestCase's default WorkspaceAccess binding (AllowAllWorkspaceAccess).
        $message = $this->makeMessage();

        $this->assertTrue(Gate::forUser((object) [])->allows('delete', $message));
    }

    public function test_a_message_whose_project_has_no_workspace_denies_delete(): void
    {
        $project = Project::factory()->state(['workspace_id' => null])->create();
        $inbox = $project->inboxes()->create(['name' => 'Inbox']);
        $message = Message::factory()->for($inbox)->create();

        $this->assertFalse(Gate::forUser((object) [])->allows('delete', $message));
    }
}
