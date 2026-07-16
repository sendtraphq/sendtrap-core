<?php

namespace Sendtrap\Core\Support;

/**
 * Pulls hrefs out of an HTML body via regex rather than a DOM parser — this
 * extracts from already-trusted, already-parsed email HTML (not third-party
 * markup being sanitized/rendered), so a DOM crawler's malformed-HTML
 * tolerance buys little here. Relative URLs are returned as-is (no base
 * href to resolve them against is guaranteed to exist).
 */
class LinkExtractor
{
    private const HREF_PATTERN = '/href\s*=\s*(["\'])(.*?)\1/i';

    /**
     * @return list<string>
     */
    public static function extract(?string $html): array
    {
        if ($html === null || $html === '') {
            return [];
        }

        preg_match_all(self::HREF_PATTERN, $html, $matches);

        return collect($matches[2])
            ->map(fn ($href) => trim($href))
            ->filter(fn ($href) => $href !== ''
                && ! str_starts_with($href, '#')
                && ! str_starts_with(strtolower($href), 'mailto:')
                && ! str_starts_with(strtolower($href), 'tel:'))
            ->unique()
            ->values()
            ->all();
    }
}
