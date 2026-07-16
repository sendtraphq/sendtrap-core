<?php

namespace Sendtrap\Core\Tests\Feature;

use Illuminate\Foundation\Auth\Access\Authorizable;
use Illuminate\Http\Request;
use Sendtrap\Core\Contracts\Workspace as WorkspaceContract;
use Sendtrap\Core\Contracts\WorkspaceAccess;
use Sendtrap\Core\Http\Resources\InboxResource;
use Sendtrap\Core\Models\Inbox;
use Sendtrap\Core\Models\Workspace;
use Sendtrap\Core\Tests\PackageTestCase;

/**
 * Plan 06 Phase 4b slice 0 (§4.7, F1 BLOCKER; §10.9 test mandate): the
 * package-side coverage for InboxResource::canRevealCredentials()'s
 * two-clause gate. Complements (doesn't replace) InboxApiTest — that suite
 * already exercises the token API's happy paths; this file is specifically
 * about *what the resource reveals to whom*.
 */
class InboxResourceCredentialVisibilityTest extends PackageTestCase
{
    protected function makeInbox(): Inbox
    {
        $workspace = Workspace::factory()->create();
        $project = $workspace->projects()->create(['name' => 'P']);

        return $project->inboxes()->create([
            'name' => 'Inbox',
            'auto_forward_to' => 'ops@example.org',
            'webhook_url' => 'https://hooks.example.test/in?token=super-secret',
        ]);
    }

    /**
     * A minimal Authorizable stand-in — package tests have no host User
     * model, but `canRevealCredentials()`'s clause (a) calls
     * `$request->user()?->can(...)`, which requires the Authorizable trait
     * (the same trait every real host User model gets via
     * Illuminate\Foundation\Auth\User). WorkspaceAccess fakes don't care
     * about the user's identity, only which fake is currently bound.
     */
    protected function makeUser(): object
    {
        return new class
        {
            use Authorizable;
        };
    }

    protected function denyManage(): void
    {
        $this->app->instance(WorkspaceAccess::class, new class implements WorkspaceAccess
        {
            public function canView(object $user, WorkspaceContract $workspace): bool
            {
                return true;
            }

            public function canManage(object $user, WorkspaceContract $workspace): bool
            {
                return false;
            }
        });
    }

    public function test_the_token_api_still_returns_credentials(): void
    {
        // Clause (b), Cloud-parity guard: Api\InboxController::show() runs
        // under AuthenticateInboxToken, so $request->user() is null and the
        // 'inbox' request attribute is this exact inbox — clause (a) alone
        // would strip these fields from the API's own inbox-details
        // response, a Cloud regression too (§4.7).
        $inbox = $this->makeInbox();

        $response = $this->withToken($inbox->api_token)
            ->getJson('/api/v1/inbox')
            ->assertOk();

        $response->assertJsonPath('data.smtp_username', $inbox->smtp_username);
        $response->assertJsonPath('data.smtp_password', $inbox->smtp_password);
        $response->assertJsonPath('data.api_token', $inbox->api_token);
        $response->assertJsonPath('data.auto_forward_to', 'ops@example.org');
        $response->assertJsonPath('data.webhook_url', 'https://hooks.example.test/in?token=super-secret');
    }

    public function test_a_web_manager_still_sees_credentials(): void
    {
        // PackageTestCase's default WorkspaceAccess binding
        // (AllowAllWorkspaceAccess) is single-access-level — Cloud-shaped
        // (canView === canManage) — proving the gate is a no-op there.
        $inbox = $this->makeInbox();
        $user = $this->makeUser();

        $request = Request::create('/');
        $request->setUserResolver(fn () => $user);

        $data = (new InboxResource($inbox))->resolve($request);

        $this->assertSame($inbox->smtp_username, $data['smtp_username']);
        $this->assertSame($inbox->smtp_password, $data['smtp_password']);
        $this->assertSame($inbox->api_token, $data['api_token']);
        $this->assertSame('ops@example.org', $data['auto_forward_to']);
        $this->assertSame('https://hooks.example.test/in?token=super-secret', $data['webhook_url']);
    }

    public function test_a_web_viewer_without_manage_rights_sees_none_of_the_credentials(): void
    {
        $inbox = $this->makeInbox();
        $user = $this->makeUser();
        $this->denyManage();

        $request = Request::create('/');
        $request->setUserResolver(fn () => $user);

        $data = (new InboxResource($inbox))->resolve($request);

        $this->assertArrayNotHasKey('smtp_username', $data);
        $this->assertArrayNotHasKey('smtp_password', $data);
        $this->assertArrayNotHasKey('api_token', $data);
        $this->assertArrayNotHasKey('auto_forward_to', $data);
        $this->assertArrayNotHasKey('webhook_url', $data);

        // Non-credential fields are unaffected by the gate.
        $this->assertSame($inbox->id, $data['id']);
        $this->assertSame($inbox->name, $data['name']);

        // The raw response body (as if this had gone out over HTTP) must
        // not contain the bearer-usable token string either.
        $this->assertStringNotContainsString($inbox->api_token, json_encode($data));
    }

    public function test_a_web_viewer_without_manage_rights_sees_no_active_share_url(): void
    {
        $inbox = $this->makeInbox();
        $inbox->shares()->create(['expires_at' => now()->addDays(30)]);
        $inbox->load('shares');

        $user = $this->makeUser();
        $this->denyManage();

        $request = Request::create('/');
        $request->setUserResolver(fn () => $user);

        $data = (new InboxResource($inbox))->resolve($request);

        $this->assertArrayNotHasKey('share', $data);
    }

    public function test_a_manager_still_sees_the_active_share_url(): void
    {
        $inbox = $this->makeInbox();
        $inbox->shares()->create(['expires_at' => now()->addDays(30)]);
        $inbox->load('shares');

        $user = $this->makeUser();

        $request = Request::create('/');
        $request->setUserResolver(fn () => $user);

        $data = (new InboxResource($inbox))->resolve($request);

        $this->assertArrayHasKey('share', $data);
        $this->assertArrayHasKey('url', $data['share']);
    }
}
