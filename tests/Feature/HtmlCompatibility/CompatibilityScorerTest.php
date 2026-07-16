<?php

namespace Sendtrap\Core\Tests\Feature\HtmlCompatibility;

use Sendtrap\Core\Support\HtmlCompatibility\CompatibilityScorer;
use Sendtrap\Core\Support\HtmlCompatibility\HtmlFeatureExtractor;
use Sendtrap\Core\Tests\PackageTestCase;

/**
 * Runs against the real vendored caniemail dataset — a package-local
 * snapshot at tests/Fixtures/caniemail-features.json, pre-warmed into the
 * cache by PackageTestCase (see its own docblock) rather than a small
 * synthetic fixture, since the mapping/scoring logic is only meaningful
 * against real feature slugs — these assert on known-stable, long-established
 * features rather than exact ratios, so a dataset refresh won't break them.
 */
class CompatibilityScorerTest extends PackageTestCase
{
    public function test_it_returns_a_perfect_ratio_for_no_extracted_features(): void
    {
        $result = CompatibilityScorer::score([]);

        $this->assertSame(100.0, $result['compatibility_ratio']);
        $this->assertSame([], $result['issues']);
    }

    public function test_it_ignores_tokens_with_no_matching_feature(): void
    {
        $result = CompatibilityScorer::score([
            ['type' => 'html-tag', 'name' => 'not-a-real-tag', 'count' => 1],
            ['type' => 'css-property', 'name' => 'not-a-real-property', 'count' => 1],
        ]);

        $this->assertSame(100.0, $result['compatibility_ratio']);
        $this->assertSame([], $result['issues']);
    }

    public function test_it_flags_flexbox_gap_as_an_issue_in_major_clients(): void
    {
        // display:flex + gap is well known to be unsupported in Outlook desktop.
        $result = CompatibilityScorer::score([
            ['type' => 'css-property', 'name' => 'gap', 'count' => 1],
        ]);

        $this->assertNotEmpty($result['issues']);
        $this->assertSame('css-gap', $result['issues'][0]['feature_id']);
        $this->assertContains($result['issues'][0]['severity'], ['error', 'warn']);
        $this->assertNotEmpty($result['issues'][0]['unsupported_clients']);
    }

    public function test_it_deduplicates_repeated_tokens_mapping_to_the_same_feature(): void
    {
        $result = CompatibilityScorer::score([
            ['type' => 'css-property', 'name' => 'gap', 'count' => 5],
            ['type' => 'css-property', 'name' => 'gap', 'count' => 3],
        ]);

        $this->assertCount(1, $result['issues']);
    }

    public function test_end_to_end_from_extracted_html(): void
    {
        $html = '<div style="display:flex;gap:10px;"><svg></svg></div>';

        $result = CompatibilityScorer::score(HtmlFeatureExtractor::extract($html));

        $featureIds = array_column($result['issues'], 'feature_id');
        $this->assertContains('css-gap', $featureIds);
        $this->assertContains('html-svg', $featureIds);
        $this->assertLessThan(100.0, $result['compatibility_ratio']);
    }
}
