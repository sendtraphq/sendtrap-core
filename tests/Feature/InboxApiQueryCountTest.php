<?php

namespace Sendtrap\Core\Tests\Feature;

use Illuminate\Support\Facades\DB;
use Sendtrap\Core\Models\Inbox;
use Sendtrap\Core\Models\Project;
use Sendtrap\Core\Tests\PackageTestCase;

/**
 * Regression sentinel for the API hot path: the `inbox-api` rate
 * limiter must reuse the inbox (and its eager-loaded project.workspace)
 * that AuthenticateInboxToken::resolve() already fetched, not re-query it
 * via WorkspaceContext::forInboxId(). The limiter closure runs on every
 * token-authenticated API request, so an extra inbox/project/workspace
 * lookup there multiplies across the product's programmatic/CI hot path.
 *
 * Moved to the package in Plan 06 Phase 3b slice 7 (§5.1 bucket (a)).
 * Plan 06 Phase 3 gate finding #1 removed AuthenticateInboxToken::resolve()'s
 * `project.team` eager load and team-priming block entirely — core has no
 * Team concept at all (§1.2: the package's own `projects` table doesn't even
 * carry a team_id column, §7.3), and the query-neutral replacement (a host's
 * own lazy `$workspace->team` load, if it needs one) lives entirely outside
 * core. This test exercises the two-resolve()-calls budget against a plain
 * workspace-rooted Project. The "teams" assertion stays as a sentinel that
 * core never queries a table it has no concept of; the "workspaces"
 * assertion guards resolve()'s own eager load.
 */
class InboxApiQueryCountTest extends PackageTestCase
{
    public function test_the_rate_limiter_does_not_requery_the_inbox_chain(): void
    {
        $inbox = Inbox::factory()->for(Project::factory())->create();

        $queries = [];
        DB::listen(function ($query) use (&$queries) {
            $queries[] = $query->sql;
        });

        $this->getJson('/api/v1/inbox', [
            'Authorization' => 'Bearer '.$inbox->api_token,
        ])->assertOk();

        $matching = fn (string $needle): int => count(array_filter(
            $queries,
            fn (string $sql): bool => str_contains($sql, $needle)
        ));

        // The inbox is resolved by token exactly twice per request — once by
        // the throttle middleware's limiter closure
        // (AuthenticateInboxToken::resolve) and once by the
        // AuthenticateInboxToken middleware itself, which runs after
        // ThrottleRequests in Laravel's fixed middleware priority order. A
        // third resolution means someone reintroduced a redundant lookup
        // (e.g. WorkspaceContext::forInboxId()) inside the limiter. The
        // controller's own loadCount('messages') select is excluded by
        // matching on the token lookup, not the table name.
        $this->assertSame(2, $matching('where "api_token"'), 'inbox resolved by token more than the two expected resolve() calls');
        $this->assertLessThanOrEqual(2, $matching('from "projects"'), 'projects re-queried outside resolve() eager loads');
        // Core has no Team concept and no "teams" table (§1.2/§7.3) —
        // resolve() no longer eager-loads or primes anything team-shaped, so
        // a package Testbench request should never query "teams" at all.
        $this->assertSame(0, $matching('from "teams"'), 'a package Testbench request should never query a non-existent "teams" table');
        // Plan 06 Phase 2 (§5.1 items 4/8): resolve() also eager-loads
        // project.workspace for effectiveAllowedIps() and the limiter's
        // Entitlements resolution — one workspaces select per resolve(),
        // never a per-request lazy load on top.
        $this->assertLessThanOrEqual(2, $matching('from "workspaces"'), 'workspaces re-queried outside resolve() eager loads');
    }
}
