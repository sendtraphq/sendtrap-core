<?php

namespace Sendtrap\Core\Support;

use DOMDocument;
use DOMElement;
use DOMXPath;

/**
 * Pulls links out of an HTML body with a tolerant DOM parse (libxml recovers
 * from malformed markup), falling back to the previous regex scan only when
 * the document cannot be parsed at all. Relative URLs are resolved against a
 * `<base href>` when the document declares a valid absolute http(s) one, and
 * are otherwise returned as-is — nothing is ever fetched, and no base is
 * ever guessed.
 */
class LinkExtractor
{
    private const HREF_PATTERN = '/href\s*=\s*(["\'])(.*?)\1/i';

    private const MAX_TEXT_LENGTH = 200;

    /**
     * Unique link URLs, excluding mailto:/tel:/#-only anchors — the shape
     * the message API has always returned.
     *
     * @return list<string>
     */
    public static function extract(?string $html): array
    {
        return array_values(array_unique(array_column(self::detailed($html), 'url')));
    }

    /**
     * Links with their visible anchor text, in document order, deduped by
     * URL (first occurrence keeps its text).
     *
     * @return list<array{url: string, text: string}>
     */
    public static function detailed(?string $html): array
    {
        if ($html === null || trim($html) === '') {
            return [];
        }

        $document = new DOMDocument;
        $flags = LIBXML_NOERROR | LIBXML_NOWARNING | LIBXML_NONET;

        // The encoding prolog stops libxml misreading UTF-8 as Latin-1;
        // stripping any real XML declaration first avoids a double prolog.
        $prepared = '<?xml encoding="UTF-8">'.preg_replace('/^\s*<\?xml[^>]*\?>/i', '', $html);

        if (! @$document->loadHTML($prepared, $flags)) {
            // Unparseable even for libxml's recovering parser — fall back to
            // the legacy regex scan (no anchor text available on this path).
            return array_map(
                fn (string $url) => ['url' => $url, 'text' => ''],
                self::regexExtract($html),
            );
        }

        $xpath = new DOMXPath($document);
        $base = self::baseUrl($xpath);

        $links = [];

        /** @var DOMElement $node */
        foreach ($xpath->query('//a[@href] | //area[@href]') as $node) {
            $href = trim($node->getAttribute('href'));

            if (! self::isNavigable($href)) {
                continue;
            }

            $url = self::resolve($href, $base);

            if (array_key_exists($url, $links)) {
                continue;
            }

            $text = trim(preg_replace('/\s+/u', ' ', $node->textContent) ?? '');

            $links[$url] = [
                'url' => $url,
                'text' => mb_strimwidth($text, 0, self::MAX_TEXT_LENGTH, '…'),
            ];
        }

        return array_values($links);
    }

    /**
     * @return list<string>
     */
    private static function regexExtract(string $html): array
    {
        preg_match_all(self::HREF_PATTERN, $html, $matches);

        return collect($matches[2])
            ->map(fn ($href) => trim($href))
            ->filter(fn ($href) => self::isNavigable($href))
            ->unique()
            ->values()
            ->all();
    }

    private static function isNavigable(string $href): bool
    {
        return $href !== ''
            && ! str_starts_with($href, '#')
            && ! str_starts_with(strtolower($href), 'mailto:')
            && ! str_starts_with(strtolower($href), 'tel:');
    }

    /**
     * The document's declared base URL, only when it is an explicit,
     * absolute http(s) URL — anything else means "do not resolve".
     */
    private static function baseUrl(DOMXPath $xpath): ?string
    {
        $node = $xpath->query('//base[@href]')->item(0);

        if (! $node instanceof DOMElement) {
            return null;
        }

        $href = trim($node->getAttribute('href'));
        $parts = parse_url($href);

        if ($parts === false || ! in_array(strtolower($parts['scheme'] ?? ''), ['http', 'https'], true) || empty($parts['host'])) {
            return null;
        }

        return $href;
    }

    /**
     * Minimal RFC 3986 reference resolution — enough for the base-href
     * cases email HTML actually produces. With no valid base, relative
     * URLs pass through untouched.
     */
    private static function resolve(string $href, ?string $base): string
    {
        if ($base === null || parse_url($href, PHP_URL_SCHEME) !== null) {
            return $href;
        }

        $parts = parse_url($base);
        $origin = strtolower($parts['scheme']).'://'.$parts['host'].(isset($parts['port']) ? ':'.$parts['port'] : '');

        if (str_starts_with($href, '//')) {
            return strtolower($parts['scheme']).':'.$href;
        }

        if (str_starts_with($href, '/')) {
            return $origin.$href;
        }

        if (str_starts_with($href, '?') || str_starts_with($href, '#')) {
            return $origin.($parts['path'] ?? '/').$href;
        }

        $directory = preg_replace('~[^/]*$~', '', $parts['path'] ?? '/', 1);

        return $origin.($directory === '' ? '/' : $directory).$href;
    }
}
