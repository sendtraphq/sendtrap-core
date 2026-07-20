<?php

namespace Sendtrap\Core\Extract;

use InvalidArgumentException;
use Sendtrap\Core\Models\Attachment;
use Sendtrap\Core\Models\Message;
use Sendtrap\Core\Support\SafeRegex;

/**
 * `type: attachment` — select an attachment by filename or media type and
 * return its metadata plus the authenticated download URL. Bytes are never
 * inlined in the response; the URL points at the existing token-scoped
 * attachment route.
 */
final class AttachmentExtractor extends Extractor
{
    public const KEYS = ['filename', 'filename_contains', 'matches', 'content_type'];

    private function __construct(
        public readonly ?string $filename,
        public readonly ?string $filenameContains,
        public readonly ?string $matches,
        public readonly ?string $contentType,
        ?string $select,
        bool $optional,
    ) {
        parent::__construct($select, $optional);
    }

    protected static function make(string $name, array $raw, ?string $select, bool $optional): static
    {
        $matches = self::stringOption($name, $raw, 'matches');

        if ($matches !== null) {
            SafeRegex::validate($matches);
        }

        $contentType = self::stringOption($name, $raw, 'content_type');

        if ($contentType !== null && ! preg_match('~^[\w.+-]+/(\*|[\w.+-]+)$~', $contentType)) {
            throw new InvalidArgumentException(
                "Extractor \"{$name}\": \"content_type\" must be a media type, optionally with a `/*` wildcard subtype."
            );
        }

        return new self(
            filename: self::stringOption($name, $raw, 'filename'),
            filenameContains: self::stringOption($name, $raw, 'filename_contains'),
            matches: $matches,
            contentType: $contentType,
            select: $select,
            optional: $optional,
        );
    }

    public function run(Message $message): ExtractionResult
    {
        $candidates = [];

        foreach ($message->attachments as $attachment) {
            if (! $this->selects($attachment)) {
                continue;
            }

            $candidates[] = [
                'value' => [
                    'id' => $attachment->id,
                    'filename' => $attachment->filename,
                    'content_type' => $attachment->content_type,
                    'size' => $attachment->size,
                    'checksum' => $attachment->checksum,
                    'url' => route('api.messages.attachment', [$message, $attachment]),
                ],
                'context' => null,
            ];
        }

        return $this->outcome($candidates, 'attachments');
    }

    private function selects(Attachment $attachment): bool
    {
        $filename = $attachment->filename ?? '';

        if ($this->filename !== null && $filename !== $this->filename) {
            return false;
        }

        if ($this->filenameContains !== null && mb_stripos($filename, $this->filenameContains) === false) {
            return false;
        }

        if ($this->matches !== null && ! @preg_match(SafeRegex::delimit($this->matches), $filename)) {
            return false;
        }

        if ($this->contentType !== null && ! $this->typeMatches((string) $attachment->content_type)) {
            return false;
        }

        return true;
    }

    private function typeMatches(string $actual): bool
    {
        // Ignore any ";charset=..." parameters on the stored type.
        $actual = strtolower(trim(strtok($actual, ';')));

        if (str_ends_with($this->contentType, '/*')) {
            return str_starts_with($actual, strtolower(substr($this->contentType, 0, -1)));
        }

        return $actual === strtolower($this->contentType);
    }
}
