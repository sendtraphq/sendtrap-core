<?php

namespace Sendtrap\Core\Extract;

use InvalidArgumentException;
use Sendtrap\Core\Models\Message;
use Sendtrap\Core\Support\SafeRegex;

/**
 * `type: regex` — capture a value with a bounded regular expression from
 * the text body, the HTML source, the subject, or a named header. When the
 * pattern has a capture group the first group's value is extracted,
 * otherwise the whole match.
 */
final class RegexExtractor extends Extractor
{
    public const KEYS = ['pattern', 'from'];

    public const SOURCES = ['text', 'html', 'subject'];

    private function __construct(
        public readonly string $pattern,
        public readonly string $from,
        ?string $select,
        bool $optional,
    ) {
        parent::__construct($select, $optional);
    }

    protected static function make(string $name, array $raw, ?string $select, bool $optional): static
    {
        $pattern = self::stringOption($name, $raw, 'pattern', required: true);
        SafeRegex::validate($pattern);

        $from = $raw['from'] ?? 'text';

        if (! is_string($from)
            || (! in_array($from, self::SOURCES, true) && ! preg_match('/^header\.[A-Za-z0-9-]{1,64}$/', $from))) {
            throw new InvalidArgumentException(
                "Extractor \"{$name}\": \"from\" must be one of text, html, subject, or header.<Name>."
            );
        }

        return new self($pattern, $from, $select, $optional);
    }

    public function run(Message $message): ExtractionResult
    {
        $subjects = match (true) {
            $this->from === 'text' => [$message->textBody()],
            $this->from === 'html' => [$message->htmlBody()],
            $this->from === 'subject' => [$message->subject],
            default => collect($message->headerLines())
                ->filter(fn ($h) => strcasecmp($h['name'], substr($this->from, strlen('header.'))) === 0)
                ->pluck('value')
                ->all(),
        };

        $candidates = [];

        foreach ($subjects as $subject) {
            if ($subject === null || $subject === '') {
                continue;
            }

            preg_match_all(SafeRegex::delimit($this->pattern), $subject, $matches, PREG_OFFSET_CAPTURE);

            foreach ($matches[0] as $i => [$whole, $offset]) {
                $candidates[] = [
                    'value' => isset($matches[1]) && $matches[1][$i][1] !== -1 ? $matches[1][$i][0] : $whole,
                    'context' => $this->snippet($subject, $offset, strlen($whole)),
                ];
            }
        }

        return $this->outcome($this->dedupe($candidates), $this->from);
    }
}
