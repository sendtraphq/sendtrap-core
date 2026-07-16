<?php

namespace Sendtrap\Core\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Mail;
use Sendtrap\Core\Models\Message;
use Symfony\Component\Mailer\Envelope;
use Symfony\Component\Mime\Address;
use Symfony\Component\Mime\RawMessage;

/**
 * Re-sends a captured message's raw MIME to a real address via the configured
 * outbound relay (MAIL_* env). Used for inbox auto-forwarding.
 */
class ForwardMessage implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public function __construct(
        public int $messageId,
        public string $to,
    ) {}

    public function handle(): void
    {
        $message = Message::find($this->messageId);

        if (! $message) {
            return;
        }

        $raw = $message->raw();

        if ($raw === '') {
            return;
        }

        // Relay the original RFC822 message verbatim through the outbound
        // transport, overriding only the envelope recipient.
        $transport = Mail::mailer()->getSymfonyTransport();

        $envelope = new Envelope(
            new Address(config('mail.from.address', 'sandbox@localhost')),
            [new Address($this->to)],
        );

        $transport->send(new RawMessage($raw), $envelope);
    }
}
