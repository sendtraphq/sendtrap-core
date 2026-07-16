<?php

namespace Sendtrap\Core\Tests\Feature;

use Sendtrap\Core\Models\Message;
use Sendtrap\Core\Models\Workspace;
use Sendtrap\Core\Support\HtmlCompatibility\CaniemailDataset;
use Sendtrap\Core\Tests\PackageTestCase;

/**
 * Plan 06 Phase 3b slice 9 (§5.1 bucket (c), M-1 "package tests pass
 * independently... new independently-authored test, not a diminished
 * port"): covers Message::resolveHtmlCheck()'s caching/staleness behavior
 * directly at the model level — the one piece of the host's
 * MessageHtmlCheckTest genuinely testing core domain logic rather than the
 * `messages.htmlcheck` HTTP route itself (which stays host-only, H-5 — see
 * the host file's own docblock). The compatibility-ratio/issue-shape
 * assertions live in CompatibilityScorerTest already.
 */
class MessageHtmlCheckResolutionTest extends PackageTestCase
{
    protected function makeMessage(): Message
    {
        $workspace = Workspace::factory()->create();
        $project = $workspace->projects()->create(['name' => 'P']);
        $inbox = $project->inboxes()->create(['name' => 'Inbox']);

        return Message::factory()->for($inbox)->create();
    }

    public function test_it_computes_and_caches_the_result_on_first_resolution(): void
    {
        $message = $this->makeMessage();

        $htmlCheck = $message->resolveHtmlCheck();

        $this->assertSame(CaniemailDataset::version(), $htmlCheck->data_version);
        $this->assertNotNull($htmlCheck->checked_at);
    }

    public function test_it_does_not_recompute_on_a_second_resolution(): void
    {
        $message = $this->makeMessage();

        $first = $message->resolveHtmlCheck();
        $firstCheckedAt = $first->checked_at;

        $second = $message->fresh()->resolveHtmlCheck();

        $this->assertTrue($firstCheckedAt->equalTo($second->checked_at));
    }

    public function test_it_recomputes_when_the_dataset_version_changes(): void
    {
        $message = $this->makeMessage();
        $message->resolveHtmlCheck();
        $message->refresh()->htmlCheck()->update(['data_version' => 'stale-version']);

        $recomputed = $message->fresh()->resolveHtmlCheck();

        $this->assertSame(CaniemailDataset::version(), $recomputed->data_version);
    }
}
