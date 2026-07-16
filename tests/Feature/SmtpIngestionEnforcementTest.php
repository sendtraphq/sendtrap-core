<?php

namespace Sendtrap\Core\Tests\Feature;

use Illuminate\Support\Facades\Storage;
use Sendtrap\Core\Contracts\Entitlements;
use Sendtrap\Core\Contracts\UsageMeter;
use Sendtrap\Core\Models\Inbox;
use Sendtrap\Core\Models\Message;
use Sendtrap\Core\Models\Workspace;
use Sendtrap\Core\Testing\Concerns\InteractsWithSmtpServer;
use Sendtrap\Core\Tests\Fakes\FakeEntitlements;
use Sendtrap\Core\Tests\Fakes\FakeUsageMeter;
use Sendtrap\Core\Tests\Fakes\FakeWorkspaceEntitlements;
use Sendtrap\Core\Tests\PackageTestCase;

/**
 * The package-side equivalent of a host-level SMTP ingestion suite — "a
 * new, independently-authored test covering the same behavior, not a
 * diminished port" — authored fresh against the package's own
 * fixtures and fakes rather than a port of a host file's exact assertions.
 *
 * Fixture: Workspace::factory()->create() -> $workspace->projects()->create()
 * -> $project->inboxes()->create() — no Team, no User, no billing config, the
 * same pattern WorkspaceAllowedIpsTest/SmtpStartTlsTest already use
 * package-side. The plan-cap/rate/quota decisions are driven by binding
 * FakeEntitlements/FakeUsageMeter directly (Sendtrap\Core\Console\Commands\
 * SmtpServer::handleDataLine() reads app(Entitlements::class)->for($workspace)
 * ->emailSizeBytes() and app(UsageMeter::class)->checkSend($workspace) at
 * DATA time, src/Console/Commands/SmtpServer.php:592,
 * 600) rather than by mutating a billing config tree the package doesn't
 * have.
 *
 * Mirrors, case-for-case: SmtpServerIngestionTest::
 * test_it_completes_a_full_smtp_conversation_and_stores_the_message,
 * test_it_rejects_mail_commands_before_authentication,
 * test_it_rejects_invalid_credentials,
 * test_it_drops_the_connection_after_too_many_authentication_failures,
 * test_it_enforces_the_daemon_size_limit,
 * test_it_enforces_the_plan_email_size_cap,
 * test_smtp_rejects_a_message_when_the_rate_limit_is_exceeded,
 * test_smtp_rejects_a_message_over_the_monthly_quota.
 */
class SmtpIngestionEnforcementTest extends PackageTestCase
{
    use InteractsWithSmtpServer;

    protected function makeInbox(): Inbox
    {
        $workspace = Workspace::factory()->create();
        $project = $workspace->projects()->create(['name' => 'P']);

        return $project->inboxes()->create(['name' => 'Inbox']);
    }

    protected function sampleRawMessage(): string
    {
        return implode("\r\n", [
            'From: Alice Sender <alice@example.com>',
            'To: Bob <bob@example.com>',
            'Subject: Hello from the wire',
            'Message-ID: <wire-1@example.com>',
            'MIME-Version: 1.0',
            'Content-Type: text/html; charset="utf-8"',
            '',
            '<html><body><h1>Hi</h1></body></html>',
            '',
        ]);
    }

    protected function dataStep(string $raw): array
    {
        return ['send' => $raw."\r\n.\r\n"];
    }

    /**
     * @return array<int, array{send?: string, expect?: string}>
     */
    protected function authSteps(Inbox $inbox, ?string $password = null): array
    {
        return $this->smtpAuthLoginSteps($inbox->smtp_username, $password ?? $inbox->smtp_password);
    }

