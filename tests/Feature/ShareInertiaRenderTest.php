<?php

namespace Sendtrap\Core\Tests\Feature;

use Inertia\Support\Header as InertiaHeader;
use Sendtrap\Core\Models\Inbox;
use Sendtrap\Core\Models\Message;
use Sendtrap\Core\Models\Workspace;
use Sendtrap\Core\Tests\PackageTestCase;

/**
 * Regression coverage for a once-undeclared dependency:
 * `Sendtrap\Core\Http\Controllers\
 * ShareController::show()`/`Sendtrap\Core\Http\Controllers\
 * InboxShareController::show()` (src/Http/Controllers/ShareController.php:
 * 20, src/Http/Controllers/InboxShareController.php:27) have always
 * directly `use`d `Inertia\Inertia` — real, load-bearing code, not a
 * docblock reference — but nothing in composer.json declared it as a
 * dependency, so the package's own vendor/ tree had no inertiajs/
 * inertia-laravel until the dependency was declared explicitly
 * (`composer require inertiajs/inertia-laravel`,
 * pinned to `^2.0`). No package test hit either `show()`
 * action before this file — the only existing package-side share coverage
 * (InboxShareConsumptionTest) exercises the JSON `messages`/`message`
 * actions only, which never touch Inertia::render() — so the gap went
 * uncaught by the package's own standalone suite even though it would have
 * fataled the moment either action actually ran.
 *
 * Fixing this finding also surfaced a second, harness-level gap: Orchestra
 * Testbench's `$enablesPackageDiscoveries` defaults to false
 * (Sendtrap\Core\Tests\PackageTestCase's own `getPackageProviders()`
 * docblock has the full story), so the package's Testbench harness never
 * auto-registered ANY dependency's service provider — Inertia's own
 * `Inertia\ServiceProvider` had to be added there explicitly, or
 * `Inertia::render()` would silently "work" (the container auto-resolves
 * the unregistered concrete `ResponseFactory` class on demand) while
 * quietly never wiring the config/middleware/testing-macros that provider
 * is responsible for.
 *
 * This file makes the dependency load-bearing in the standalone suite: it
 * GETs both `share.show` and `share.inbox.show` with the `X-Inertia: true`
 * request header (exactly what the real Inertia.js client sends on every
 * client-side visit) and asserts a genuine Inertia response — status,
 * the `X-Inertia` response header Inertia's own `Response::toResponse()`
 * only sets on this code path (`vendor/inertiajs/
 * inertia-laravel/src/Response.php:216`), the component name, and
 * representative props. Response content is asserted directly (JSON body)
 * rather than via Inertia's `assertInertia()` testing macro: that macro's
 * `AssertableInertia::fromTestResponse()` only reads the *full-page* HTML
 * variant (`$response->assertViewHas('page')`,
 * `vendor/inertiajs/inertia-laravel/src/Testing/AssertableInertia.php:73`),
 * which requires a host's own root Blade view — the package's Testbench
 * skeleton ships none, deliberately (a host application concern, not a
 * package one) — while the `X-Inertia` JSON variant this file drives
 * carries the identical `component`/`props`/`url`/`version` payload
 * directly as the response body (`Response.php:198-216`).
 */
class ShareInertiaRenderTest extends PackageTestCase
{
    protected function makeInbox(): Inbox
    {
        $workspace = Workspace::factory()->create();
        $project = $workspace->projects()->create(['name' => 'P']);

        return $project->inboxes()->create(['name' => 'Inbox']);
    }

    public function test_share_show_renders_the_inertia_message_page(): void
    {
        $inbox = $this->makeInbox();
        $message = Message::factory()->for($inbox)->create(['subject' => 'Rendered via Inertia']);
        $share = $message->shares()->create();

        $this->get(route('share.show', $share->token), [InertiaHeader::INERTIA => 'true'])
            ->assertOk()
            ->assertHeader(InertiaHeader::INERTIA, 'true')
            ->assertJsonPath('component', 'Share/Show')
            ->assertJsonPath('props.token', $share->token)
            ->assertJsonPath('props.message.subject', 'Rendered via Inertia');
    }

    public function test_share_inbox_show_renders_the_inertia_inbox_page(): void
    {
        $inbox = $this->makeInbox();
        Message::factory()->count(2)->for($inbox)->create();
        $share = $inbox->shares()->create(['expires_at' => now()->addDays(7)]);

        $this->get(route('share.inbox.show', $share->token), [InertiaHeader::INERTIA => 'true'])
            ->assertOk()
            ->assertHeader(InertiaHeader::INERTIA, 'true')
            ->assertJsonPath('component', 'Share/InboxShow')
            ->assertJsonPath('props.token', $share->token)
            ->assertJsonPath('props.inbox.id', $inbox->id)
            ->assertJsonCount(2, 'props.messages.data');
    }
}
