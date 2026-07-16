<?php

namespace Sendtrap\Core\Tests\Unit;

use PHPUnit\Framework\TestCase;
use Sendtrap\Core\Support\MergeTagDetector;

class MergeTagDetectorTest extends TestCase
{
    public function test_it_detects_curly_brace_tags(): void
    {
        $result = MergeTagDetector::detect('<p>Hi {{ first_name }}</p>', null);

        $this->assertTrue($result['has_unresolved_merge_tags']);
        $this->assertSame(['{{ first_name }}'], $result['unresolved_merge_tags']);
    }

    public function test_it_detects_percent_delimited_tags(): void
    {
        $result = MergeTagDetector::detect(null, 'Hi %first_name%, welcome.');

        $this->assertTrue($result['has_unresolved_merge_tags']);
        $this->assertContains('%first_name%', $result['unresolved_merge_tags']);
    }

    public function test_it_does_not_false_positive_on_ordinary_percentages(): void
    {
        $result = MergeTagDetector::detect('<div style="width: 50%">100% off, save 20%!</div>', null);

        $this->assertFalse($result['has_unresolved_merge_tags']);
    }

    public function test_it_returns_no_tags_for_clean_content(): void
    {
        $result = MergeTagDetector::detect('<p>Hi Alice</p>', 'Hi Alice');

        $this->assertFalse($result['has_unresolved_merge_tags']);
        $this->assertSame([], $result['unresolved_merge_tags']);
    }

    public function test_it_dedupes_repeated_tags(): void
    {
        $result = MergeTagDetector::detect('{{name}} ... {{name}}', null);

        $this->assertSame(['{{name}}'], $result['unresolved_merge_tags']);
    }
}
