<?php

namespace Sendtrap\Core\Tests\Feature;

use Illuminate\Support\Facades\Storage;
use React\EventLoop\Loop;
use React\Socket\Connection;
use React\Socket\ConnectionInterface;
use React\Socket\Connector;
use React\Socket\StreamEncryption;
use RuntimeException;
use Sendtrap\Core\Models\Inbox;
use Sendtrap\Core\Models\Message;
use Sendtrap\Core\Models\Workspace;
use Sendtrap\Core\Testing\Concerns\InteractsWithSmtpServer;
use Sendtrap\Core\Tests\PackageTestCase;
use Throwable;

/**
 * Completes the full STARTTLS handshake left uncovered by
 * SmtpServerIngestionTest::test_it_advertises_starttls_and_accepts_the_command()
 * (which only asserts the 220 response to STARTTLS and the pre-upgrade
 * EHLO advertisement).
 *
 * This drives a hand-rolled conversation (rather than
 * InteractsWithSmtpServer::smtpConversation(), which only writes/reads a
 * plaintext byte stream) because completing STARTTLS requires the *client*
 * side to also perform a mid-connection TLS upgrade on the same socket, in
 * the same process, on the same ReactPHP loop as the server under test. It
 * reuses React\Socket\StreamEncryption — the exact class
 * Sendtrap\Core\Console\Commands\SmtpServer::handleStartTls() uses for the
 * server side — constructed with $server=false for the client side, and
 * disables peer verification so it accepts the server's auto-generated
 * self-signed cert.
 *
 * Plan 06 Phase 3b slice 9 (§5.1 bucket (b)): moved from the host's former
 * Tests\Feature\SmtpStartTlsTest — fixture rework only.
 */
