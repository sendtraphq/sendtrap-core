<?php

namespace Sendtrap\Core\Tests\Feature;

use Illuminate\Support\Facades\Storage;
use Sendtrap\Core\Models\Message;
use Sendtrap\Core\Support\MessageLinter;
use Sendtrap\Core\Tests\PackageTestCase;

/**
 * Pure(ish) unit-level edge cases for Sendtrap\Core\Support\MessageLinter, built from
 * in-memory (unsaved) Message models rather than through SMTP/API ingestion
 * — complements the ingestion-driven MessageLinterTest, which
 * already covers the "one check per ingested message" happy paths.
 *
 * MessageLinter::lint() unconditionally calls Message::headerLines(), which
 * lazily parses the raw MIME source off disk (Message::raw() ->
 * MessageStorage::disk()->get($raw_path)) — that's the one piece of "app"
 * (filesystem) it can't avoid, so this still uses Storage::fake() and a
 * booted app, but never touches the database (no ->save()).
 *
 * Plan 06 Phase 3b slice 9 (§5.1 bucket (a)): moved from the host's former
 * Tests\Unit\MessageLinterUnitTest, into tests/Feature/ (not tests/Unit/)
 * despite the name — same "needs a booted app" reasoning
 * CompatibilityScorerTest's own docblock documents (Storage::fake()/
 * config() both require the container), so it extends PackageTestCase
 * rather than a bare PHPUnit\Framework\TestCase.
 */
class MessageLinterUnitTest extends PackageTestCase
{
    protected function makeMessage(array $attributes, string $raw): Message
    {
        Storage::fake('local');
        $path = 'messages/unit-'.uniqid().'.eml';
        Storage::disk('local')->put($path, $raw);

        // forceFill() rather than Message::factory()->make(): the factory's
        // 'inbox_id' => Inbox::factory() default is eagerly resolved (and
        // persisted) even under ->make(), which needs a real database. This
        // stays entirely in memory — no DB, no RefreshDatabase.
        return (new Message)->forceFill(array_merge([
            'raw_path' => $path,
            'to' => [],
            'cc' => [],
        ], $attributes));
    }

    protected function checksByKey(array $checks): array
    {
        return collect($checks)->keyBy('key')->all();
    }

    protected function eml(array $headers, string $body = 'body'): string
    {
        $lines = array_map(fn ($k, $v) => "$k: $v", array_keys($headers), $headers);

        return implode("\r\n", array_merge($lines, [
            'MIME-Version: 1.0',
            'Content-Type: text/plain; charset="utf-8"',
            '',
            $body,
            '',
        ]));
    }

    public function test_lint_returns_exactly_four_checks_in_a_stable_order(): void
    {
        $message = $this->makeMessage(
            ['has_text' => true, 'size' => 10, 'from_address' => 'a@example.com'],
            $this->eml(['From' => 'a@example.com', 'To' => 'b@example.com'])
        );

        $checks = MessageLinter::lint($message);

        $this->assertSame(
            ['missing_text_part', 'oversized_html', 'missing_list_unsubscribe', 'from_address_present'],
            array_column($checks, 'key')
        );
        foreach ($checks as $check) {
            $this->assertSame(['key', 'passed', 'severity'], array_keys($check));
        }
    }

    public function test_each_check_reports_its_fixed_severity_regardless_of_pass_fail(): void
    {
        // A message that fails every check.
        $message = $this->makeMessage(
            ['has_text' => false, 'size' => 999999, 'from_address' => 'not-an-email'],
            $this->eml(['From' => 'not-an-email', 'To' => 'b@example.com'])
        );
        config(['sendtrap.lint.max_html_bytes' => 10]);

        $checks = $this->checksByKey(MessageLinter::lint($message));

        $this->assertSame('warn', $checks['missing_text_part']['severity']);
        $this->assertSame('warn', $checks['oversized_html']['severity']);
        $this->assertSame('info', $checks['missing_list_unsubscribe']['severity']);
        $this->assertSame('error', $checks['from_address_present']['severity']);
        $this->assertFalse($checks['missing_text_part']['passed']);
        $this->assertFalse($checks['oversized_html']['passed']);
        $this->assertFalse($checks['missing_list_unsubscribe']['passed']);
        $this->assertFalse($checks['from_address_present']['passed']);
    }

