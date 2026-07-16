<?php

namespace Sendtrap\Core\Console\Commands;

use Illuminate\Console\Command;
use React\EventLoop\Loop;
use React\EventLoop\TimerInterface;
use React\Socket\Connection;
use React\Socket\ConnectionInterface;
use React\Socket\SocketServer;
use React\Socket\StreamEncryption;
use Sendtrap\Core\Contracts\Entitlements;
use Sendtrap\Core\Contracts\LegacyOwnershipFallback;
use Sendtrap\Core\Contracts\UsageMeter;
use Sendtrap\Core\Exceptions\UnresolvedWorkspaceOwnerException;
use Sendtrap\Core\Jobs\ProcessIncomingMessage;
use Sendtrap\Core\Models\Inbox;
use Sendtrap\Core\Models\Workspace;
use Sendtrap\Core\Support\IpAllowList;

/**
 * A minimal multi-tenant SMTP server. Each connection authenticates with a
 * per-inbox SMTP username/password; accepted messages are queued for parsing
 * and routed into the authenticated inbox.
 */
class SmtpServer extends Command
{
    protected $signature = 'mail:smtp-server
        {--bind= : Address to bind to (default from config)}
        {--port= : Port to listen on (default from config)}';

    protected $description = 'Run the multi-tenant SMTP ingestion server';

    /** @var array<int, array<string, mixed>> Per-connection session state. */
    protected array $sessions = [];

    /** TLS context (['local_cert' => ..., 'local_pk' => ...]) or null when disabled. */
    protected ?array $tls = null;

    protected ?StreamEncryption $encryption = null;

    /** Live connection accounting + per-connection timers. */
    protected int $connections = 0;

    /** @var array<string, int> */
    protected array $ipConnections = [];

    /** @var array<int, TimerInterface> */
    protected array $timers = [];

    /** @var array<string, int|bool> */
    protected array $limits = [];

    public function handle(): int
    {
        $bind = $this->option('bind') ?: config('sendtrap.smtp_bind');
        $port = (int) ($this->option('port') ?: config('sendtrap.smtp_port'));
        $uri = "tcp://{$bind}:{$port}";

        $this->serve($uri);

        $this->info("Sendtrap SMTP server listening on {$uri}".($this->tls ? ' (STARTTLS enabled)' : ''));

        Loop::get()->run();

        return self::SUCCESS;
    }

    /**
     * Bind and start accepting connections on the given URI, without running
     * the event loop — lets tests drive the server in-process on the same
     * loop as a scripted client.
     */
    public function serve(string $uri): SocketServer
    {
        $this->limits = [
            'max' => (int) config('sendtrap.max_connections'),
            'perIp' => (int) config('sendtrap.max_connections_per_ip'),
            'idle' => (int) config('sendtrap.idle_timeout'),
            'session' => (int) config('sendtrap.max_session'),
            'authAttempts' => (int) config('sendtrap.max_auth_attempts'),
            'errors' => (int) config('sendtrap.max_errors'),
            'recipients' => (int) config('sendtrap.max_recipients'),
            'maxLine' => (int) config('sendtrap.max_line'),
            'maxSize' => (int) config('sendtrap.max_size'),
            'requireTls' => (bool) config('sendtrap.require_tls'),
        ];

        $this->bootTls();

        $socket = new SocketServer($uri);

        $socket->on('connection', function (ConnectionInterface $conn) {
            $this->onConnection($conn);
        });

        return $socket;
    }

    /**
     * Resolve a TLS cert/key (or auto-generate a self-signed one) for STARTTLS.
     */
    protected function bootTls(): void
    {
        if (! config('sendtrap.tls')) {
            return;
        }

        $cert = config('sendtrap.tls_cert');
        $key = config('sendtrap.tls_key');

        if (! $cert || ! $key || ! is_readable($cert) || ! is_readable($key)) {
            [$cert, $key] = $this->selfSignedCert();
        }

        if ($cert && $key) {
            $this->tls = ['local_cert' => $cert, 'local_pk' => $key];
            $this->encryption = new StreamEncryption(Loop::get(), true);
        }
    }

