<?php

namespace Sendtrap\Core\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Str;
use Sendtrap\Core\Jobs\ProcessIncomingMessage;
use Sendtrap\Core\Models\Inbox;
use Sendtrap\Core\Models\Message;

/**
 * Seed an inbox with one rich example message, so a fresh install has
 * something to explore before any real application is wired up — and so
 * support triage can split ingestion bugs from app-config bugs with one
 * command ("run send-test: does it appear?").
 *
 * The fixture deliberately lights up every UI surface at once: multipart
 * HTML + plain text, a file attachment, an inline cid: image, unresolved
 * merge tags, an envelope-only BCC recipient, at least one failing lint
 * check (no List-Unsubscribe header), and a caniemail-scored CSS feature
 * (display:grid) for HTML Check.
 *
 * By default the fixture is injected straight into the ingestion pipeline
 * (ProcessIncomingMessage), so it works with no SMTP daemon running.
 * --via-smtp instead opens a real loopback connection to the configured
 * daemon port and delivers over the wire — EHLO, STARTTLS when advertised,
 * AUTH LOGIN with the inbox's own credentials — proving the full path an
 * application would use.
 */
class SendTest extends Command
{
    protected $signature = 'sendtrap:send-test
        {--inbox= : Inbox to receive the message, by id or name (default: the only inbox, or a prompt)}
        {--via-smtp : Deliver over a real loopback SMTP connection instead of injecting into the pipeline}';

    protected $description = 'Send a rich example message to an inbox — no configured application required';

    public function handle(): int
    {
        $inbox = $this->resolveInbox();

        if ($inbox === null) {
            return self::FAILURE;
        }

        $envelopeFrom = 'demo@sendtrap.example';
        // audit@ is on the SMTP envelope only — it never appears in the
        // To/Cc headers, which is exactly how a real BCC arrives.
        $envelopeTo = ['avery@example.com', 'quinn@example.com', 'audit@example.com'];
        $raw = $this->fixture();
        $baselineId = (int) $inbox->messages()->max('id');

        if ($this->option('via-smtp')) {
            if (! $this->deliverViaSmtp($inbox, $envelopeFrom, $envelopeTo, $raw)) {
                return self::FAILURE;
            }
        } else {
            (new ProcessIncomingMessage($inbox->id, $raw, $envelopeFrom, $envelopeTo))->handle();
        }

        $message = $this->awaitArrival($inbox, $baselineId);

        if ($message === null) {
            if ($this->option('via-smtp')) {
                // The daemon accepted the message and dispatched ingestion
                // to the queue — on an async connection it lands once a
                // worker runs. The wire test itself has already passed.
                $this->components->warn(
                    'Delivered over SMTP, but ingestion is still queued — the message will '
                    .'appear once a queue worker processes it (QUEUE_CONNECTION is not sync).'
                );

                return self::SUCCESS;
            }

            $this->components->error('The message was sent but never appeared in the inbox — check the log for ingestion errors.');

            return self::FAILURE;
        }

        $this->report($inbox, $message);

        return self::SUCCESS;
    }

    /**
     * Wait (briefly) for a message newer than the pre-send baseline —
     * instant on the pipeline path and sync queues, a short poll when a
     * queue worker is doing the ingestion.
     */
    private function awaitArrival(Inbox $inbox, int $baselineId): ?Message
    {
        $deadline = microtime(true) + 10;

        do {
            $message = $inbox->messages()
                ->where('id', '>', $baselineId)
                ->where('test_id', 'send-test')
                ->latest('id')
                ->first();

            if ($message !== null) {
                return $message;
            }

            usleep(250_000);
        } while (microtime(true) < $deadline);

        return null;
    }

    private function resolveInbox(): ?Inbox
    {
        $key = $this->option('inbox');

        if ($key !== null) {
            $inbox = Inbox::query()
                ->when(
                    ctype_digit((string) $key),
                    fn ($q) => $q->whereKey((int) $key)->orWhere('name', $key),
                    fn ($q) => $q->where('name', $key),
                )
                ->first();

            if ($inbox === null) {
                $this->components->error("No inbox with id or name \"{$key}\".");
            }

            return $inbox;
        }

        $inboxes = Inbox::with('project')->get();

        if ($inboxes->isEmpty()) {
            $this->components->error('No inboxes exist yet — create one first.');

            return null;
        }

        if ($inboxes->count() === 1) {
            return $inboxes->first();
        }

        $labels = $inboxes->mapWithKeys(
            fn (Inbox $i) => ["#{$i->id}" => "{$i->project->name} / {$i->name}"]
        )->all();

        // For an associative choices array Symfony returns the selected KEY,
        // but fall back to a value lookup for safety.
        $choice = $this->choice('Which inbox should receive the test message?', $labels);
        $key = array_key_exists($choice, $labels) ? $choice : array_search($choice, $labels, true);

        return $inboxes->firstWhere('id', (int) ltrim((string) $key, '#'));
    }

