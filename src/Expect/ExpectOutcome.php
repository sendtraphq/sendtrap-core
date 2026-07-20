<?php

namespace Sendtrap\Core\Expect;

use Illuminate\Support\Collection;
use Sendtrap\Core\Extract\ExtractionResult;
use Sendtrap\Core\Extract\ExtractSpec;
use Sendtrap\Core\Models\Message;

/**
 * One snapshot's verdict, plus everything the diagnostic response needs.
 */
final class ExpectOutcome
{
    /**
     * @param  Collection<int, Message>  $candidates
     * @param  Collection<int, Message>  $matched
     * @param  list<ConditionResult>  $assertResults
     * @param  array<string, ExtractionResult>|null  $extractResults
     */
    public function __construct(
        public readonly ExpectSpec $spec,
        public readonly int $candidatesSeen,
        public readonly Collection $candidates,
        public readonly Collection $matched,
        public readonly bool $countSatisfied,
        public readonly bool $assertionsPassed,
        public readonly array $assertResults,
        public readonly ?int $assertionsFailedOn,
        public readonly ?array $extractResults = null,
        public readonly bool $extractionSatisfied = true,
    ) {}

    /**
     * The wait loop stops as soon as the whole expectation is satisfied.
     */
    public function satisfied(): bool
    {
        return $this->countSatisfied && $this->assertionsPassed && $this->extractionSatisfied;
    }

    public function status(): string
    {
        return match (true) {
            $this->satisfied() => 'matched',
            $this->candidatesSeen === 0 => 'no_candidates',
            $this->matched->isEmpty() => 'no_match',
            ! $this->countSatisfied => 'count_mismatch',
            ! $this->assertionsPassed => 'assertions_failed',
            default => 'extraction_failed',
        };
    }

    /**
     * Per-extractor diagnostics for the response body — null when the
     * request had no `extract` object.
     *
     * @return array<string, array<string, mixed>>|null
     */
    public function extractDiagnostics(): ?array
    {
        return $this->extractResults === null ? null : ExtractSpec::toDiagnostics($this->extractResults);
    }

    /**
     * Per-condition diagnostics. Match conditions are reported against the
     * first matched message when there is one, otherwise diagnosed against
     * the first candidate in scope (so a miss shows which condition broke).
     *
     * @return list<array<string, mixed>>
     */
    public function conditionDiagnostics(): array
    {
        $rows = [];
        $matchTarget = $this->matched->first() ?? $this->candidates->first();

        foreach ($this->spec->match as $condition) {
            $rows[] = ['type' => 'match'] + ($matchTarget !== null
                ? $condition->evaluate($matchTarget)->toArray()
                : [
                    'field' => $condition->field->raw,
                    'op' => $condition->operator,
                    'value' => $condition->value,
                    'passed' => null,
                    'actual' => null,
                ]);
        }

        foreach ($this->assertResults as $result) {
            $rows[] = ['type' => 'assert'] + $result->toArray();
        }

        // Assertions that never ran (no matched message) still appear, unevaluated.
        if ($this->assertResults === [] && $this->matched->isEmpty()) {
            foreach ($this->spec->assert as $condition) {
                $rows[] = [
                    'type' => 'assert',
                    'field' => $condition->field->raw,
                    'op' => $condition->operator,
                    'value' => $condition->value,
                    'passed' => null,
                    'actual' => null,
                ];
            }
        }

        return $rows;
    }
}