    public function test_oversized_html_is_measured_against_the_message_size_column_not_the_html_body(): void
    {
        // CHARACTERIZATION: despite the check's name, `oversized_html`
        // compares $message->size (the total raw RFC822 byte count set at
        // ingestion) against the configured threshold — not the length of
        // the parsed HTML body. A tiny HTML body inside a large raw message
        // (e.g. large headers/attachments folded into `size`) still fails
        // this check.
        config(['sendtrap.lint.max_html_bytes' => 100]);

        $atThreshold = $this->makeMessage(
            ['has_text' => true, 'size' => 100, 'from_address' => 'a@example.com'],
            $this->eml(['From' => 'a@example.com', 'To' => 'b@example.com'])
        );
        $overThreshold = $this->makeMessage(
            ['has_text' => true, 'size' => 101, 'from_address' => 'a@example.com'],
            $this->eml(['From' => 'a@example.com', 'To' => 'b@example.com'])
        );

        $this->assertTrue($this->checksByKey(MessageLinter::lint($atThreshold))['oversized_html']['passed']);
        $this->assertFalse($this->checksByKey(MessageLinter::lint($overThreshold))['oversized_html']['passed']);
    }

    public function test_missing_list_unsubscribe_header_matching_is_case_insensitive(): void
    {
        $message = $this->makeMessage(
            ['has_text' => true, 'size' => 10, 'from_address' => 'a@example.com'],
            $this->eml([
                'From' => 'a@example.com',
                'To' => 'b@example.com',
                'lIsT-uNsUbScRiBe' => '<mailto:unsub@example.com>',
            ])
        );

        $checks = $this->checksByKey(MessageLinter::lint($message));

        $this->assertTrue($checks['missing_list_unsubscribe']['passed']);
    }

    public function test_from_address_present_rejects_a_syntactically_invalid_address(): void
    {
        $message = $this->makeMessage(
            ['has_text' => true, 'size' => 10, 'from_address' => 'no-at-sign-here'],
            $this->eml(['From' => 'no-at-sign-here', 'To' => 'b@example.com'])
        );

        $this->assertFalse($this->checksByKey(MessageLinter::lint($message))['from_address_present']['passed']);
    }

    public function test_from_address_present_rejects_a_null_from_address(): void
    {
        $message = $this->makeMessage(
            ['has_text' => true, 'size' => 10, 'from_address' => null],
            $this->eml(['To' => 'b@example.com'])
        );

        $this->assertFalse($this->checksByKey(MessageLinter::lint($message))['from_address_present']['passed']);
    }

    public function test_from_address_present_accepts_a_valid_address_with_a_plus_tag(): void
    {
        $message = $this->makeMessage(
            ['has_text' => true, 'size' => 10, 'from_address' => 'a+tag@example.com'],
            $this->eml(['From' => 'a+tag@example.com', 'To' => 'b@example.com'])
        );

        $this->assertTrue($this->checksByKey(MessageLinter::lint($message))['from_address_present']['passed']);
    }

    public function test_missing_text_part_passes_purely_on_the_has_text_flag(): void
    {
        // has_text is a stored flag set at ingestion time (Message::has_text)
        // — the check trusts it rather than re-deriving it from the raw
        // body, so a message flagged has_text=true passes even though this
        // fixture's raw body doesn't actually contain a text/plain part.
        $message = $this->makeMessage(
            ['has_text' => true, 'size' => 10, 'from_address' => 'a@example.com'],
            implode("\r\n", [
                'From: a@example.com',
                'To: b@example.com',
                'MIME-Version: 1.0',
                'Content-Type: text/html; charset="utf-8"',
                '',
                '<p>html only</p>',
                '',
            ])
        );

        $this->assertTrue($this->checksByKey(MessageLinter::lint($message))['missing_text_part']['passed']);
    }
}