    /**
     * Generate (once) and return a self-signed cert/key pair under storage.
     *
     * @return array{0: ?string, 1: ?string}
     */
    protected function selfSignedCert(): array
    {
        $dir = storage_path('app/sendtrap-tls');
        $certFile = $dir.'/cert.pem';
        $keyFile = $dir.'/key.pem';

        if (is_readable($certFile) && is_readable($keyFile)) {
            return [$certFile, $keyFile];
        }

        if (! is_dir($dir)) {
            mkdir($dir, 0700, true);
        }

        $pk = openssl_pkey_new(['private_key_bits' => 2048, 'private_key_type' => OPENSSL_KEYTYPE_RSA]);
        if ($pk === false) {
            $this->warn('Could not generate TLS key; STARTTLS disabled.');

            return [null, null];
        }

        $dn = ['commonName' => config('sendtrap.public_host', 'localhost')];
        $csr = openssl_csr_new($dn, $pk, ['digest_alg' => 'sha256']);
        $x509 = openssl_csr_sign($csr, null, $pk, 3650, ['digest_alg' => 'sha256']);

        openssl_x509_export($x509, $certOut);
        openssl_pkey_export($pk, $keyOut);

        file_put_contents($certFile, $certOut);
        file_put_contents($keyFile, $keyOut);
        chmod($keyFile, 0600);

        return [$certFile, $keyFile];
    }

    protected function onConnection(ConnectionInterface $conn): void
    {
        $ip = $this->remoteIp($conn);

        // Global + per-IP connection caps.
        if ($this->connections >= $this->limits['max']
            || ($this->ipConnections[$ip] ?? 0) >= $this->limits['perIp']) {
            $conn->write("421 4.7.0 Too many connections, try again later\r\n");
            $conn->end();

            return;
        }

        $this->connections++;
        $this->ipConnections[$ip] = ($this->ipConnections[$ip] ?? 0) + 1;

        $id = spl_object_id($conn);
        $this->resetSession($id);
        $this->sessions[$id]['ip'] = $ip;

        $conn->write('220 '.$this->hostname()." Sendtrap SMTP ready\r\n");
        $this->armIdleTimer($conn, $id);

        // Hard cap on total session duration (slow-loris protection).
        $this->sessions[$id]['sessionTimer'] = Loop::get()->addTimer($this->limits['session'], function () use ($conn, $id) {
            if (! isset($this->sessions[$id])) {
                return;
            }
            $conn->write("421 4.4.2 Session timeout, closing connection\r\n");
            $conn->end();
        });

        $conn->on('data', function ($chunk) use ($conn, $id) {
            if (! isset($this->sessions[$id])) {
                return;
            }
            $this->armIdleTimer($conn, $id);
            $this->sessions[$id]['buffer'] .= $chunk;
            $this->drain($conn, $id);
        });

        $conn->on('close', function () use ($id, $ip) {
            if (isset($this->timers[$id])) {
                Loop::get()->cancelTimer($this->timers[$id]);
                unset($this->timers[$id]);
            }
            if (isset($this->sessions[$id]['sessionTimer'])) {
                Loop::get()->cancelTimer($this->sessions[$id]['sessionTimer']);
            }
            unset($this->sessions[$id]);
            $this->connections = max(0, $this->connections - 1);
            if (isset($this->ipConnections[$ip])) {
                $this->ipConnections[$ip]--;
                if ($this->ipConnections[$ip] <= 0) {
                    unset($this->ipConnections[$ip]);
                }
            }
        });

        $conn->on('error', function () use ($conn) {
            $conn->close();
        });
    }

