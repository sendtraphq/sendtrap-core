<?php

namespace Sendtrap\Core\Support;

use Sendtrap\Core\Models\Message;

/**
 * Cheap, synchronous hygiene checks against an already-parsed message —
 * every check here reads data that's already stored or already parsed from
 * the raw MIME, so this is computed on read rather than persisted.
 *
 * Deliberately excluded: broken-image-URL checking. That requires fetching
 * remote URLs referenced in the HTML at read time, which is an SSRF and
 * latency risk not worth taking on for a lint report.
 */
class MessageLinter
{
    /**
     * @return list<array{key: string, passed: bool, severity: string}>
     */
    public static function lint(Message $message): array
    {
        return [
            [
                'key' => 'missing_text_part',
                'passed' => $message->has_text,
                'severity' => 'warn',
            ],
            [
                'key' => 'oversized_html',
                'passed' => $message->size <= config('sendtrap.lint.max_html_bytes'),
                'severity' => 'warn',
            ],
            [
                'key' => 'missing_list_unsubscribe',
                'passed' => collect($message->headerLines())->contains(
                    fn ($h) => strcasecmp($h['name'], 'List-Unsubscribe') === 0
                ),
                'severity' => 'info',
            ],
            [
                'key' => 'from_address_present',
                'passed' => $message->from_address !== null
                    && filter_var($message->from_address, FILTER_VALIDATE_EMAIL) !== false,
                'severity' => 'error',
            ],
        ];
    }
}
