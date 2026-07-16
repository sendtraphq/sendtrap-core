<?php

namespace Sendtrap\Core\Tests\Feature;

use Illuminate\Support\Facades\Gate;
use Sendtrap\Core\Contracts\Workspace as WorkspaceContract;
use Sendtrap\Core\Contracts\WorkspaceAccess;
use Sendtrap\Core\Models\Project;
use Sendtrap\Core\Models\Workspace;
use Sendtrap\Core\Tests\PackageTestCase;

/**
 * Plan 06 Phase 3b slice 9 (§5.1 bucket (c), split not moved whole): the
 * ProjectPolicy half of the host's former ProjectAuthorizationTest — its
 * `update`/`delete` methods are core-only, WorkspaceAccess-based (D-18),
 * and ProjectPolicy itself already lives in the package (slice 8). The
 * original host test drove this through the session `PUT`/`DELETE
 * /projects/{project}` routes; those are ProjectController routes, and
 * ProjectController stays host-side for all of Phase 3 (§1.6's H-5
 * decision) — a package-standalone test can't hit them. This tests the
 * registered Gate policy directly instead
 * (Sendtrap\Core\SendtrapCoreServiceProvider registers
 * `Gate::policy(Project::class, ProjectPolicy::class)`), which is what
 * `$this->authorize('update', $project)` resolves to either way — same
 * authorization decision, no HTTP round trip required.
 */
class ProjectPolicyAuthorizationTest extends PackageTestCase
{
    protected function makeProject(): Project
    {
        $workspace = Workspace::factory()->create();

        return $workspace->projects()->create(['name' => 'P']);
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

    public function test_a_user_the_bound_workspace_access_denies_cannot_update_the_project(): void
    {
        $project = $this->makeProject();
        $this->denyWorkspaceAccess();

        $this->assertFalse(Gate::forUser((object) [])->allows('update', $project));
    }

    public function test_a_user_the_bound_workspace_access_allows_can_update_the_project(): void
    {
        // PackageTestCase's default WorkspaceAccess binding (AllowAllWorkspaceAccess).
        $project = $this->makeProject();

        $this->assertTrue(Gate::forUser((object) [])->allows('update', $project));
    }

    public function test_a_user_the_bound_workspace_access_denies_cannot_delete_the_project(): void
    {
        $project = $this->makeProject();
        $this->denyWorkspaceAccess();

        $this->assertFalse(Gate::forUser((object) [])->allows('delete', $project));
    }

    public function test_a_user_the_bound_workspace_access_allows_can_delete_the_project(): void
    {
        $project = $this->makeProject();

        $this->assertTrue(Gate::forUser((object) [])->allows('delete', $project));
    }

    public function test_a_project_with_no_workspace_denies_both_update_and_delete(): void
    {
        $project = Project::factory()->state(['workspace_id' => null])->create();

        // Even though the bound WorkspaceAccess would allow everything —
        // a null workspace denies before WorkspaceAccess is ever consulted
        // (§5.0.1 row 1: deny-by-default, never "no scope").
        $this->assertFalse(Gate::forUser((object) [])->allows('update', $project));
        $this->assertFalse(Gate::forUser((object) [])->allows('delete', $project));
    }
}