    /** Reset (or start) the per-connection idle timer. */
    protected function armIdleTimer(ConnectionInterface $conn, int $id): void
    {
        if (isset($this->timers[$id])) {
            Loop::get()->cancelTimer($this->timers[$id]);
        }

        $this->timers[$id] = Loop::get()->addTimer($this->limits['idle'], function () use ($conn, $id) {
            if (! isset($this->sessions[$id])) {
                return;
            }
            $conn->write("421 4.4.2 Idle timeout, closing connection\r\n");
            $conn->end();
        });
    }

    /** Extract the remote IP (without port) from a connection. */
    protected function remoteIp(ConnectionInterface $conn): string
    {
        $addr = $conn->getRemoteAddress();
        if ($addr === null) {
            return 'unknown';
        }
        $host = parse_url($addr, PHP_URL_HOST);

        return $host ? trim($host, '[]') : 'unknown';
    }

    /** Count a protocol error; close the connection once the limit is hit. */
    protected function bumpError(ConnectionInterface $conn, int $id): bool
    {
        $this->sessions[$id]['errors'] = ($this->sessions[$id]['errors'] ?? 0) + 1;

        if ($this->sessions[$id]['errors'] >= $this->limits['errors']) {
            $conn->write("421 4.7.0 Too many errors, closing connection\r\n");
            $conn->end();

            return true;
        }

        return false;
    }

    /**
     * Pull complete CRLF-terminated lines out of the buffer and act on them.
     */
    protected function drain(ConnectionInterface $conn, int $id): void
    {
        // Guard against unbounded command lines with no CRLF.
        if ($this->sessions[$id]['mode'] !== 'data'
            && ! str_contains($this->sessions[$id]['buffer'], "\n")
            && strlen($this->sessions[$id]['buffer']) > $this->limits['maxLine']) {
            $conn->write("500 5.5.2 Line too long\r\n");
            $conn->end();

            return;
        }

        // Guard against an oversized message body flooding memory.
        if ($this->sessions[$id]['mode'] === 'data'
            && strlen($this->sessions[$id]['data']) + strlen($this->sessions[$id]['buffer']) > $this->limits['maxSize']) {
            $this->sessions[$id]['dataOverflow'] = true;
            $this->sessions[$id]['data'] = '';
            // Keep only a trailing slice so we can still detect end-of-data ".".
            if (strlen($this->sessions[$id]['buffer']) > $this->limits['maxLine']) {
                $this->sessions[$id]['buffer'] = substr($this->sessions[$id]['buffer'], -$this->limits['maxLine']);
            }
        }

        while (($pos = strpos($this->sessions[$id]['buffer'], "\n")) !== false) {
            $line = substr($this->sessions[$id]['buffer'], 0, $pos + 1);
            $this->sessions[$id]['buffer'] = substr($this->sessions[$id]['buffer'], $pos + 1);
            $line = rtrim($line, "\r\n");

            if ($this->sessions[$id]['mode'] === 'data') {
                $this->handleDataLine($conn, $id, $line);
            } elseif ($this->sessions[$id]['authStep'] !== null) {
                $this->handleAuthLine($conn, $id, $line);
            } else {
                $this->handleCommand($conn, $id, $line);
            }
        }
    }

