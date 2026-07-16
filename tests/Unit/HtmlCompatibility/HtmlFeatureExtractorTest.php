<?php

namespace Sendtrap\Core\Tests\Unit\HtmlCompatibility;

use PHPUnit\Framework\TestCase;
use Sendtrap\Core\Support\HtmlCompatibility\HtmlFeatureExtractor;

class HtmlFeatureExtractorTest extends TestCase
{
    protected function tokens(array $extracted, string $type, string $name): ?array
    {
        foreach ($extracted as $token) {
            if ($token['type'] === $type && $token['name'] === $name) {
                return $token;
            }
        }

        return null;
    }

    public function test_it_extracts_tags_and_attributes(): void
    {
        $html = '<div class="wrap" width="100"><img src="x.png" srcset="x2.png 2x"></div>';

        $extracted = HtmlFeatureExtractor::extract($html);

        $this->assertNotNull($this->tokens($extracted, 'html-tag', 'div'));
        $this->assertNotNull($this->tokens($extracted, 'html-tag', 'img'));
        $this->assertNotNull($this->tokens($extracted, 'html-attribute', 'width'));
        $this->assertNotNull($this->tokens($extracted, 'html-attribute', 'srcset'));
        $this->assertNull($this->tokens($extracted, 'html-attribute', 'class'));
    }

    public function test_it_extracts_properties_from_a_style_block(): void
    {
        $html = '<html><head><style>.wrap { display: flex; border-radius: 8px; }</style></head><body></body></html>';

        $extracted = HtmlFeatureExtractor::extract($html);

        $this->assertNotNull($this->tokens($extracted, 'css-property', 'display'));
        $this->assertNotNull($this->tokens($extracted, 'css-property', 'border-radius'));
    }

    public function test_it_extracts_properties_from_an_inline_style_attribute(): void
    {
        $html = '<div style="display:flex;gap:10px;"></div>';

        $extracted = HtmlFeatureExtractor::extract($html);

        $this->assertNotNull($this->tokens($extracted, 'css-property', 'display'));
        $this->assertNotNull($this->tokens($extracted, 'css-property', 'gap'));
    }

    public function test_it_extracts_at_rules(): void
    {
        $html = '<style>@media (prefers-color-scheme: dark) { body { color: #fff; } }</style>';

        $extracted = HtmlFeatureExtractor::extract($html);

        $this->assertNotNull($this->tokens($extracted, 'css-at-rule', 'media'));
    }

    public function test_it_counts_repeated_tokens(): void
    {
        $html = '<p>a</p><p>b</p><p>c</p>';

        $extracted = HtmlFeatureExtractor::extract($html);

        $this->assertSame(3, $this->tokens($extracted, 'html-tag', 'p')['count']);
    }

    public function test_it_returns_empty_for_no_html(): void
    {
        $this->assertSame([], HtmlFeatureExtractor::extract(''));
    }

    public function test_it_ignores_malformed_inline_css_without_failing(): void
    {
        $html = '<div style="display: ; not-css !!! ">text</div>';

        $extracted = HtmlFeatureExtractor::extract($html);

        $this->assertNotNull($this->tokens($extracted, 'html-tag', 'div'));
    }
}
