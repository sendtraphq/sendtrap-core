<?php

namespace Sendtrap\Core\Tests\Feature;

use Sendtrap\Core\Models\Workspace;
use Sendtrap\Core\Testing\Concerns\InteractsWithSmtpServer;
use Sendtrap\Core\Tests\PackageTestCase;

/**
 * Plan 06 Phase 3b slice 9 (§5.1, M-N2): the workspace-path half of the
 * host's former WorkspaceAllowedIpsTest — four of its six methods
 * (`test_the_account_tier_reads_the_workspace_allowlist`,
 * `test_inbox_and_project_tiers_still_win_over_the_workspace_tier`,
 * `test_smtp_auth_enforces_the_workspace_allowlist_on_the_wire`,
 * `test_the_api_token_path_enforces_the_workspace_allowlist`) exercise the
 * workspace tier only and move here, reworked against a **new** fixture —
 * `Workspace::factory()->create()` -> `$workspace->projects()->create()` ->
 * `$project->inboxes()->create()`, no `Team`/`User`/`withPersonalTeam()` at
 * all, since the package has no Team concept to construct one from.
 *
 * The remaining two methods
 * (`test_a_null_workspace_falls_back_to_the_team_allowlist`,
 * `test_smtp_auth_still_enforces_the_team_allowlist_when_the_workspace_is_null`)
 * are Cloud-fallback-specific by construction and were already folded into
 * the host's `LegacyOwnershipFallbackTest` (`test_a_null_workspace_falls_
 * back_to_the_team_allowlist_via_the_binding`, `test_smtp_auth_still_
 * enforces_the_team_allowlist_when_the_workspace_is_null`) — this file is
 * the whole original's replacement, not a partial leftover.
 */
class WorkspaceAllowedIpsTest extends PackageTestCase
{
    use InteractsWithSmtpServer;

    protected function makeFixture(): array
    {
        $workspace = Workspace::factory()->create();
        $project = $workspace->projects()->create(['name' => 'P']);
        $inbox = $project->inboxes()->create(['name' => 'Inbox']);

        return [$workspace, $project, $inbox];
    }

    public function test_the_account_tier_reads_the_workspace_allowlist(): void
    {
        [$workspace, , $inbox] = $this->makeFixture();

        $workspace->update(['allowed_ips' => ['198.51.100.7']]);

        $this->assertSame(['198.51.100.7'], $inbox->fresh()->effectiveAllowedIps());
    }

    public function test_inbox_and_project_tiers_still_win_over_the_workspace_tier(): void
    {
        [$workspace, $project, $inbox] = $this->makeFixture();
        $workspace->update(['allowed_ips' => ['198.51.100.7']]);

        $project->update(['allowed_ips' => ['192.0.2.1']]);
        $this->assertSame(['192.0.2.1'], $inbox->fresh()->effectiveAllowedIps());

        $inbox->update(['allowed_ips' => ['192.0.2.2']]);
        $this->assertSame(['192.0.2.2'], $inbox->fresh()->effectiveAllowedIps());
    }

    public function test_smtp_auth_enforces_the_workspace_allowlist_on_the_wire(): void
    {
        [$workspace, , $inbox] = $this->makeFixture();
        $workspace->update(['allowed_ips' => ['203.0.113.5']]); // test client connects from 127.0.0.1

        $port = $this->bootSmtpServer();

        $transcript = $this->smtpConversation($port, [
            ['expect' => '/^220 /'],
            ['send' => "EHLO test.local\r\n"],
            ['expect' => '/250 HELP\r\n$/'],
            ...$this->smtpAuthLoginSteps($inbox->smtp_username, $inbox->smtp_password),
            ['expect' => '/^535 5\.7\.1 Access denied for your IP address\r\n$/'],
        ]);

        $this->assertStringContainsString('535', end($transcript));
    }

    public function test_the_api_token_path_enforces_the_workspace_allowlist(): void
    {
        [$workspace, , $inbox] = $this->makeFixture();
        $workspace->update(['allowed_ips' => ['203.0.113.5']]);

        $this->getJson('/api/v1/inbox', ['Authorization' => 'Bearer '.$inbox->api_token])
            ->assertForbidden();
    }
}