class SmtpStartTlsTest extends PackageTestCase
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
            'Subject: Hello over TLS',
            'Message-ID: <tls-1@example.com>',
            'MIME-Version: 1.0',
            'Content-Type: text/plain; charset="utf-8"',
            '',
            'Encrypted body.',
            '',
        ]);
    }

    /**
     * Like InteractsWithSmtpServer::smtpConversation(), but a step may also
     * be ['upgrade' => true] to perform a client-side TLS upgrade on the
     * live connection before continuing the script.
     *
     * @param  array<int, array{send?: string, expect?: string, upgrade?: bool}>  $steps
     * @return array<int, string>
     */
    protected function tlsSmtpConversation(int $port, array $steps, int $timeoutSeconds = 5): array
    {
        $transcript = [];
        $buffer = '';
        $done = false;
        $failure = null;
        $hardStop = null;

        $finish = function () use (&$done, &$hardStop) {
            if ($done) {
                return;
            }
            $done = true;
            if ($hardStop) {
                Loop::get()->cancelTimer($hardStop);
            }
            Loop::get()->stop();
        };

        $hardStop = Loop::get()->addTimer($timeoutSeconds, function () use (&$failure, $finish) {
            $failure ??= 'TLS SMTP conversation timed out waiting for a response';
            $finish();
        });

        (new Connector)->connect("127.0.0.1:{$port}")->then(
            function (ConnectionInterface $conn) use (&$buffer, &$transcript, &$failure, $steps, $finish) {
                $conn->on('data', function ($chunk) use (&$buffer) {
                    $buffer .= $chunk;
                });
                $conn->on('error', function (Throwable $e) use (&$failure, $finish) {
                    $failure ??= 'Connection error: '.$e->getMessage();
                    $finish();
                });

                $index = 0;
                $count = count($steps);
                $advance = null;
                $advance = function () use (
                    &$advance, &$index, &$buffer, &$transcript, $steps, $count, $conn, $finish, &$failure
                ) {
                    if ($index >= $count) {
                        $finish();

                        return;
                    }

                    $step = $steps[$index];

                    if (isset($step['send'])) {
                        $conn->write($step['send']);
                        $transcript[] = '> '.trim($step['send']);
                        $index++;
                        Loop::get()->futureTick($advance);

                        return;
                    }

                    if (! empty($step['upgrade'])) {
                        if (! $conn instanceof Connection) {
                            $failure = 'Cannot upgrade a non-Connection instance to TLS';
                            $finish();

                            return;
                        }

                        // Accept the server's auto-generated self-signed cert.
                        stream_context_set_option($conn->stream, ['ssl' => [
                            'verify_peer' => false,
                            'verify_peer_name' => false,
                            'allow_self_signed' => true,
                        ]]);

                        (new StreamEncryption(Loop::get(), false))->enable($conn)->then(
                            function () use (&$advance, &$index, &$transcript) {
                                $transcript[] = '* TLS handshake completed';
                                $index++;
                                Loop::get()->futureTick($advance);
                            },
                            function (Throwable $e) use (&$failure, $finish) {
                                $failure = 'TLS handshake failed: '.$e->getMessage();
                                $finish();
                            }
                        );

                        return;
                    }

                    if (isset($step['expect'])) {
                        if (preg_match($step['expect'], $buffer) === 1) {
                            $transcript[] = '< '.trim($buffer);
                            $buffer = '';
                            $index++;
                            Loop::get()->futureTick($advance);

                            return;
                        }

                        Loop::get()->addTimer(0.01, $advance);

                        return;
                    }
                };

                $advance();
            },
            function (Throwable $e) use (&$failure, $finish) {
                $failure = 'Could not connect: '.$e->getMessage();
                $finish();
            }
        );

        Loop::get()->run();

        if ($failure) {
            throw new RuntimeException($failure.' — transcript so far: '.implode(' | ', $transcript));
        }

        return $transcript;
    }

    public function test_it_completes_a_full_starttls_handshake_and_sends_a_message_over_the_encrypted_channel(): void
    {
        Storage::fake('local');
        $inbox = $this->makeInbox();
        $port = $this->bootSmtpServer();

        $transcript = $this->tlsSmtpConversation($port, [
            ['expect' => '/^220 /'],
            ['send' => "EHLO test.local\r\n"],
            ['expect' => '/250 HELP\r\n$/'],
            ['send' => "STARTTLS\r\n"],
            ['expect' => '/^220 2\.0\.0 Ready to start TLS\r\n$/'],
            ['upgrade' => true],
            // RFC 3207: the server discards prior session state and requires
            // a fresh EHLO after the TLS upgrade.
            ['send' => "EHLO test.local\r\n"],
            ['expect' => '/250 HELP\r\n$/'],
            ...$this->smtpAuthLoginSteps($inbox->smtp_username, $inbox->smtp_password),
            ['expect' => '/^235 /'],
            ['send' => "MAIL FROM:<alice@example.com>\r\n"],
            ['expect' => '/^250 2\.1\.0 OK\r\n$/'],
            ['send' => "RCPT TO:<bob@example.com>\r\n"],
            ['expect' => '/^250 2\.1\.5 OK\r\n$/'],
            ['send' => "DATA\r\n"],
            ['expect' => '/^354 /'],
            ['send' => $this->sampleRawMessage()."\r\n.\r\n"],
            ['expect' => '/^250 2\.0\.0 Message accepted\r\n$/'],
            ['send' => "QUIT\r\n"],
            ['expect' => '/^221 /'],
        ], timeoutSeconds: 10);

        $this->assertContains('* TLS handshake completed', $transcript);

        $message = Message::where('inbox_id', $inbox->id)->first();
        $this->assertNotNull($message);
        $this->assertSame('Hello over TLS', $message->subject);
        $this->assertSame('alice@example.com', $message->from_address);
    }

    public function test_the_post_tls_ehlo_no_longer_advertises_starttls_but_still_advertises_auth(): void
    {
        $port = $this->bootSmtpServer();

        $transcript = $this->tlsSmtpConversation($port, [
            ['expect' => '/^220 /'],
            ['send' => "EHLO test.local\r\n"],
            ['expect' => '/250 HELP\r\n$/'],
            ['send' => "STARTTLS\r\n"],
            ['expect' => '/^220 2\.0\.0 Ready to start TLS\r\n$/'],
            ['upgrade' => true],
            ['send' => "EHLO test.local\r\n"],
            ['expect' => '/250 HELP\r\n$/'],
        ], timeoutSeconds: 10);

        $postTlsEhlo = end($transcript);

        $this->assertStringNotContainsString('STARTTLS', $postTlsEhlo);
        $this->assertStringContainsString('AUTH LOGIN PLAIN', $postTlsEhlo);
    }

    public function test_issuing_starttls_again_after_the_upgrade_is_rejected(): void
    {
        $port = $this->bootSmtpServer();

        $transcript = $this->tlsSmtpConversation($port, [
            ['expect' => '/^220 /'],
            ['send' => "EHLO test.local\r\n"],
            ['expect' => '/250 HELP\r\n$/'],
            ['send' => "STARTTLS\r\n"],
            ['expect' => '/^220 2\.0\.0 Ready to start TLS\r\n$/'],
            ['upgrade' => true],
            ['send' => "STARTTLS\r\n"],
            ['expect' => '/^503 5\.5\.1 Already using TLS\r\n$/'],
        ], timeoutSeconds: 10);

        $this->assertStringContainsString('503', end($transcript));
    }
}
