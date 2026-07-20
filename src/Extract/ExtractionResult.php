<?php

namespace Sendtrap\Core\Extract;

/**
 * One extractor's verdict. `found` is the only success state; misses stay
 * explicit and diagnosable — `not_found` (nothing matched), `ambiguous`
 * (several distinct values matched and the extractor was not told which to
 * take), `not_evaluated` (the surrounding /expect never reached extraction).
 * The server never guesses among ambiguous values.
 */
final class ExtractionResult
{
    /**
     * @param  list<mixed>|null  $candidates
     */
    private function __construct(
        public readonly string $status,
        public readonly mixed $value,
        public readonly int $matches,
        public readonly ?string $source,
        public readonly ?string $context,
        public readonly ?array $candidates,
    ) {}

    public function isFound(): bool
    {
        return $this->status === 'found';
    }

    public static function found(mixed $value, int $matches, string $source, ?string $context): self
    {
        return new self('found', $value, $matches, $source, $context, null);
    }

    public static function notFound(string $source): self
    {
        return new self('not_found', null, 0, $source, null, null);
    }

    /**
     * @param  list<mixed>  $candidates  truncated, diagnostics-safe values
     */
    public static function ambiguous(string $source, int $matches, array $candidates): self
    {
        return new self('ambiguous', null, $matches, $source, null, $candidates);
    }

    public static function notEvaluated(): self
    {
        return new self('not_evaluated', null, 0, null, null, null);
    }

    /**
     * @return array{found: bool, status: string, value: mixed, matches: int, source: ?string, context: ?string, candidates: ?array}
     */
    public function toArray(): array
    {
        return [
            'found' => $this->isFound(),
            'status' => $this->status,
            'value' => $this->value,
            'matches' => $this->matches,
            'source' => $this->source,
            'context' => $this->context,
            'candidates' => $this->candidates,
        ];
    }
}
