<?php

namespace Sendtrap\Core\Support;

use InvalidArgumentException;

/**
 * The one place user-supplied regex patterns become executable expressions.
 * Patterns are treated as bodies and delimited server-side — user input
 * never chooses delimiters or modifiers — and are length-capped before
 * compilation. Shared by /expect conditions and the extraction engine so
 * both surfaces enforce identical limits.
 */
final class SafeRegex
{
    public const MAX_PATTERN_LENGTH = 256;

    public static function delimit(string $pattern): string
    {
        return '~'.str_replace('~', '\~', $pattern).'~u';
    }

    /**
     * @throws InvalidArgumentException with a user-facing message
     */
    public static function validate(string $pattern): void
    {
        if (strlen($pattern) > self::MAX_PATTERN_LENGTH) {
            throw new InvalidArgumentException('Regex patterns are capped at '.self::MAX_PATTERN_LENGTH.' bytes.');
        }

        if (@preg_match(self::delimit($pattern), '') === false) {
            throw new InvalidArgumentException("\"{$pattern}\" is not a valid regular expression.");
        }
    }
}
