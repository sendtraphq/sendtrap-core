<?php

namespace Sendtrap\Core\Tests\Feature;

use Illuminate\Support\Facades\Storage;
use Sendtrap\Core\Jobs\ProcessIncomingMessage;
use Sendtrap\Core\Models\Inbox;
use Sendtrap\Core\Models\Workspace;
use Sendtrap\Core\Support\MessageLinter;
use Sendtrap\Core\Tests\PackageTestCase;

/**
 * Plan 06 Phase 3b slice 9 (§5.1 bucket (b)): moved from the host's former
 * Tests\Feature\MessageLinterTest — the only rework is the fixture:
 * `User::factory()->withPersonalTeam()->create()` + `$team->projects()`
 * becomes `Workspace::factory()->create()` + `$workspace->projects()`.
 */
class MessageLinterTest extends PackageTestCase
{
    protected function makeInbox(): Inbox
    {
        $workspace = Workspace::factory()->create();
        $project = $workspace->projects()->create(['name' => 'P']);

        return $project->inboxes()->create(['name' => 'Inbox']);
    }

    protected function eml(array $headers, string $body): string
    {
        $lines = array_map(fn ($k, $v) => "$k: $v", array_keys($headers), $headers);

        return implode("\r\n", array_merge($lines, [
            'MIME-Version: 1.0',
            'Content-Type: text/html; charset="utf-8"',
            '',
            $body,
            '',
        ]));
    }

    protected function checksByKey(array $checks): array
    {
        return collect($checks)->keyBy('key')->all();
    }

    public function test_it_flags_a_missing_plain_text_part(): void
    {
        Storage::fake('local');
        $inbox = $this->makeInbox();

        ProcessIncomingMessage::dispatchSync($inbox->id, $this->eml([
            'From' => 'a@example.com',
            'To' => 'b@example.com',
            'Subject' => 'Hi',
        ], '<p>html only</p>'));

        $checks = $this->checksByKey(MessageLinter::lint($inbox->messages()->first()));

        $this->assertFalse($checks['missing_text_part']['passed']);
    }

    public function test_it_flags_a_missing_list_unsubscribe_header(): void
    {
        Storage::fake('local');
        $inbox = $this->makeInbox();

        ProcessIncomingMessage::dispatchSync($inbox->id, $this->eml([
            'From' => 'a@example.com',
            'To' => 'b@example.com',
            'Subject' => 'Hi',
        ], '<p>no unsubscribe header</p>'));

        $checks = $this->checksByKey(MessageLinter::lint($inbox->messages()->first()));

        $this->assertFalse($checks['missing_list_unsubscribe']['passed']);
    }

    public function test_it_passes_the_list_unsubscribe_check_when_present(): void
    {
        Storage::fake('local');
        $inbox = $this->makeInbox();

        ProcessIncomingMessage::dispatchSync($inbox->id, $this->eml([
            'From' => 'a@example.com',
            'To' => 'b@example.com',
            'Subject' => 'Hi',
            'List-Unsubscribe' => '<mailto:unsub@example.com>',
        ], '<p>has unsubscribe header</p>'));

        $checks = $this->checksByKey(MessageLinter::lint($inbox->messages()->first()));

        $this->assertTrue($checks['missing_list_unsubscribe']['passed']);
    }

    public function test_it_flags_an_invalid_from_address(): void
    {
        Storage::fake('local');
        $inbox = $this->makeInbox();

        ProcessIncomingMessage::dispatchSync($inbox->id, $this->eml([
            'From' => 'not-an-email',
            'To' => 'b@example.com',
            'Subject' => 'Hi',
        ], '<p>bad from</p>'));

        $checks = $this->checksByKey(MessageLinter::lint($inbox->messages()->first()));

        $this->assertFalse($checks['from_address_present']['passed']);
    }

    public function test_it_flags_oversized_html_against_the_configured_threshold(): void
    {
        config(['sendtrap.lint.max_html_bytes' => 10]);
        Storage::fake('local');
        $inbox = $this->makeInbox();

        ProcessIncomingMessage::dispatchSync($inbox->id, $this->eml([
            'From' => 'a@example.com',
            'To' => 'b@example.com',
            'Subject' => 'Hi',
        ], '<p>this body is well over ten bytes</p>'));

        $checks = $this->checksByKey(MessageLinter::lint($inbox->messages()->first()));

        $this->assertFalse($checks['oversized_html']['passed']);
    }
}
