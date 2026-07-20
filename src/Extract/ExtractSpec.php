<?php

namespace Sendtrap\Core\Extract;

use InvalidArgumentException;
use Sendtrap\Core\Models\Message;

/**
 * A validated map of named extractors — the `extract` object shared by
 * `POST /messages/{message}/extract` and `/expect`. Construction is the
 * only place request data is interpreted; running the spec against a
 * message yields one ExtractionResult per name.
 */
final class ExtractSpec
{
    public const MAX_EXTRACTORS = 10;

    private const NAME_PATTERN = '/^[A-Za-z0-9_][A-Za-z0-9_.-]{0,63}$/';

    /**
     * @param  array<string, Extractor>  $extractors
     */
    private function __construct(
        public readonly array $extractors,
    ) {}

    /**
     * @throws InvalidArgumentException with a user-facing message
     */
    public static function fromArray(mixed $raw): self
    {
        if (! is_array($raw) || $raw === [] || array_is_list($raw)) {
            throw new InvalidArgumentException('"extract" must be an object mapping names to extractor definitions.');
        }

        if (count($raw) > self::MAX_EXTRACTORS) {
            throw new InvalidArgumentException('"extract" is capped at '.self::MAX_EXTRACTORS.' extractors.');
        }

        $extractors = [];

        foreach ($raw as $name => $definition) {
            $name = (string) $name; // JSON keys that look numeric decode as ints

            if (! preg_match(self::NAME_PATTERN, $name)) {
                throw new InvalidArgumentException(
                    'Extractor names must match [A-Za-z0-9_][A-Za-z0-9_.-]{0,63}.'
                );
            }

            if (! is_array($definition)) {
                throw new InvalidArgumentException("Extractor \"{$name}\" must be an object with a \"type\".");
            }

            $extractors[$name] = Extractor::fromArray($name, $definition);
        }

        return new self($extractors);
    }

    /**
     * @return array<string, ExtractionResult>
     */
    public function run(Message $message): array
    {
        return array_map(fn (Extractor $extractor) => $extractor->run($message), $this->extractors);
    }

    /**
     * @return array<string, ExtractionResult>
     */
    public function notEvaluated(): array
    {
        return array_map(fn () => ExtractionResult::notEvaluated(), $this->extractors);
    }

    /**
     * Whether every non-optional extractor found its value — the condition
     * a satisfied /expect (and a strict-mode extract call) requires.
     *
     * @param  array<string, ExtractionResult>  $results
     */
    public function satisfiedBy(array $results): bool
    {
        foreach ($this->extractors as $name => $extractor) {
            if (! $extractor->optional && ! ($results[$name] ?? ExtractionResult::notEvaluated())->isFound()) {
                return false;
            }
        }

        return true;
    }

    /**
     * @param  array<string, ExtractionResult>  $results
     * @return array<string, array<string, mixed>>
     */
    public static function toDiagnostics(array $results): array
    {
        return array_map(fn (ExtractionResult $result) => $result->toArray(), $results);
    }
}
