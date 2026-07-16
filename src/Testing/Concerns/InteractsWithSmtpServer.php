<?php

namespace Sendtrap\Core\Testing\Concerns;

use React\EventLoop\Loop;
use React\EventLoop\StreamSelectLoop;
use React\Socket\ConnectionInterface;
use React\Socket\Connector;
use React\Socket\SocketServer;
use RuntimeException;
use Sendtrap\Core\Console\Commands\SmtpServer;
use Throwable;

/**
 * Drives Sendtrap\Core\Console\Commands\SmtpServer in-process over a real loopback
 * socket, on the same ReactPHP event loop as a scripted test client. This
 * exercises the real wire protocol (auth, line buffering, STARTTLS, size
 * limits) while staying on the same DB connection as the test (required for
 * the sqlite :memory: connection used in phpunit.xml).
 *
 * Moved into the package from a host test-support concern. Placed under the
 * package's main `src/` autoload — not `tests/` — deliberately: Composer
 * never merges a dependency's `autoload-dev` into a consuming project's
 * autoloader, so a trait living under the package's `tests/` directory would
 * be invisible to a host's own SMTP-daemon tests (hosts keep their own
 * daemon-level suites and drive them through this trait).
 * `PackageTestCase`, by contrast, lives OUT of `src/Testing/` in `tests/`
 * (namespaced
 * `Sendtrap\Core\Tests\PackageTestCase`), since it only ever imports a
 * require-dev dependency (Orchestra Testbench) and is consumed solely by the
 * package's own test suite — the opposite of this trait, which imports only
 * production dependencies (`React\*`, already required; `SmtpServer`) and is
 * consumed by host suites, so it MUST stay under `src/` for Composer to
 * autoload it into a consuming project (`autoload-dev` is never merged into
 * a consumer's autoloader). Both the host's remaining SMTP tests and the
 * package's own now `use
 * Sendtrap\Core\Testing\Concerns\InteractsWithSmtpServer;`.
 */
trait InteractsWithSmtpServer
{
    protected ?SocketServer $smtpSocket = null;

    /**
     * Boot an SmtpServer bound to a random loopback port and return that
     * port. Apply config overrides (e.g. tiny limits) before calling this.
     */
    protected function bootSmtpServer(): int
    {
        $command = new SmtpServer;
        $this->smtpSocket = $command->serve('tcp://127.0.0.1:0');

        return (int) parse_url($this->smtpSocket->getAddress(), PHP_URL_PORT);
    }

    /**
     * Drive a scripted SMTP conversation against a server booted via
     * bootSmtpServer(). Each step is one of:
     *   ['send' => "EHLO x\r\n"]  — write a line to the connection
     *   ['expect' => '/pattern/'] — wait until data received since the
     *                                previous step matches the pattern
     *   ['call' => fn () => ...]  — run an arbitrary PHP callback in-place,
     *                                mid-conversation, on the same
     *                                connection/session — e.g. a raw-SQL
     *                                mutation between AUTH and DATA that a
     *                                fresh connection couldn't observe (the
     *                                session's cached ids are captured once,
     *                                at AUTH time).
     * Returns the transcript (for debugging on failure); throws if the
     * conversation doesn't complete within $timeoutSeconds.
     *
     * @param  array<int, array{send?: string, expect?: string, call?: \Closure}>  $steps
     * @return array<int, string>
     */
    protected function smtpConversation(int $port, array $steps, int $timeoutSeconds = 5): array
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
            $failure ??= 'SMTP conversation timed out waiting for a response';
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
                    &$advance, &$index, &$buffer, &$transcript, $steps, $count, $conn, $finish
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

                    if (isset($step['call'])) {
                        ($step['call'])();
                        $transcript[] = '# (callback ran)';
                        $index++;
                        Loop::get()->futureTick($advance);

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

    /**
     * The AUTH LOGIN step sequence for a username/password.
     *
     * @return array<int, array{send?: string, expect?: string}>
     */
    protected function smtpAuthLoginSteps(string $username, string $password): array
    {
        return [
            ['send' => "AUTH LOGIN\r\n"],
            ['expect' => '/^334 /'],
            ['send' => base64_encode($username)."\r\n"],
            ['expect' => '/^334 /'],
            ['send' => base64_encode($password)."\r\n"],
        ];
    }

    /**
     * Auto-called by Laravel's test-trait bootstrapping (setUp{Trait}/
     * tearDown{Trait} convention) — see
     * Illuminate\Foundation\Testing\Concerns\InteractsWithTestCaseLifecycle::setUpTraits().
     * Named this way (rather than a plain tearDown()) to avoid colliding with
     * other testing traits' own tearDown hooks.
     */
    protected function tearDownInteractsWithSmtpServer(): void
    {
        $this->smtpSocket?->close();
        $this->smtpSocket = null;

        // SmtpServer arms idle/session/auth-tarpit timers on the process-wide
        // ReactPHP loop. A test that doesn't drive every connection to a
        // clean close can leave timers armed; swapping in a fresh loop
        // guarantees none of them fire during a later, unrelated test.
        Loop::set(new StreamSelectLoop);
    }
}