    protected function handleCommand(ConnectionInterface $conn, int $id, string $line): void
    {
        $parts = explode(' ', $line, 2);
        $verb = strtoupper($parts[0]);
        $arg = $parts[1] ?? '';

        switch ($verb) {
            case 'EHLO':
            case 'HELO':
                $this->resetTransaction($id);
                $host = $this->hostname();
                if ($verb === 'EHLO') {
                    $max = config('sendtrap.max_size');
                    $conn->write("250-{$host} greets you\r\n");
                    $conn->write("250-SIZE {$max}\r\n");
                    if ($this->tls && ! $this->sessions[$id]['secure']) {
                        $conn->write("250-STARTTLS\r\n");
                    }
                    $conn->write("250-AUTH LOGIN PLAIN\r\n");
                    $conn->write("250 HELP\r\n");
                } else {
                    $conn->write("250 {$host}\r\n");
                }
                break;

            case 'STARTTLS':
                $this->handleStartTls($conn, $id);
                break;

            case 'AUTH':
                $this->handleAuthStart($conn, $id, $arg);
                break;

            case 'MAIL':
                if (! $this->sessions[$id]['inboxId']) {
                    $conn->write("530 5.7.0 Authentication required\r\n");
                    break;
                }
                $this->sessions[$id]['from'] = $this->extractAddress($arg);
                $conn->write("250 2.1.0 OK\r\n");
                break;

            case 'RCPT':
                if (! $this->sessions[$id]['inboxId']) {
                    $conn->write("530 5.7.0 Authentication required\r\n");
                    break;
                }
                if (count($this->sessions[$id]['rcpt']) >= $this->limits['recipients']) {
                    $conn->write("452 4.5.3 Too many recipients\r\n");
                    break;
                }
                $this->sessions[$id]['rcpt'][] = $this->extractAddress($arg);
                $conn->write("250 2.1.5 OK\r\n");
                break;

            case 'DATA':
                if (! $this->sessions[$id]['inboxId']) {
                    $conn->write("530 5.7.0 Authentication required\r\n");
                    break;
                }
                $this->sessions[$id]['mode'] = 'data';
                $this->sessions[$id]['data'] = '';
                $conn->write("354 End data with <CR><LF>.<CR><LF>\r\n");
                break;

            case 'RSET':
                $this->resetTransaction($id);
                $conn->write("250 2.0.0 OK\r\n");
                break;

            case 'NOOP':
                $conn->write("250 2.0.0 OK\r\n");
                break;

            case 'VRFY':
                $conn->write("252 2.1.5 Cannot VRFY user\r\n");
                break;

            case 'QUIT':
                $conn->write("221 2.0.0 Bye\r\n");
                $conn->end();
                break;

            default:
                if ($this->bumpError($conn, $id)) {
                    break;
                }
                $conn->write("500 5.5.2 Command unrecognized\r\n");
        }
    }

    protected function handleStartTls(ConnectionInterface $conn, int $id): void
    {
        if (! $this->tls || ! $this->encryption || ! $conn instanceof Connection) {
            $conn->write("454 4.7.0 TLS not available\r\n");

            return;
        }

        if ($this->sessions[$id]['secure']) {
            $conn->write("503 5.5.1 Already using TLS\r\n");

            return;
        }

        $conn->write("220 2.0.0 Ready to start TLS\r\n");

        // Apply the cert to this connection's socket, then negotiate TLS.
        stream_context_set_option($conn->stream, ['ssl' => $this->tls]);

        $this->encryption->enable($conn)->then(
            function () use ($id) {
                // RFC 3207: discard any state learned before TLS; require a new EHLO.
                $this->resetSession($id);
                $this->sessions[$id]['secure'] = true;
            },
            function () use ($conn) {
                $conn->close();
            }
        );
    }

    protected function handleAuthStart(ConnectionInterface $conn, int $id, string $arg): void
    {
        if ($this->limits['requireTls'] && ! $this->sessions[$id]['secure']) {
            $conn->write("530 5.7.0 Must issue STARTTLS command first\r\n");

            return;
        }

        $bits = explode(' ', $arg, 2);
        $mechanism = strtoupper($bits[0] ?? '');

        if ($mechanism === 'LOGIN') {
            $this->sessions[$id]['authStep'] = 'username';
            $conn->write('334 '.base64_encode('Username:')."\r\n");
        } elseif ($mechanism === 'PLAIN') {
            if (! empty($bits[1])) {
                $this->finishPlainAuth($conn, $id, $bits[1]);
            } else {
                $this->sessions[$id]['authStep'] = 'plain';
                $conn->write("334 \r\n");
            }
        } else {
            $conn->write("504 5.5.4 Unrecognized authentication type\r\n");
        }
    }

