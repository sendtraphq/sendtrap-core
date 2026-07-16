<?php

namespace Sendtrap\Core\Tests\Unit;

use PHPUnit\Framework\TestCase;
use Sendtrap\Core\Support\LinkExtractor;

class LinkExtractorTest extends TestCase
{
    public function test_it_extracts_multiple_links(): void
    {
        $html = '<a href="https://example.com/verify?token=abc">Verify</a>'
            .'<a href=\'https://example.com/unsubscribe\'>Unsubscribe</a>';

        $links = LinkExtractor::extract($html);

        $this->assertSame([
            'https://example.com/verify?token=abc',
            'https://example.com/unsubscribe',
        ], $links);
    }

    public function test_it_drops_mailto_tel_and_anchor_only_links(): void
    {
        $html = '<a href="mailto:test@example.com">Email</a>'
            .'<a href="tel:+15555555555">Call</a>'
            .'<a href="#top">Top</a>';

        $this->assertSame([], LinkExtractor::extract($html));
    }

    public function test_it_dedupes_repeated_links(): void
    {
        $html = '<a href="https://example.com">A</a><a href="https://example.com">B</a>';

        $this->assertSame(['https://example.com'], LinkExtractor::extract($html));
    }

    public function test_it_returns_empty_array_for_no_html(): void
    {
        $this->assertSame([], LinkExtractor::extract(null));
        $this->assertSame([], LinkExtractor::extract(''));
    }
}
