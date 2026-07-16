<?php

namespace Sendtrap\Core\Support\HtmlCompatibility;

use Sendtrap\Core\Models\Message;

/**
 * Facade tying the extractor, feature map and scorer together for a
 * captured Message. No outbound calls, no persistence — callers (web
 * controller, API controller, assert) own the caching decision.
 */
class HtmlCompatibilityChecker
{
    /**
     * @return array{compatibility_ratio: float, issues: list<array>}
     */
    public static function run(Message $message): array
    {
        $features = HtmlFeatureExtractor::extract($message->htmlBody() ?? '');

        return CompatibilityScorer::score($features);
    }
}