    protected function handleAuthLine(ConnectionInterface $conn, int $id, string $line): void
    {
        $step = $this->sessions[$id]['authStep'];

        if ($step === 'plain') {
            $this->finishPlainAuth($conn, $id, $line);

            return;
        }

        if ($step === 'username') {
            $this->sessions[$id]['authUser'] = base64_decode($line);
            $this->sessions[$id]['authStep'] = 'password';
            $conn->write('334 '.base64_encode('Password:')."\r\n");

            return;
        }

        if ($step === 'password') {
            $user = $this->sessions[$id]['authUser'];
            $pass = base64_decode($line);
            $this->attemptAuth($conn, $id, $user, $pass);
        }
    }

    protected function finishPlainAuth(ConnectionInterface $conn, int $id, string $token): void
    {
        // AUTH PLAIN payload is base64("\0username\0password")
        $decoded = base64_decode($token);
        $segments = explode("\0", $decoded);
        $user = $segments[1] ?? '';
        $pass = $segments[2] ?? '';
        $this->attemptAuth($conn, $id, $user, $pass);
    }

    protected function attemptAuth(ConnectionInterface $conn, int $id, string $user, string $pass): void
    {
        $this->sessions[$id]['authStep'] = null;
        $this->sessions[$id]['authUser'] = null;

        // project.workspace is eager-loaded so both the session workspaceId
        // derivation below and effectiveAllowedIps()'s workspace tier resolve
        // without a lazy query on the auth hot path. Plan 06 Phase 3b slice 5:
        // core no longer names the Cloud-only project.team hop (a host adapter
        // resolves the legacy owner behind the LegacyOwnershipFallback
        // contract, once per model instance) — matching the ProcessIncoming
        // Message eager-load treatment.
        $inbox = Inbox::with('project.workspace')->where('smtp_username', $user)->first();
        // Constant-time-ish compare; compare against a dummy when the user is
        // unknown to avoid leaking validity via timing.
        $expected = $inbox ? $inbox->smtp_password : str_repeat('x', 24);

        if ($inbox && hash_equals($expected, $pass)) {
            // IP allowlist (inbox › project › account).
            if (! IpAllowList::allows($inbox->effectiveAllowedIps(), $this->sessions[$id]['ip'] ?? null)) {
                $conn->write("535 5.7.1 Access denied for your IP address\r\n");

                return;
            }

            // Plan 06 Phase 3b slice 5 (§1.3): store only inboxId + a
            // workspace-rooted workspaceId. The Phase-2 dual-key teamId is
            // gone with the Team fallback it fed — the LegacyOwnershipFallback
            // contract re-derives the legacy owner from the inboxId session
            // key at DATA time (§3.4), so nothing Team-shaped rides the
            // session. L-N5: workspaceId now derives from the workspace
            // relation directly ($inbox->project?->workspace?->id()), not the
            // former team-rooted $inbox->project?->team?->workspace_id — a
            // team-backfilled-but-project-lagging tenant therefore takes the
            // DATA-time fallback path (decision-equivalent, §3's fallback
            // reproduces the same enforcement outcome).
            $this->sessions[$id]['inboxId'] = $inbox->id;
            $this->sessions[$id]['workspaceId'] = $inbox->project?->workspace?->id();
            $this->sessions[$id]['authFails'] = 0;
            $conn->write("235 2.7.0 Authentication successful\r\n");

            return;
        }

        $this->sessions[$id]['authFails'] = ($this->sessions[$id]['authFails'] ?? 0) + 1;
        $fails = $this->sessions[$id]['authFails'];

        // Tarpit: delay the failure response (escalating) to slow brute-force,
        // and drop the connection once the attempt limit is reached.
        Loop::get()->addTimer(min(5, $fails * 1.5), function () use ($conn, $id, $fails) {
            if (! isset($this->sessions[$id])) {
                return;
            }
            if ($fails >= $this->limits['authAttempts']) {
                $conn->write("421 4.7.0 Too many authentication failures\r\n");
                $conn->end();
            } else {
                $conn->write("535 5.7.8 Authentication credentials invalid\r\n");
            }
        });
    }

