<?php

namespace Sendtrap\Core\Tests\Feature;

use PHPUnit\Framework\TestCase;
use Sendtrap\Core\Support\LinkExtractor;

/**
 * Unit-level edge cases for the tolerant (DOM-based) link discovery:
 * malformed markup, duplicate links, anchor-text capture, and base-href
 * resolution rules. Pure functions of an HTML string — no app boot needed.
 */
class LinkExtractorUnitTest extends TestCase
{
    public function test_extract_preserves_the_flat_url_list_shape(): void
    {
        $html = '<a href="https://a.example/one">One</a> <a href="https://a.example/two">Two</a>';

        $this->assertSame(['https://a.example/one', 'https://a.example/two'], LinkExtractor::extract($html));
    }

    public function test_malformed_html_is_parsed_tolerantly(): void
    {
        // Unclosed tags, stray brackets, attribute soup — libxml recovers.
        $html = '<div><p>Hello <a href="https://a.example/verify?x=1">Verify<br>'
            .'<td><a href=https://a.example/other data-x=">">other</div>';

        $urls = LinkExtractor::extract($html);

        $this->assertContains('https://a.example/verify?x=1', $urls);
        $this->assertContains('https://a.example/other', $urls);
    }

    public function test_duplicate_links_are_deduped_keeping_first_text(): void
    {
        $html = '<a href="https://a.example/go">First</a><a href="https://a.example/go">Second</a>';

        $detailed = LinkExtractor::detailed($html);

        $this->assertCount(1, $detailed);
        $this->assertSame('First', $detailed[0]['text']);
    }

    public function test_mailto_tel_and_fragment_anchors_are_excluded(): void
    {
        $html = '<a href="mailto:x@example.com">m</a><a href="tel:+123">t</a>'
            .'<a href="#top">f</a><a href="https://a.example/">ok</a>';

        $this->assertSame(['https://a.example/'], LinkExtractor::extract($html));
    }

    public function test_area_elements_count_as_links(): void
    {
        $html = '<map><area href="https://a.example/map" alt="zone"></map>';

        $this->assertSame(['https://a.example/map'], LinkExtractor::extract($html));
    }

    public function test_anchor_text_is_collapsed_and_captured(): void
    {
        $html = "<a href=\"https://a.example/v\">\n  Verify\n  <b>your</b>\n  account\n</a>";

        $this->assertSame('Verify your account', LinkExtractor::detailed($html)[0]['text']);
    }

    public function test_relative_links_stay_relative_without_a_base(): void
    {
        $this->assertSame(['/verify?code=1'], LinkExtractor::extract('<a href="/verify?code=1">v</a>'));
    }

    public function test_a_valid_absolute_base_resolves_relative_links(): void
    {
        $html = '<head><base href="https://mail.example.com/campaign/x.html"></head>'
            .'<body>'
            .'<a href="/abs">a</a>'
            .'<a href="rel/path">r</a>'
            .'<a href="//cdn.example.com/p">p</a>'
            .'<a href="?q=1">q</a>'
            .'<a href="https://other.example/full">f</a>'
            .'</body>';

        $this->assertSame([
            'https://mail.example.com/abs',
            'https://mail.example.com/campaign/rel/path',
            'https://cdn.example.com/p',
            'https://mail.example.com/campaign/x.html?q=1',
            'https://other.example/full',
        ], LinkExtractor::extract($html));
    }

    public function test_a_relative_or_non_http_base_is_ignored(): void
    {
        foreach (['/just/a/path', 'ftp://files.example.com/', 'javascript:void(0)'] as $base) {
            $html = "<base href=\"{$base}\"><a href=\"/verify\">v</a>";

            $this->assertSame(['/verify'], LinkExtractor::extract($html), "base: {$base}");
        }
    }

    public function test_non_ascii_bodies_survive_the_parse(): void
    {
        $html = '<p>Håll utkik – クリック <a href="https://a.example/確認">確認する</a></p>';

        $detailed = LinkExtractor::detailed($html);

        $this->assertSame('https://a.example/確認', $detailed[0]['url']);
        $this->assertSame('確認する', $detailed[0]['text']);
    }

    public function test_empty_and_null_input_yield_no_links(): void
    {
        $this->assertSame([], LinkExtractor::extract(null));
        $this->assertSame([], LinkExtractor::extract(''));
        $this->assertSame([], LinkExtractor::extract('   '));
    }
}