    public function test_it_completes_a_full_smtp_conversation_and_stores_the_message(): void
    {
        Storage::fake('local');
        $inbox = $this->makeInbox();
        $port = $this->bootSmtpServer();

        $transcript = $this->smtpConversation($port, [
            ['expect' => '/^220 /'],
            ['send' => "EHLO test.local\r\n"],
            ['expect' => '/250 HELP\r\n$/'],
            ...$this->authSteps($inbox),
            ['expect' => '/^235 /'],
            ['send' => "MAIL FROM:<alice@example.com>\r\n"],
            ['expect' => '/^250 2\.1\.0 OK\r\n$/'],
            ['send' => "RCPT TO:<bob@example.com>\r\n"],
            ['expect' => '/^250 2\.1\.5 OK\r\n$/'],
            ['send' => "DATA\r\n"],
            ['expect' => '/^354 /'],
            $this->dataStep($this->sampleRawMessage()),
            ['expect' => '/^250 2\.0\.0 Message accepted\r\n$/'],
            ['send' => "QUIT\r\n"],
            ['expect' => '/^221 /'],
        ]);

        $this->assertNotEmpty($transcript);

        $message = Message::where('inbox_id', $inbox->id)->first();
        $this->assertNotNull($message);
        $this->assertSame('Hello from the wire', $message->subject);
        $this->assertSame('alice@example.com', $message->from_address);
        $this->assertSame('bob@example.com', $message->to[0]['address']);
    }

    public function test_it_rejects_mail_commands_before_authentication(): void
    {
        $inbox = $this->makeInbox();
        $port = $this->bootSmtpServer();

        $this->smtpConversation($port, [
            ['expect' => '/^220 /'],
            ['send' => "EHLO test.local\r\n"],
            ['expect' => '/250 HELP\r\n$/'],
            ['send' => "MAIL FROM:<alice@example.com>\r\n"],
            ['expect' => '/^530 5\.7\.0 Authentication required\r\n$/'],
        ]);

        $this->assertSame(0, Message::where('inbox_id', $inbox->id)->count());
    }

    public function test_it_rejects_invalid_credentials(): void
    {
        $inbox = $this->makeInbox();
        $port = $this->bootSmtpServer();

        $transcript = $this->smtpConversation($port, [
            ['expect' => '/^220 /'],
            ['send' => "EHLO test.local\r\n"],
            ['expect' => '/250 HELP\r\n$/'],
            ...$this->authSteps($inbox, 'totally-wrong-password'),
            ['expect' => '/^535 5\.7\.8 Authentication credentials invalid\r\n$/'],
        ], timeoutSeconds: 5);

        $this->assertStringContainsString('535', end($transcript));
    }

    public function test_it_drops_the_connection_after_too_many_authentication_failures(): void
    {
        config(['sendtrap.max_auth_attempts' => 1]);
        $inbox = $this->makeInbox();
        $port = $this->bootSmtpServer();

        $transcript = $this->smtpConversation($port, [
            ['expect' => '/^220 /'],
            ['send' => "EHLO test.local\r\n"],
            ['expect' => '/250 HELP\r\n$/'],
            ...$this->authSteps($inbox, 'still-wrong'),
            ['expect' => '/^421 4\.7\.0 Too many authentication failures\r\n$/'],
        ], timeoutSeconds: 5);

        $this->assertStringContainsString('421', end($transcript));
    }

    public function test_it_enforces_the_daemon_size_limit(): void
    {
        config(['sendtrap.max_size' => 50]);
        Storage::fake('local');
        $inbox = $this->makeInbox();
        $port = $this->bootSmtpServer();

        $this->smtpConversation($port, [
            ['expect' => '/^220 /'],
            ['send' => "EHLO test.local\r\n"],
            ['expect' => '/250 HELP\r\n$/'],
            ...$this->authSteps($inbox),
            ['expect' => '/^235 /'],
            ['send' => "MAIL FROM:<alice@example.com>\r\n"],
            ['expect' => '/^250 2\.1\.0 OK\r\n$/'],
            ['send' => "RCPT TO:<bob@example.com>\r\n"],
            ['expect' => '/^250 2\.1\.5 OK\r\n$/'],
            ['send' => "DATA\r\n"],
            ['expect' => '/^354 /'],
            $this->dataStep($this->sampleRawMessage()),
            ['expect' => '/^552 5\.3\.4 Message exceeds maximum size\r\n$/'],
        ]);

        $this->assertSame(0, Message::where('inbox_id', $inbox->id)->count());
    }

