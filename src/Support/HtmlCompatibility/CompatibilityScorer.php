<?php

namespace Sendtrap\Core\Support\HtmlCompatibility;

/**
 * Scores a set of extracted HTML/CSS tokens against a fixed, equal-weighted
 * reference client list (NOT market-share weighted — deliberately, since no
 * free/reliable market-share dataset exists; see HTML Check plan). The
 * resulting `compatibility_ratio` is a plain count: the percentage of
 * distinct detected features that are fully supported across every
 * reference client checked. It exists for filtering/CI gating, not as a
 * headline "market support" metric.
 */
class CompatibilityScorer
{
    /**
     * A fixed subset of caniemail's ~20 client families, covering the major
     * clients called out in the HTML Check UI copy plus a few more common
     * ones. Deliberately not market-share weighted.
     */
    protected const REFERENCE_CLIENTS = [
        ['family' => 'apple-mail', 'platform' => 'macos'],
        ['family' => 'apple-mail', 'platform' => 'ios'],
        ['family' => 'gmail', 'platform' => 'desktop-webmail'],
        ['family' => 'gmail', 'platform' => 'ios'],
        ['family' => 'gmail', 'platform' => 'android'],
        ['family' => 'outlook', 'platform' => 'windows'],
        ['family' => 'outlook', 'platform' => 'macos'],
        ['family' => 'outlook', 'platform' => 'outlook-com'],
        ['family' => 'outlook', 'platform' => 'ios'],
        ['family' => 'outlook', 'platform' => 'android'],
        ['family' => 'yahoo', 'platform' => 'desktop-webmail'],
        ['family' => 'yahoo', 'platform' => 'ios'],
        ['family' => 'samsung-email', 'platform' => 'android'],
        ['family' => 'thunderbird', 'platform' => 'macos'],
    ];

    /**
     * @param  list<array{type: string, name: string, count: int}>  $extractedFeatures
     * @return array{compatibility_ratio: float, issues: list<array>}
     */
    public static function score(array $extractedFeatures): array
    {
        $evaluated = [];
        $checked = 0;
        $fullySupported = 0;
        $issues = [];

        foreach ($extractedFeatures as $token) {
            $feature = static::mapToken($token);

            if ($feature === null || isset($evaluated[$feature['slug']])) {
                continue;
            }
            $evaluated[$feature['slug']] = true;

            $clientResults = [];
            foreach (self::REFERENCE_CLIENTS as $client) {
                $support = static::supportFor($feature, $client['family'], $client['platform']);

                if ($support !== null) {
                    $clientResults[] = array_merge($client, $support);
                }
            }

            if ($clientResults === []) {
                continue; // no reference data for this feature at all
            }

            $checked++;
            $supportedCount = count(array_filter($clientResults, fn ($c) => $c['support'] === 'y'));

            if ($supportedCount === count($clientResults)) {
                $fullySupported++;

                continue;
            }

            $ratio = $supportedCount / count($clientResults);

            $issues[] = [
                'feature_id' => $feature['slug'],
                'title' => $feature['title'],
                'category' => $feature['category'],
                'severity' => $ratio < 0.5 ? 'error' : 'warn',
                'unsupported_clients' => array_values(array_map(
                    fn ($c) => ['client' => $c['family'], 'platform' => $c['platform'], 'support' => $c['support'], 'note' => $c['note']],
                    array_filter($clientResults, fn ($c) => $c['support'] !== 'y'),
                )),
            ];
        }

        usort($issues, fn ($a, $b) => ($a['severity'] === $b['severity']) ? 0 : ($a['severity'] === 'error' ? -1 : 1));

        return [
            'compatibility_ratio' => $checked > 0 ? round(($fullySupported / $checked) * 100, 1) : 100.0,
            'issues' => $issues,
        ];
    }

    protected static function mapToken(array $token): ?array
    {
        return match ($token['type']) {
            'html-tag' => FeatureMap::forHtmlTag($token['name']),
            'html-attribute' => FeatureMap::forHtmlAttribute($token['name']),
            'css-property' => FeatureMap::forCssProperty($token['name']),
            'css-value' => FeatureMap::forCssValue($token['name']),
            'css-at-rule' => FeatureMap::forCssAtRule($token['name']),
            default => null,
        };
    }

    /** @return array{support: string, note: ?string}|null */
    protected static function supportFor(array $feature, string $family, string $platform): ?array
    {
        $versions = $feature['stats'][$family][$platform] ?? null;

        if (empty($versions)) {
            return null;
        }

        // Versions are inserted chronologically — the last one is current.
        $code = trim((string) $versions[array_key_last($versions)]);

        preg_match('/^([a-z])(?:\s+(#.*))?$/i', $code, $m);
        $letter = strtolower($m[1] ?? substr($code, 0, 1));

        if ($letter === 'u') {
            return null; // "support unknown" — treat as no usable data, not a failure
        }

        $note = null;
        if (! empty($m[2])) {
            preg_match_all('/#(\d+)/', $m[2], $numMatches);
            $texts = array_filter(array_map(
                fn ($num) => $feature['notes_by_num'][$num] ?? null,
                $numMatches[1],
            ));
            $note = $texts ? implode(' ', $texts) : null;
        }

        return ['support' => $letter, 'note' => $note];
    }
}
