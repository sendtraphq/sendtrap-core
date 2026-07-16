<?php

namespace Sendtrap\Core\Support\HtmlCompatibility;

use Sabberworm\CSS\CSSList\CSSList;
use Sabberworm\CSS\Parser as CssParser;
use Sabberworm\CSS\Property\AtRule;
use Sabberworm\CSS\RuleSet\RuleSet;

/**
 * Pure HTML string -> list of used HTML/CSS tokens (tags, attributes, CSS
 * properties, at-rules). Walks the DOM for markup and runs every <style>
 * block and inline style="" attribute through the CSS parser. Stateless and
 * independent of Message, so it's directly unit-testable against fixture
 * HTML.
 *
 * Attributes that are style/structural noise, not caniemail-tracked
 * features, are skipped (class/id/data-*).
 */
class HtmlFeatureExtractor
{
    protected const SKIPPED_ATTRIBUTES = ['class', 'id', 'style'];

    /**
     * @return list<array{type: string, name: string, count: int}>
     */
    public static function extract(string $html): array
    {
        $counts = [];
        $bump = function (string $type, string $name) use (&$counts) {
            $key = $type.':'.strtolower($name);
            $counts[$key] ??= ['type' => $type, 'name' => strtolower($name), 'count' => 0];
            $counts[$key]['count']++;
        };

        if (trim($html) === '') {
            return [];
        }

        libxml_use_internal_errors(true);
        $dom = new \DOMDocument;
        $dom->loadHTML('<?xml encoding="utf-8" ?>'.$html, LIBXML_NOERROR | LIBXML_NOWARNING);
        libxml_clear_errors();

        foreach ($dom->getElementsByTagName('*') as $element) {
            /** @var \DOMElement $element */
            $bump('html-tag', $element->tagName);

            foreach ($element->attributes as $attribute) {
                if (in_array(strtolower($attribute->name), self::SKIPPED_ATTRIBUTES, true)) {
                    continue;
                }
                $bump('html-attribute', $attribute->name);
            }

            if ($element->tagName === 'style') {
                static::extractCss($element->textContent, $bump);
            }

            if ($element->hasAttribute('style')) {
                static::extractCss('x{'.$element->getAttribute('style').'}', $bump);
            }
        }

        return array_values($counts);
    }

    protected static function extractCss(string $css, \Closure $bump): void
    {
        if (trim($css) === '') {
            return;
        }

        try {
            $document = (new CssParser($css))->parse();
        } catch (\Throwable) {
            return; // malformed CSS in a real captured email — skip rather than fail the whole check
        }

        foreach ($document->getAllRuleSets() as $ruleSet) {
            /** @var RuleSet $ruleSet */
            foreach ($ruleSet->getDeclarations() as $declaration) {
                $bump('css-property', $declaration->getRule());

                try {
                    $value = (string) $declaration->getValue();
                } catch (\Throwable) {
                    continue; // non-stringable value node (e.g. a URL); property name was already recorded
                }

                foreach (preg_split('/[\s,]+/', $value) as $token) {
                    $bump('css-value', trim($token, '()'));
                }
            }
        }

        static::walkAtRules($document, $bump);
    }

    protected static function walkAtRules(CSSList $list, \Closure $bump): void
    {
        foreach ($list->getContents() as $item) {
            if ($item instanceof AtRule) {
                $bump('css-at-rule', $item->atRuleName());
            }

            if ($item instanceof CSSList) {
                static::walkAtRules($item, $bump);
            }
        }
    }
}
