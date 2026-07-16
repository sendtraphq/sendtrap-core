<?php

namespace Sendtrap\Core\Support\HtmlCompatibility;

/**
 * Maps extracted HTML/CSS tokens (property names, tag names, attribute
 * names, at-rules) to caniemail feature entries. Most caniemail feature
 * titles already are the token they describe (e.g. a CSS feature titled
 * "background-image", an HTML feature titled "<table> element") — those are
 * derived automatically. Irregular titles are covered by the overrides in
 * data/feature_mapping.php.
 */
class FeatureMap
{
    protected static ?array $indexes = null;

    public static function forCssProperty(string $property): ?array
    {
        return static::lookup('css-property', strtolower($property));
    }

    public static function forCssAtRule(string $atRule): ?array
    {
        return static::lookup('css-at-rule', strtolower(ltrim($atRule, '@')));
    }

    public static function forCssValue(string $value): ?array
    {
        return static::lookup('css-value', strtolower($value));
    }

    public static function forHtmlTag(string $tag): ?array
    {
        return static::lookup('html-tag', strtolower($tag));
    }

    public static function forHtmlAttribute(string $attribute): ?array
    {
        return static::lookup('html-attribute', strtolower($attribute));
    }

    protected static function lookup(string $index, string $key): ?array
    {
        $slug = static::indexes()[$index][$key] ?? null;

        return $slug ? CaniemailDataset::features()[$slug] ?? null : null;
    }

    protected static function indexes(): array
    {
        return static::$indexes ??= static::build();
    }

    protected static function build(): array
    {
        $overrides = require __DIR__.'/data/feature_mapping.php';

        $indexes = [
            'css-property' => [],
            'css-value' => [],
            'css-at-rule' => [],
            'html-tag' => [],
            'html-attribute' => [],
        ];

        foreach ($overrides as $index => $bySlug) {
            foreach ($bySlug as $slug => $tokens) {
                foreach ($tokens as $token) {
                    $indexes[$index][strtolower($token)] = $slug;
                }
            }
        }

        foreach (CaniemailDataset::features() as $slug => $feature) {
            $title = trim($feature['title']);

            if ($feature['category'] === 'css') {
                if (preg_match('/^@([a-z-]+)$/i', $title, $m)) {
                    $indexes['css-at-rule'] += [strtolower($m[1]) => $slug];
                } elseif (preg_match('/^[a-z-]+$/i', $title)) {
                    $indexes['css-property'] += [strtolower($title) => $slug];
                }

                continue;
            }

            if ($feature['category'] === 'html') {
                if (preg_match('/^<(\w+)>(?: element)?$/i', $title, $m)) {
                    $indexes['html-tag'] += [strtolower($m[1]) => $slug];
                } elseif (preg_match('/^([\w-]+) attribute$/i', $title, $m)) {
                    $indexes['html-attribute'] += [strtolower($m[1]) => $slug];
                }
            }
        }

        return $indexes;
    }
}
