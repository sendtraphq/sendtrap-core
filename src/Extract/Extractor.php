<?php

namespace Sendtrap\Core\Extract;

use InvalidArgumentException;
use Sendtrap\Core\Models\Message;

/**
 * One validated, named extractor. Construction (fromArray) is the only
 * place request data is interpreted — unknown types, unknown option keys
 * and malformed options are rejected with a user-facing message before any
 * message is parsed. Subclasses implement one extractor type each and run
 * against a single message, never fetching anything remote.
 */
abstract class Extractor
{
    public const SELECTS = ['first', 'last', 'all'];

    /** Per-type option keys — overridden by every subclass. */
    public const KEYS = [];

    protected const COMMON_KEYS = ['type', 'select', 'optional'];

    protected const CONTEXT_RADIUS = 60;

    protected function __construct(
        public readonly ?string $select,
        public readonly bool $optional,
    ) {}

    abstract public function run(Message $message): ExtractionResult;

    /**
     * @param  array<string, mixed>  $raw
     */
    abstract protected static function make(string $name, array $raw, ?string $select, bool $optional): static;

    /**
     * @param  array<string, mixed>  $raw
     *
     * @throws InvalidArgumentException with a user-facing message
     */
    public static function fromArray(string $name, array $raw): self
    {
        $type = $raw['type'] ?? null;

        $class = match ($type) {
            'regex' => RegexExtractor::class,
            'code' => CodeExtractor::class,
            'link' => LinkExtractor::class,
            'address' => AddressExtractor::class,
            'attachment' => AttachmentExtractor::class,
            default => throw new InvalidArgumentException(
                "Extractor \"{$name}\" has unknown type \"".(is_string($type) ? $type : gettype($type))
                .'" — allowed: regex, code, link, address, attachment.'
            ),
        };

        $unknown = array_diff(array_keys($raw), array_merge(self::COMMON_KEYS, $class::KEYS));

        if ($unknown !== []) {
            throw new InvalidArgumentException(
                "Extractor \"{$name}\" has unknown option \"".implode('", "', $unknown)
                ."\" for type \"{$type}\" — allowed: ".implode(', ', $class::KEYS).'.'
            );
        }

        $select = $raw['select'] ?? null;

        if ($select !== null && ! in_array($select, self::SELECTS, true)) {
            throw new InvalidArgumentException(
                "Extractor \"{$name}\": \"select\" must be one of first, last, all."
            );
        }

        $optional = $raw['optional'] ?? false;

        if (! is_bool($optional)) {
            throw new InvalidArgumentException("Extractor \"{$name}\": \"optional\" must be a boolean.");
        }

        return $class::make($name, $raw, $select, $optional);
    }

    /**
     * Turn a deduplicated, document-ordered candidate list into a result.
     * Without an explicit `select`, more than one distinct value is
     * `ambiguous` — never a silent guess.
     *
     * @param  list<array{value: mixed, context: ?string}>  $candidates
     */
    protected function outcome(array $candidates, string $source): ExtractionResult
    {
        $count = count($candidates);

        if ($count === 0) {
            return ExtractionResult::notFound($source);
        }

        if ($this->select === 'all') {
            return ExtractionResult::found(array_column($candidates, 'value'), $count, $source, null);
        }

        if ($this->select === 'first' || ($this->select === null && $count === 1)) {
            return ExtractionResult::found($candidates[0]['value'], $count, $source, $candidates[0]['context']);
        }

        if ($this->select === 'last') {
            $last = $candidates[$count - 1];

            return ExtractionResult::found($last['value'], $count, $source, $last['context']);
        }

        return ExtractionResult::ambiguous($source, $count, array_map(
            fn ($candidate) => is_string($candidate['value'])
                ? mb_strimwidth($candidate['value'], 0, 200, '…')
                : $candidate['value'],
            array_slice($candidates, 0, 5),
        ));
    }

    /**
     * Keep the first occurrence of each distinct value, preserving order.
     *
     * @param  list<array{value: mixed, context: ?string}>  $candidates
     * @return list<array{value: mixed, context: ?string}>
     */
    protected function dedupe(array $candidates): array
    {
        $seen = [];
        $unique = [];

        foreach ($candidates as $candidate) {
            $key = json_encode($candidate['value']);

            if (isset($seen[$key])) {
                continue;
            }

            $seen[$key] = true;
            $unique[] = $candidate;
        }

        return $unique;
    }

    /**
     * A bounded, UTF-8-clean excerpt around a byte-offset match — enough to
     * see the surroundings, never a whole body.
     */
    protected function snippet(string $subject, int $offset, int $length): string
    {
        $start = max(0, $offset - self::CONTEXT_RADIUS);
        $raw = substr($subject, $start, $length + 2 * self::CONTEXT_RADIUS);

        // Trim any multi-byte sequences the byte window split in half.
        $clean = (string) preg_replace('/^[\x80-\xBF]+|[\xC0-\xFF][\x80-\xBF]*$/', '', $raw);

        return trim((string) preg_replace('/\s+/u', ' ', $clean));
    }

    /**
     * Required non-empty string option, capped at 1 KiB (the same value cap
     * /expect conditions enforce).
     */
    protected static function stringOption(string $name, array $raw, string $key, bool $required = false): ?string
    {
        $value = $raw[$key] ?? null;

        if ($value === null) {
            if ($required) {
                throw new InvalidArgumentException("Extractor \"{$name}\": \"{$key}\" is required.");
            }

            return null;
        }

        if (! is_string($value) || $value === '' || strlen($value) > 1024) {
            throw new InvalidArgumentException(
                "Extractor \"{$name}\": \"{$key}\" must be a non-empty string of at most 1024 bytes."
            );
        }

        return $value;
    }
}
