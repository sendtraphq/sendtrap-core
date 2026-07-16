<?php

/**
 * Hand-maintained overrides for caniemail feature titles that don't reduce to
 * a plain "<tag>", "<tag> element", "attribute-name attribute" or bare
 * CSS-property/@rule name. FeatureMap derives the regular cases automatically
 * from each feature's title; only the irregular ones need an entry here,
 * keyed by the feature's `slug` (from resources/data/caniemail/features.json).
 * Expand as gaps are found.
 */
return [
    'css-property' => [
        // slug => [css property names it covers]
        'css-block-inline-size' => ['block-size', 'inline-size'],
        'css-border-inline-block' => ['border-inline', 'border-block'],
        'css-border-inline-block-individual' => [
            'border-block-end', 'border-block-start', 'border-inline-end', 'border-inline-start',
        ],
        'css-border-inline-block-longhand' => [
            'border-block-color', 'border-block-style', 'border-block-width',
            'border-inline-color', 'border-inline-style', 'border-inline-width',
        ],
        'css-border-radius-logical' => [
            'border-end-end-radius', 'border-end-start-radius', 'border-start-end-radius', 'border-start-start-radius',
        ],
        'css-color-scheme' => ['color-scheme'],
        'css-column-layout-properties' => [
            'column-count', 'column-fill', 'column-gap', 'column-rule', 'column-rule-color',
            'column-rule-style', 'column-rule-width', 'column-span', 'column-width', 'columns',
        ],
        'css-gap' => ['column-gap', 'gap', 'row-gap'],
        'css-grid-template' => ['grid-template', 'grid-template-areas', 'grid-template-columns', 'grid-template-rows'],
        'css-margin-block-start-end' => ['margin-block-end', 'margin-block-start'],
        'css-margin-inline-block' => ['margin-block', 'margin-inline'],
        'css-margin-inline-start-end' => ['margin-inline-end', 'margin-inline-start'],
        'css-padding-block-start-end' => ['padding-block-end', 'padding-block-start'],
        'css-padding-inline-block' => ['padding-block', 'padding-inline'],
    ],
    'css-value' => [
        // slug => [property values it covers, matched against any declaration's value]
        'css-intrinsic-size' => ['fit-content', 'max-content', 'min-content'],
        'css-sytem-ui' => ['system-ui', 'ui-monospace', 'ui-rounded', 'ui-sans-serif', 'ui-serif'],
    ],
    'html-tag' => [
        'html-address' => ['address'],
        'html-doctype' => ['!doctype'],
        'html-semantics' => [
            'article', 'aside', 'details', 'figcaption', 'figure', 'footer',
            'header', 'main', 'mark', 'nav', 'section', 'summary', 'time',
        ],
        'html-image-maps' => ['map'],
        'html-svg' => ['svg'],
        'html-h1-h6' => ['h1', 'h2', 'h3', 'h4', 'h5', 'h6'],
    ],
    'html-attribute' => [
        'html-srcset' => ['srcset', 'sizes'],
    ],
];
