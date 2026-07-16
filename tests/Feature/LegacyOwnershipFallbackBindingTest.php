<?php

namespace Sendtrap\Core\Tests\Feature;

use Sendtrap\Core\Contracts\LegacyOwnershipFallback;
use Sendtrap\Core\Models\Project;
use Sendtrap\Core\Models\Workspace;
use Sendtrap\Core\Support\NullLegacyOwnershipFallback;
use Sendtrap\Core\Tests\PackageTestCase;

/**
 * Plan 06 Phase 3b slice 3 (§3.2/§6): under the package's own Testbench
 * host — no Cloud provider, no host binding — the bindIf default resolves,
 * and Inbox::effectiveAllowedIps()'s post-swap last-resort tier (the
 * contract call that replaced the slice-2-verbatim
 * `$this->project?->team?->allowed_ips ?? []` chain, M-N1) degrades to []
 * (no restriction), exactly as the pre-swap chain did here.
 */
class LegacyOwnershipFallbackBindingTest extends PackageTestCase
{
    public function test_the_container_resolves_the_package_default_via_bind_if(): void
    {
        $this->assertInstanceOf(
            NullLegacyOwnershipFallback::class,
            $this->app->make(LegacyOwnershipFallback::class)
        );
    }

    public function test_a_pre_existing_host_binding_wins_over_the_bind_if_default(): void
    {
        $host = new class extends NullLegacyOwnershipFallback {};
        $this->app->instance(LegacyOwnershipFallback::class, $host);

        $this->assertSame($host, $this->app->make(LegacyOwnershipFallback::class));
    }

    public function test_effective_allowed_ips_last_resort_answers_no_restriction_here(): void
    {
        // A genuinely unresolvable owner: no workspace on the project (the
        // not-yet-backfilled shape), no allowlist at the inbox or project
        // tier — the account tier's last resort is the contract call.
        $project = Project::factory()->create(['workspace_id' => null]);
        $inbox = $project->inboxes()->create(['name' => 'Inbox']);

        $this->assertSame([], $inbox->effectiveAllowedIps());
    }

    public function test_the_workspace_tier_still_wins_before_the_fallback_is_consulted(): void
    {
        $workspace = Workspace::factory()->create(['allowed_ips' => ['198.51.100.7']]);
        $project = $workspace->projects()->create(['name' => 'P']);
        $inbox = $project->inboxes()->create(['name' => 'Inbox']);

        $this->assertSame(['198.51.100.7'], $inbox->effectiveAllowedIps());
    }
}
