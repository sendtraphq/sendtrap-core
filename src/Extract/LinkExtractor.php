<?php

namespace Sendtrap\Core\Extract;

use InvalidArgumentException;
use Sendtrap\Core\Models\Message;
use Sendtrap\Core\Support\LinkExtractor as Links;
use Sendtrap\Core\Support\SafeRegex;

/**
 * `type: link` — select a link from the HTML body by exact URL, host, path
 * prefix, query parameter, visible anchor text, or a regular expression on
 * the URL. Criteria combine with AND. The selected link is returned as
 * data, never fetched; relative URLs stay relative unless the message
 * declares a valid absolute `<base href>`.
 */
final class LinkExtractor extends Extractor
{
    public const KEYS = ['url', 'host', 'path_prefix', 'query_param', 'text_contains', 'matches'];

    private function __construct(
        public readonly ?string $url,
        public readonly ?string $host,
        public readonly ?string $pathPrefix,
        public readonly ?string $queryParam,
        public readonly ?string $queryValue,
        public readonly ?string $textContains,
        public readonly ?string $matches,
        ?string $select,
        bool $optional,
    ) {
        parent::__construct($select, $optional);
    }

    protected static function make(string $name, array $raw, ?string $select, bool $optional): static
    {
        $matches = self::stringOption($name, $raw, 'matches');

        if ($matches !== null) {
            SafeRegex::validate($matches);
        }

        [$queryParam, $queryValue] = self::queryParamOption($name, $raw);

        return new self(
            url: self::stringOption($name, $raw, 'url'),
            host: self::stringOption($name, $raw, 'host'),
            pathPrefix: self::stringOption($name, $raw, 'path_prefix'),
            queryParam: $queryParam,
            queryValue: $queryValue,
            textContains: self::stringOption($name, $raw, 'text_contains'),
            matches: $matches,
            select: $select,
            optional: $optional,
        );
    }

    public function run(Message $message): ExtractionResult
    {
        $candidates = [];

        foreach (Links::detailed($message->htmlBody()) as $link) {
            if (! $this->selects($link['url'], $link['text'])) {
                continue;
            }

            $candidates[] = [
                'value' => $link,
                'context' => $link['text'] !== '' ? $link['text'] : null,
            ];
        }

        return $this->outcome($candidates, 'links');
    }

    private function selects(string $url, string $text): bool
    {
        if ($this->url !== null && $url !== $this->url) {
            return false;
        }

        if ($this->host !== null) {
            $host = parse_url($url, PHP_URL_HOST);

            if (! is_string($host) || strcasecmp($host, $this->host) !== 0) {
                return false;
            }
        }

        if ($this->pathPrefix !== null) {
            $path = parse_url($url, PHP_URL_PATH);

            if (! is_string($path) || ! str_starts_with($path, $this->pathPrefix)) {
                return false;
            }
        }

        if ($this->queryParam !== null && ! $this->queryMatches($url)) {
            return false;
        }

        if ($this->textContains !== null && mb_stripos($text, $this->textContains) === false) {
            return false;
        }

        if ($this->matches !== null && ! @preg_match(SafeRegex::delimit($this->matches), $url)) {
            return false;
        }

        return true;
    }

    private function queryMatches(string $url): bool
    {
        $query = parse_url($url, PHP_URL_QUERY);

        if (! is_string($query) || $query === '') {
            return false;
        }

        parse_str($query, $params);

        if (! array_key_exists($this->queryParam, $params)) {
            return false;
        }

        return $this->queryValue === null
            || (is_string($params[$this->queryParam]) && $params[$this->queryParam] === $this->queryValue);
    }

    /**
     * `query_param` accepts a bare parameter name, or `{name, value}` to
     * also require a specific value.
     *
     * @return array{0: ?string, 1: ?string}
     */
    private static function queryParamOption(string $name, array $raw): array
    {
        $option = $raw['query_param'] ?? null;

        if ($option === null) {
            return [null, null];
        }

        if (is_string($option) && $option !== '' && strlen($option) <= 256) {
            return [$option, null];
        }

        if (is_array($option)
            && is_string($option['name'] ?? null) && $option['name'] !== ''
            && (! array_key_exists('value', $option) || is_string($option['value']))) {
            return [$option['name'], $option['value'] ?? null];
        }

        throw new InvalidArgumentException(
            "Extractor \"{$name}\": \"query_param\" must be a parameter name or {name, value}."
        );
    }
}
