<?php

namespace Sendtrap\Core\Extract;

use InvalidArgumentException;
use Sendtrap\Core\Models\Message;

/**
 * `type: code` — the verification-code helper. Finds standalone tokens of a
 * configured length and character class in the message's visible text
 * (never inside longer runs, so a code is not fished out of an id or URL
 * hash), optionally only near an anchor phrase like "verification code".
 * HTML sources are searched as visible text (tags stripped, entities
 * decoded) — codes live in prose, not markup.
 */
final class CodeExtractor extends Extractor
{
    public const KEYS = ['length', 'charset', 'near', 'from'];

    public const SOURCES = ['auto', 'text', 'html', 'subject'];

    private const CHARSETS = [
        'digits' => '0-9',
        'letters' => 'A-Za-z',
        'upper' => 'A-Z',
        'alphanumeric' => 'A-Za-z0-9',
    ];

    /** Furthest a token may sit from a `near` anchor occurrence, in bytes. */
    private const NEAR_MAX_DISTANCE = 160;

    private function __construct(
        public readonly int $length,
        public readonly string $charset,
        public readonly ?string $near,
        public readonly string $from,
        ?string $select,
        bool $optional,
    ) {
        parent::__construct($select, $optional);
    }

    protected static function make(string $name, array $raw, ?string $select, bool $optional): static
    {
        $length = $raw['length'] ?? 6;

        if (! is_int($length) || $length < 4 || $length > 12) {
            throw new InvalidArgumentException("Extractor \"{$name}\": \"length\" must be an integer between 4 and 12.");
        }

        $charset = $raw['charset'] ?? 'digits';

        if (! is_string($charset) || ! isset(self::CHARSETS[$charset])) {
            throw new InvalidArgumentException(
                "Extractor \"{$name}\": \"charset\" must be one of ".implode(', ', array_keys(self::CHARSETS)).'.'
            );
        }

        $near = self::stringOption($name, $raw, 'near');

        if ($near !== null && strlen($near) > 128) {
            throw new InvalidArgumentException("Extractor \"{$name}\": \"near\" is capped at 128 bytes.");
        }

        $from = $raw['from'] ?? 'auto';

        if (! is_string($from) || ! in_array($from, self::SOURCES, true)) {
            throw new InvalidArgumentException(
                "Extractor \"{$name}\": \"from\" must be one of ".implode(', ', self::SOURCES).'.'
            );
        }

        return new self($length, $charset, $near, $from, $select, $optional);
    }

    public function run(Message $message): ExtractionResult
    {
        [$subject, $source] = $this->subject($message);

        if ($subject === null || $subject === '') {
            return ExtractionResult::notFound($source);
        }

        $class = self::CHARSETS[$this->charset];
        preg_match_all("/(?<![A-Za-z0-9])[{$class}]{{$this->length}}(?![A-Za-z0-9])/", $subject, $matches, PREG_OFFSET_CAPTURE);

        $candidates = [];

        foreach ($matches[0] as [$code, $offset]) {
            $entry = [
                'value' => $code,
                'context' => $this->snippet($subject, $offset, strlen($code)),
            ];

            if ($this->near !== null) {
                $distance = $this->distanceToAnchor($subject, $offset, strlen($code));

                if ($distance === null || $distance > self::NEAR_MAX_DISTANCE) {
                    continue;
                }

                $entry['distance'] = $distance;
            }

            $candidates[] = $entry;
        }

        if ($this->near !== null) {
            // `near` means nearest: order by distance to the anchor phrase,
            // and when the closest token is strictly nearer than every other
            // distinct value, it wins. Distinct values at an equal distance
            // stay ambiguous — that would be a guess.
            usort($candidates, fn ($a, $b) => $a['distance'] <=> $b['distance']);
            $candidates = $this->dedupe($candidates);

            if ($this->select === null && count($candidates) > 1
                && $candidates[0]['distance'] < $candidates[1]['distance']) {
                $candidates = [$candidates[0]];
            }
        } else {
            $candidates = $this->dedupe($candidates);
        }

        return $this->outcome(
            array_map(fn ($c) => ['value' => $c['value'], 'context' => $c['context']], $candidates),
            $source,
        );
    }

    /**
     * Byte distance from a token to the closest case-insensitive occurrence
     * of the anchor phrase, or null when the anchor never occurs.
     */
    private function distanceToAnchor(string $subject, int $offset, int $length): ?int
    {
        $best = null;
        $search = 0;

        while (($position = stripos($subject, $this->near, $search)) !== false) {
            $anchorEnd = $position + strlen($this->near);

            $distance = match (true) {
                $offset >= $anchorEnd => $offset - $anchorEnd,
                $offset + $length <= $position => $position - ($offset + $length),
                default => 0,
            };

            $best = $best === null ? $distance : min($best, $distance);
            $search = $anchorEnd;
        }

        return $best;
    }

    /**
     * @return array{0: ?string, 1: string}
     */
    private function subject(Message $message): array
    {
        return match ($this->from) {
            'subject' => [$message->subject, 'subject'],
            'text' => [$message->textBody(), 'text'],
            'html' => [self::visibleText($message->htmlBody()), 'html'],
            'auto' => ($text = $message->textBody()) !== null && trim($text) !== ''
                ? [$text, 'text']
                : [self::visibleText($message->htmlBody()), 'html'],
        };
    }

    private static function visibleText(?string $html): ?string
    {
        if ($html === null || $html === '') {
            return null;
        }

        $text = (string) preg_replace('/<(script|style)\b[^>]*+>.*?<\/\1\s*+>/is', ' ', $html);
        $text = (string) preg_replace('/<[^>]*+>/', ' ', $text);
        $text = html_entity_decode($text, ENT_QUOTES | ENT_HTML5, 'UTF-8');

        return trim((string) preg_replace('/\s+/u', ' ', $text));
    }
}