    public function test_it_enforces_the_plan_email_size_cap_via_fake_entitlements(): void
    {
        $this->app->instance(Entitlements::class, new FakeEntitlements(
            new FakeWorkspaceEntitlements(emailSizeBytes: 50),
        ));
        Storage::fake('local');
        $inbox = $this->makeInbox();
        $port = $this->bootSmtpServer();

        $this->smtpConversation($port, [
            ['expect' => '/^220 /'],
            ['send' => "EHLO test.local\r\n"],
            ['expect' => '/250 HELP\r\n$/'],
            ...$this->authSteps($inbox),
            ['expect' => '/^235 /'],
            ['send' => "MAIL FROM:<alice@example.com>\r\n"],
            ['expect' => '/^250 2\.1\.0 OK\r\n$/'],
            ['send' => "RCPT TO:<bob@example.com>\r\n"],
            ['expect' => '/^250 2\.1\.5 OK\r\n$/'],
            ['send' => "DATA\r\n"],
            ['expect' => '/^354 /'],
            $this->dataStep($this->sampleRawMessage()),
            ['expect' => '/^552 5\.3\.4 Message exceeds your plan/'],
        ]);

        $this->assertSame(0, Message::where('inbox_id', $inbox->id)->count());
    }

    public function test_smtp_rejects_a_message_when_the_rate_decision_fires_via_fake_usage_meter(): void
    {
        $this->app->instance(UsageMeter::class, new FakeUsageMeter(checkSend: 'rate'));
        Storage::fake('local');
        $inbox = $this->makeInbox();
        $port = $this->bootSmtpServer();

        $this->smtpConversation($port, [
            ['expect' => '/^220 /'],
            ['send' => "EHLO test.local\r\n"],
            ['expect' => '/250 HELP\r\n$/'],
            ...$this->authSteps($inbox),
            ['expect' => '/^235 /'],
            ['send' => "MAIL FROM:<alice@example.com>\r\n"],
            ['expect' => '/^250 2\.1\.0 OK\r\n$/'],
            ['send' => "RCPT TO:<bob@example.com>\r\n"],
            ['expect' => '/^250 2\.1\.5 OK\r\n$/'],
            ['send' => "DATA\r\n"],
            ['expect' => '/^354 /'],
            $this->dataStep($this->sampleRawMessage()),
            ['expect' => '/^452 4\.2\.1 Rate limit exceeded, please slow down\r\n$/'],
        ]);

        $this->assertSame(0, Message::where('inbox_id', $inbox->id)->count());
    }

    public function test_smtp_rejects_a_message_when_the_quota_decision_fires_via_fake_usage_meter(): void
    {
        $this->app->instance(UsageMeter::class, new FakeUsageMeter(checkSend: 'quota'));
        Storage::fake('local');
        $inbox = $this->makeInbox();
        $port = $this->bootSmtpServer();

        $this->smtpConversation($port, [
            ['expect' => '/^220 /'],
            ['send' => "EHLO test.local\r\n"],
            ['expect' => '/250 HELP\r\n$/'],
            ...$this->authSteps($inbox),
            ['expect' => '/^235 /'],
            ['send' => "MAIL FROM:<alice@example.com>\r\n"],
            ['expect' => '/^250 2\.1\.0 OK\r\n$/'],
            ['send' => "RCPT TO:<bob@example.com>\r\n"],
            ['expect' => '/^250 2\.1\.5 OK\r\n$/'],
            ['send' => "DATA\r\n"],
            ['expect' => '/^354 /'],
            $this->dataStep($this->sampleRawMessage()),
            ['expect' => '/^550 5\.7\.0 Monthly sending quota exceeded\r\n$/'],
        ]);

        $this->assertSame(0, Message::where('inbox_id', $inbox->id)->count());
    }

    public function test_a_send_within_limits_is_recorded_on_the_usage_meter(): void
    {
        $meter = new FakeUsageMeter;
        $this->app->instance(UsageMeter::class, $meter);
        Storage::fake('local');
        $inbox = $this->makeInbox();
        $port = $this->bootSmtpServer();

        $this->smtpConversation($port, [
            ['expect' => '/^220 /'],
            ['send' => "EHLO test.local\r\n"],
            ['expect' => '/250 HELP\r\n$/'],
            ...$this->authSteps($inbox),
            ['expect' => '/^235 /'],
            ['send' => "MAIL FROM:<alice@example.com>\r\n"],
            ['expect' => '/^250 2\.1\.0 OK\r\n$/'],
            ['send' => "RCPT TO:<bob@example.com>\r\n"],
            ['expect' => '/^250 2\.1\.5 OK\r\n$/'],
            ['send' => "DATA\r\n"],
            ['expect' => '/^354 /'],
            $this->dataStep($this->sampleRawMessage()),
            ['expect' => '/^250 2\.0\.0 Message accepted\r\n$/'],
        ]);

        $this->assertSame(1, $meter->sendsRecorded);
    }
}
