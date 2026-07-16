<?php

namespace Sendtrap\Core\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Sendtrap\Core\Models\Message;
use Sendtrap\Core\Support\IpAllowList;

/**
 * POSTs a signed JSON payload to the inbox webhook_url when a message arrives.
 */
class DeliverWebhook implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;

    public array $backoff = [10, 60, 300];

    public function __construct(public int $messageId) {}

    public function handle(): void
    {
        $message = Message::with('inbox')->find($this->messageId);

        if (! $message || ! $message->inbox?->webhook_url) {
            return;
        }

        $inbox = $message->inbox;

        // Resolve at send-time (not just save-time) since DNS can change
        // between when the URL was saved and when this job runs.
        $host = parse_url($inbox->webhook_url, PHP_URL_HOST);
        $isIp = $host && filter_var($host, FILTER_VALIDATE_IP);
        $ip = $isIp ? $host : ($host ? gethostbyname($host) : false);
        $resolved = $ip !== false && ($isIp || $ip !== $host);

        if (! $resolved || IpAllowList::isReservedOrPrivate($ip)) {
            Log::warning('webhook.blocked: refusing to deliver to reserved/unresolvable host', [
                'inbox_id' => $inbox->id,
                'webhook_url' => $inbox->webhook_url,
            ]);

            return;
        }

        $payload = [
            'event' => 'message.received',
            'inbox_id' => $inbox->id,
            'message' => [
                'id' => $message->id,
                'message_id' => $message->message_id,
                'from' => ['address' => $message->from_address, 'name' => $message->from_name],
                'to' => $message->to,
                'cc' => $message->cc,
                'subject' => $message->subject,
                'size' => $message->size,
                'has_attachments' => $message->has_attachments,
                'received_at' => $message->received_at?->toIso8601String(),
            ],
        ];

        $body = json_encode($payload);
        $signature = hash_hmac('sha256', $body, $inbox->webhook_secret ?? '');

        // Pin the connection to the IP we just validated — otherwise the
        // HTTP client re-resolves the hostname independently at connect
        // time, and an attacker-controlled DNS name can return a public
        // address here and a private/internal one a moment later (DNS
        // rebinding), bypassing the check above entirely. The Host
        // header/TLS SNI still use the original hostname.
        $port = parse_url($inbox->webhook_url, PHP_URL_PORT)
            ?: (str_starts_with($inbox->webhook_url, 'https') ? 443 : 80);

        Http::withHeaders([
            'Content-Type' => 'application/json',
            'X-Sendtrap-Signature' => $signature,
        ])->withOptions([
            'allow_redirects' => false,
            'curl' => [CURLOPT_RESOLVE => ["{$host}:{$port}:{$ip}"]],
        ])
            ->withBody($body, 'application/json')
            ->post($inbox->webhook_url)
            ->throw();
    }
}