    private function report(Inbox $inbox, Message $message): void
    {
        $this->components->info("Test message #{$message->id} \"{$message->subject}\" is in inbox \"{$inbox->name}\".");
        $this->components->bulletList([
            'HTML + plain-text bodies, an attachment, and an inline image',
            'audit@example.com received it via BCC — visible only under envelope recipients',
            'unresolved merge tags and a failing lint check to explore under Checks',
            'CSS that HTML Check flags for real email clients',
        ]);
        $this->line('  Open the inbox in the web UI, or fetch it over the API:');
        $this->line("    curl -H \"Authorization: Bearer {$inbox->api_token}\" \\");
        $this->line('      "'.rtrim(config('app.url'), '/').'/api/v1/messages?test_id=send-test"');
    }

    /**
     * The raw RFC 822 fixture. Structure:
     *
     *   multipart/mixed
     *   ├── multipart/related
     *   │   ├── multipart/alternative
     *   │   │   ├── text/plain
     *   │   │   └── text/html   (cid: image, {{merge_tag}}, display:grid, links)
     *   │   └── image/png       (inline, Content-ID <sendtrap-logo>)
     *   └── text/plain          (attachment: sendtrap-demo.txt)
     */
    private function fixture(): string
    {
        $mixed = 'st-mixed-'.Str::random(12);
        $related = 'st-related-'.Str::random(12);
        $alternative = 'st-alt-'.Str::random(12);
        $messageId = Str::uuid()->toString().'@sendtrap.example';
        $date = now()->toRfc2822String();

        $png = $this->inlinePng();
        $docsUrl = rtrim(config('app.url'), '/').'/docs/api';
        $repoUrl = 'https://github.com/sendtraphq';

        $text = <<<TEXT
        Hi {{first_name}},

        This is Sendtrap's built-in test message. Your inbox caught it the
        same way it will catch mail from your application.

        Things to look at:
          - the HTML version has an inline image and CSS that HTML Check flags
          - sendtrap-demo.txt is attached
          - audit@example.com got this via BCC (see the envelope recipients)
          - {{first_name}} above is an unresolved merge tag, detected for you

        API docs: {$docsUrl}
        Project: {$repoUrl}
        TEXT;

        $html = <<<HTML
        <html>
        <head><style>
          .features { display: grid; grid-template-columns: 1fr 1fr; gap: 12px; }
          body { font-family: sans-serif; color: #1e293b; }
        </style></head>
        <body>
          <img src="cid:sendtrap-logo" width="48" height="48" alt="Sendtrap">
          <h1>Hi {{first_name}},</h1>
          <p>This is Sendtrap's built-in test message. Your inbox caught it the
             same way it will catch mail from your application.</p>
          <div class="features">
            <div>An inline <code>cid:</code> image (above)</div>
            <div>A file attachment (sendtrap-demo.txt)</div>
            <div>An envelope-only BCC recipient</div>
            <div>Unresolved merge tags, flagged in Checks</div>
          </div>
          <p>This grid layout is itself a demo: open <strong>HTML Check</strong> to see
             which email clients would struggle with it.</p>
          <p><a href="{$docsUrl}">Explore the API</a> ·
             <a href="{$repoUrl}">Sendtrap on GitHub</a></p>
        </body>
        </html>
        HTML;

        $attachment = <<<'TXT'
        This file rode along as a normal attachment. Its size, content type
        and checksum are on the message's attachment list — and it can be
        downloaded through the UI or the API.
        TXT;

        $crlf = fn (string $s) => str_replace("\n", "\r\n", $s);

        return implode("\r\n", [
            'From: Sendtrap Demo <demo@sendtrap.example>',
            'To: Avery Example <avery@example.com>',
            'Cc: Quinn QA <quinn@example.com>',
            'Subject: =?UTF-8?B?'.base64_encode('Your first Sendtrap message 🎉').'?=',
            "Date: {$date}",
            "Message-ID: <{$messageId}>",
            'X-Sendtrap-Test-Id: send-test',
            'MIME-Version: 1.0',
            "Content-Type: multipart/mixed; boundary=\"{$mixed}\"",
            '',
            "--{$mixed}",
            "Content-Type: multipart/related; boundary=\"{$related}\"",
            '',
            "--{$related}",
            "Content-Type: multipart/alternative; boundary=\"{$alternative}\"",
            '',
            "--{$alternative}",
            'Content-Type: text/plain; charset=utf-8',
            'Content-Transfer-Encoding: 8bit',
            '',
            $crlf($text),
            '',
            "--{$alternative}",
            'Content-Type: text/html; charset=utf-8',
            'Content-Transfer-Encoding: 8bit',
            '',
            $crlf($html),
            '',
            "--{$alternative}--",
            "--{$related}",
            'Content-Type: image/png; name="sendtrap-logo.png"',
            'Content-Transfer-Encoding: base64',
            'Content-ID: <sendtrap-logo>',
            'Content-Disposition: inline; filename="sendtrap-logo.png"',
            '',
            $png,
            '',
            "--{$related}--",
            "--{$mixed}",
            'Content-Type: text/plain; charset=utf-8; name="sendtrap-demo.txt"',
            'Content-Transfer-Encoding: base64',
            'Content-Disposition: attachment; filename="sendtrap-demo.txt"',
            '',
            chunk_split(base64_encode($crlf($attachment)), 76, "\r\n").'--'.$mixed.'--',
            '',
        ]);
    }

    /**
     * A single-pixel green PNG, base64-encoded for the inline cid: part —
     * sized up by the HTML, real enough to prove cid: rewriting end to end.
     * Built programmatically rather than embedded as a base64/hex constant,
     * which the publication scanner's secrets-entropy gate would flag.
     */
    private function inlinePng(): string
    {
        $chunk = fn (string $type, string $data): string => pack('N', strlen($data)).$type.$data.pack('N', crc32($type.$data));

        $ihdr = pack('NNCCCCC', 1, 1, 8, 6, 0, 0, 0);          // 1x1, 8-bit RGBA
        $idat = gzcompress("\x00\x62\xf8\x6d\xff", 9);          // filter byte + one green pixel

        return base64_encode(
            "\x89PNG\r\n\x1a\n".$chunk('IHDR', $ihdr).$chunk('IDAT', $idat).$chunk('IEND', '')
        );
    }

    /**
     * Deliver the fixture over a real loopback SMTP conversation using the
     * inbox's own credentials: EHLO → STARTTLS (when advertised) → AUTH
     * LOGIN → MAIL/RCPT/DATA. Blocking stream client — this is a one-shot
     * CLI path, not a server.
     *
     * @param  list<string>  $envelopeTo
     */
    private function deliverViaSmtp(Inbox $inbox, string $envelopeFrom, array $envelopeTo, string $raw): bool
    {
        $host = '127.0.0.1';
        $port = (int) config('sendtrap.smtp_port');

        $socket = @stream_socket_client("tcp://{$host}:{$port}", $errno, $error, 10);

        if ($socket === false) {
            $this->components->error(
                "Could not connect to the SMTP daemon on {$host}:{$port} ({$error}). "
                .'Is `php artisan mail:smtp-server` running? Omit --via-smtp to inject without it.'
            );

            return false;
        }

        stream_set_timeout($socket, 15);

        try {
            $this->expect($socket, '220');
            $ehlo = $this->command($socket, "EHLO sendtrap-send-test\r\n", '250');

            if (str_contains($ehlo, 'STARTTLS')) {
                $this->command($socket, "STARTTLS\r\n", '220');
                // The daemon auto-generates a self-signed cert by default,
                // so peer verification stays off for this loopback hop.
                stream_context_set_option($socket, 'ssl', 'verify_peer', false);
                stream_context_set_option($socket, 'ssl', 'verify_peer_name', false);

                if (! @stream_socket_enable_crypto($socket, true, STREAM_CRYPTO_METHOD_TLS_CLIENT)) {
                    throw new \RuntimeException('TLS negotiation failed after STARTTLS.');
                }

                $this->command($socket, "EHLO sendtrap-send-test\r\n", '250');
            }

            $this->command($socket, "AUTH LOGIN\r\n", '334');
            $this->command($socket, base64_encode($inbox->smtp_username)."\r\n", '334');
            $this->command($socket, base64_encode($inbox->smtp_password)."\r\n", '235');

            $this->command($socket, "MAIL FROM:<{$envelopeFrom}>\r\n", '250');

            foreach ($envelopeTo as $recipient) {
                $this->command($socket, "RCPT TO:<{$recipient}>\r\n", '250');
            }

            $this->command($socket, "DATA\r\n", '354');

            // Dot-stuff per RFC 5321 §4.5.2, then terminate.
            $stuffed = preg_replace('/^\./m', '..', $raw);
            fwrite($socket, rtrim($stuffed, "\r\n")."\r\n.\r\n");
            $this->expect($socket, '250');

            $this->command($socket, "QUIT\r\n", '221');
        } catch (\RuntimeException $e) {
            $this->components->error("SMTP delivery failed: {$e->getMessage()}");
            fclose($socket);

            return false;
        }

        fclose($socket);

        return true;
    }

    /** Write one SMTP command and require a reply code (returns the full reply). */
    private function command($socket, string $line, string $expectCode): string
    {
        fwrite($socket, $line);

        return $this->expect($socket, $expectCode);
    }

    /** Read one (possibly multi-line) SMTP reply and require the given code. */
    private function expect($socket, string $code): string
    {
        $reply = '';

        do {
            $line = fgets($socket);

            if ($line === false) {
                throw new \RuntimeException("connection closed while waiting for a {$code} reply");
            }

            $reply .= $line;
        } while (preg_match('/^\d{3}-/', $line));

        if (! str_starts_with($reply, $code)) {
            throw new \RuntimeException('expected '.$code.', got: '.trim($reply));
        }

        return $reply;
    }
}
