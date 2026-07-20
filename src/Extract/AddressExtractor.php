<?php

namespace Sendtrap\Core\Extract;

use InvalidArgumentException;
use Sendtrap\Core\Models\Message;
use Sendtrap\Core\Support\SafeRegex;

/**
 * `type: address` — pull an address (with display name where the field
 * carries one) from the from/to/cc header fields or the SMTP envelope.
 * Envelope fields matter for BCC-style flows, where the recipient only
 * appears in `envelope_to`.
 */
final class AddressExtractor extends Extractor
{
    public const KEYS = ['field', 'matches'];

    public const FIELDS = ['from', 'to', 'cc', 'envelope_from', 'envelope_to'];

    private function __construct(
        public readonly string $field,
        public readonly ?string $matches,
        ?string $select,
        bool $optional,
    ) {
        parent::__construct($select, $optional);
    }

    protected static function make(string $name, array $raw, ?string $select, bool $optional): static
    {
        $field = $raw['field'] ?? null;

        if (! is_string($field) || ! in_array($field, self::FIELDS, true)) {
            throw new InvalidArgumentException(
                "Extractor \"{$name}\": \"field\" must be one of ".implode(', ', self::FIELDS).'.'
            );
        }

        $matches = self::stringOption($name, $raw, 'matches');

        if ($matches !== null) {
            SafeRegex::validate($matches);
        }

        return new self($field, $matches, $select, $optional);
    }

    public function run(Message $message): ExtractionResult
    {
        $entries = match ($this->field) {
            'from' => $message->from_address !== null
                ? [['address' => $message->from_address, 'name' => $message->from_name]]
                : [],
            'to' => $message->to ?? [],
            'cc' => $message->cc ?? [],
            'envelope_from' => $message->envelope_from !== null
                ? [['address' => $message->envelope_from, 'name' => null]]
                : [],
            'envelope_to' => array_map(
                fn ($address) => ['address' => $address, 'name' => null],
                $message->envelope_to ?? [],
            ),
        };

        $candidates = [];

        foreach ($entries as $entry) {
            $address = $entry['address'] ?? null;

            if (! is_string($address) || $address === '') {
                continue;
            }

            if ($this->matches !== null && ! @preg_match(SafeRegex::delimit($this->matches), $address)) {
                continue;
            }

            $candidates[] = [
                'value' => ['address' => $address, 'name' => $entry['name'] ?? null],
                'context' => null,
            ];
        }

        return $this->outcome($this->dedupe($candidates), $this->field);
    }
}