    protected function handleDataLine(ConnectionInterface $conn, int $id, string $line): void
    {
        if ($line === '.') {
            if (! empty($this->sessions[$id]['dataOverflow'])) {
                $this->resetTransaction($id);
                $conn->write("552 5.3.4 Message exceeds maximum size\r\n");

                return;
            }

            // Per-package sending limits (rate + monthly quota) and size cap.
            // Plan 06 Phase 3b slice 5 (§3.4, H-N1-corrected sketch): the
            // workspace path enforces through the Entitlements/UsageMeter
            // contracts against the real Workspace resolved from the session's
            // workspaceId (re-queried at DATA time so plan changes stay
            // effective mid-session). The fallback TRIGGER is DERIVED, never
            // configured (H-4): a null workspace (project not yet backfilled,
            // or the session simply had no wid), or a workspace whose concrete
            // owner the host adapter cannot resolve
            // (UnresolvedWorkspaceOwnerException — the second trigger, §3.2),
            // decides the LegacyOwnershipFallback is needed. active() is
            // consulted ONLY after that derivation, to decide proceed (true,
            // the default — today's behavior) versus fail loud (false —
            // tempfail, never a hard reject, never an unhandled throw in the
            // daemon's event loop). Under active()===true an unresolvable
            // inbox/team inside the fallback is accept-unchecked, byte-
            // matching the pre-move double-null fall-through (§3.3, H-N1).
            $workspace = ($wid = $this->sessions[$id]['workspaceId'] ?? null)
                ? Workspace::find($wid)
                : null;
            $fallback = app(LegacyOwnershipFallback::class);
            $usesFallback = false;
            $inbox = null;

            if ($workspace) {
                try {
                    // Per-plan message size cap (always active, like the send limits).
                    $sizeCap = app(Entitlements::class)->for($workspace)->emailSizeBytes();
                    if ($sizeCap !== null && strlen($this->sessions[$id]['data']) > $sizeCap) {
                        $this->resetTransaction($id);
                        $conn->write("552 5.3.4 Message exceeds your plan’s size limit\r\n");

                        return;
                    }

                    $reason = app(UsageMeter::class)->checkSend($workspace);
                    if ($reason === 'rate') {
                        $this->resetTransaction($id);
                        $conn->write("452 4.2.1 Rate limit exceeded, please slow down\r\n");

                        return;
                    }
                    if ($reason === 'quota') {
                        $this->resetTransaction($id);
                        $conn->write("550 5.7.0 Monthly sending quota exceeded\r\n");

                        return;
                    }
                } catch (UnresolvedWorkspaceOwnerException) {
                    // Resolved but orphaned (§3.2's second trigger): fall
                    // through to the fallback path below, exactly like a null
                    // workspace — never let the adapter's throw reach the loop.
                    $workspace = null;
                }
            }

            if (! $workspace) {
                // H-4: the null $workspace IS the trigger — active() gates only
                // HOW the already-determined need is handled, not whether to
                // attempt it.
                if (! $fallback->active()) {
                    // Kill switch engaged: an operator has asserted no record
                    // should still need the fallback. Tempfail — never a hard
                    // reject, never an unhandled throw in the event loop. This
                    // is the ONLY branch in this method that denies/tempfails.
                    $this->resetTransaction($id);
                    $conn->write("452 4.3.0 Temporarily unavailable, please retry\r\n");

                    return;
                }

                $usesFallback = true;

                // H-N1(a): re-resolve the Inbox from the session's own inboxId
                // (stored by attemptAuth()). Just as nullable as Workspace::find()
                // above — the inbox row itself can cascade-delete mid-session
                // (SmtpOrphanWorkspaceTest's scenario). A null $inbox, or a
                // non-null $inbox resolving no legacy owner, both make the
                // contract answer "no limit" — accept-unchecked, never a deny.
                $inbox = Inbox::find($this->sessions[$id]['inboxId'] ?? null);

                // Per-plan message size cap (always active, like the send limits).
                $sizeCap = $fallback->emailSizeLimitBytes($inbox);
                if ($sizeCap !== null && strlen($this->sessions[$id]['data']) > $sizeCap) {
                    $this->resetTransaction($id);
                    $conn->write("552 5.3.4 Message exceeds your plan’s size limit\r\n");

                    return;
                }

                $reason = $fallback->checkSend($inbox);
                if ($reason === 'rate') {
                    $this->resetTransaction($id);
                    $conn->write("452 4.2.1 Rate limit exceeded, please slow down\r\n");

                    return;
                }
                if ($reason === 'quota') {
                    $this->resetTransaction($id);
                    $conn->write("550 5.7.0 Monthly sending quota exceeded\r\n");

                    return;
                }
            }

            $raw = $this->sessions[$id]['data'];
            $inboxId = $this->sessions[$id]['inboxId'];
            $envelopeFrom = $this->sessions[$id]['from'];
            $envelopeTo = $this->sessions[$id]['rcpt'];

            ProcessIncomingMessage::dispatch($inboxId, $raw, $envelopeFrom, $envelopeTo);

            if ($workspace) {
                app(UsageMeter::class)->recordSend($workspace);
            } elseif ($usesFallback) {
                // Shares $fallback's per-inbox owner resolution with the
                // checkSend() above (L-4) — the two can never disagree about
                // whether a legacy owner exists. No-op for a null $inbox.
                $fallback->recordSend($inbox);
            }

            $this->resetTransaction($id);
            $conn->write("250 2.0.0 Message accepted\r\n");

            return;
        }

        // Already over the size limit — keep consuming until end-of-data.
        if (! empty($this->sessions[$id]['dataOverflow'])) {
            return;
        }

        // Undo dot-stuffing (a leading '.' on a line is doubled by the client).
        if (str_starts_with($line, '.')) {
            $line = substr($line, 1);
        }

        $this->sessions[$id]['data'] .= $line."\r\n";

        if (strlen($this->sessions[$id]['data']) > $this->limits['maxSize']) {
            $this->sessions[$id]['dataOverflow'] = true;
            $this->sessions[$id]['data'] = '';
        }
    }

