<?php

namespace Sendtrap\Core\Expect;

use InvalidArgumentException;
use Sendtrap\Core\Models\Message;
use Sendtrap\Core\Support\MessageLinter;

/**
 * A parsed condition field reference. Most fields are bare names
 * (`subject`, `links`); two families are parameterized (`header.<Name>`,
 * `checks.<key>`) and two are dotted attributes (`from.address`,
 * `attachments.count`). Each field carries a value type that constrains
 * which operators apply:
 *
 *   string   subject, from.address, from.name, text, html, test_id, message_id, header.<Name>
 *   list     to, cc, envelope_to, links, attachments.filename, attachments.content_type
 *   number   size, attachments.count
 *   bool     is_read, has_unresolved_merge_tags, checks.<key>
 *
 * List fields evaluate with any-semantics: the condition passes if any
 * element satisfies the operator.
 */
final class FieldRef
{
    private const TYPES = [
        'subject' => 'string',
        'from.address' => 'string',
        'from.name' => 'string',
        'text' => 'string',
        'html' => 'string',
        'test_id' => 'string',
        'message_id' => 'string',
        'to' => 'list',
        'cc' => 'list',
        'envelope_to' => 'list',
        'links' => 'list',
        'attachments.filename' => 'list',
        'attachments.content_type' => 'list',
        'size' => 'number',
        'attachments.count' => 'number',
        'is_read' => 'bool',
        'has_unresolved_merge_tags' => 'bool',
    ];

    private function __construct(
        public readonly string $raw,
        public readonly string $base,
        public readonly ?string $param,
        public readonly string $type,
    ) {}

    public static function parse(string $raw): self
    {
        if (isset(self::TYPES[$raw])) {
            return new self($raw, $raw, null, self::TYPES[$raw]);
        }

        if (preg_match('/^header\.([A-Za-z0-9-]{1,64})$/', $raw, $m)) {
            return new self($raw, 'header', $m[1], 'list');
        }

        if (preg_match('/^checks\.([a-z0-9_]{1,64})$/', $raw, $m)) {
            return new self($raw, 'checks', $m[1], 'bool');
        }

        throw new InvalidArgumentException("Unknown field \"{$raw}\".");
    }

    public static function isValid(string $raw): bool
    {
        try {
            self::parse($raw);

            return true;
        } catch (InvalidArgumentException) {
            return false;
        }
    }

    /**
     * Extract this field's value from a message. Strings return ?string,
     * lists return list<string>, numbers int, bools ?bool (null = the
     * referenced check does not exist on this message).
     */
    public function extract(Message $message): mixed
    {
        return match ($this->base) {
            'subject' => $message->subject,
            'from.address' => $message->from_address,
            'from.name' => $message->from_name,
            'text' => $message->textBody(),
            'html' => $message->htmlBody(),
            'test_id' => $message->test_id,
            'message_id' => $message->message_id,
            'to' => array_values(array_filter(array_column($message->to ?? [], 'address'))),
            'cc' => array_values(array_filter(array_column($message->cc ?? [], 'address'))),
            'envelope_to' => $message->envelope_to ?? [],
            'links' => $message->links(),
            'attachments.filename' => $message->attachments->pluck('filename')->filter()->values()->all(),
            'attachments.content_type' => $message->attachments->pluck('content_type')->filter()->values()->all(),
            'size' => (int) $message->size,
            'attachments.count' => $message->attachments->count(),
            'is_read' => (bool) $message->is_read,
            'has_unresolved_merge_tags' => (bool) $message->has_unresolved_merge_tags,
            'header' => collect($message->headerLines())
                ->filter(fn ($h) => strcasecmp($h['name'], $this->param) === 0)
                ->pluck('value')
                ->values()
                ->all(),
            'checks' => collect(MessageLinter::lint($message))
                ->firstWhere('key', $this->param)['passed'] ?? null,
        };
    }
}
