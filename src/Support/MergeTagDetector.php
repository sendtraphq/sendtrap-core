<?php

namespace Sendtrap\Core\Support;

/**
 * Heuristic detector for unresolved template placeholders ({{ tag }} /
 * %tag%) left in a rendered email body — a class of bug that renders and
 * sends fine, so nothing else in the pipeline would ever catch it.
 *
 * This is pattern matching, not a template-engine parser: it will miss
 * unconventional delimiters and can false-positive on content that merely
 * looks like a tag. The %tag% pattern in particular requires an
 * alphabetic-led identifier to avoid tripping on ordinary percentages
 * ("50%", "width: 100%").
 */
class MergeTagDetector
{
    private const CURLY_PATTERN = '/\{\{\s*[\w.\-]+\s*\}\}/';

    private const PERCENT_PATTERN = '/%[a-zA-Z_][a-zA-Z0-9_\-]*%/';

    /**
     * @return array{has_unresolved_merge_tags: bool, unresolved_merge_tags: list<string>}
     */
    public static function detect(?string $html, ?string $text): array
    {
        $haystack = ($html ?? '').' '.($text ?? '');

        $tags = collect([self::CURLY_PATTERN, self::PERCENT_PATTERN])
            ->flatMap(function ($pattern) use ($haystack) {
                preg_match_all($pattern, $haystack, $matches);

                return $matches[0];
            })
            ->unique()
            ->values()
            ->all();

        return [
            'has_unresolved_merge_tags' => $tags !== [],
            'unresolved_merge_tags' => $tags,
        ];
    }
}