    protected function extractAddress(string $arg): string
    {
        if (preg_match('/<([^>]*)>/', $arg, $m)) {
            return $m[1];
        }

        // Strip a leading FROM:/TO: if present.
        $arg = preg_replace('/^(FROM|TO):/i', '', trim($arg));

        return trim($arg);
    }

    protected function resetSession(int $id): void
    {
        // Preserve connection-scoped state across a STARTTLS reset.
        $prev = $this->sessions[$id] ?? [];

        $this->sessions[$id] = [
            'buffer' => '',
            'mode' => 'command',
            'authStep' => null,
            'authUser' => null,
            'inboxId' => null,
            'from' => null,
            'rcpt' => [],
            'data' => '',
            'dataOverflow' => false,
            'errors' => 0,
            'authFails' => 0,
            'secure' => $prev['secure'] ?? false,
            'ip' => $prev['ip'] ?? null,
            'sessionTimer' => $prev['sessionTimer'] ?? null,
        ];
    }

    protected function resetTransaction(int $id): void
    {
        $this->sessions[$id]['from'] = null;
        $this->sessions[$id]['rcpt'] = [];
        $this->sessions[$id]['data'] = '';
        $this->sessions[$id]['dataOverflow'] = false;
        $this->sessions[$id]['mode'] = 'command';
    }

    protected function hostname(): string
    {
        return config('sendtrap.public_host', 'localhost');
    }
}
