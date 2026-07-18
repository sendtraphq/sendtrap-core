<?php

namespace Sendtrap\Core\Expect;

use Illuminate\Support\Collection;
use Sendtrap\Core\Models\Inbox;
use Sendtrap\Core\Models\Message;

/**
 * Evaluates one snapshot of an ExpectSpec against an inbox. The controller
 * re-runs snapshots inside the wait loop; each run is independent and
 * bounded: candidates are narrowed in SQL first (scope + the safely
 * SQL-expressible match conditions), then at most CANDIDATE_CAP messages
 * are parsed and evaluated in PHP.
 */
final class ExpectEvaluator
{
    public const CANDIDATE_CAP = 50;

    public function __construct(
        private readonly Inbox $inbox,
        private readonly ExpectSpec $spec,
    ) {}

    public function evaluate(): ExpectOutcome
    {
        $candidatesSeen = $this->scopeQuery()->count();

        /** @var Collection<int, Message> $candidates */
        $candidates = $this->scopeQuery()
            ->tap(fn ($q) => $this->applyPrefilter($q))
            ->with('attachments')
            ->orderBy('received_at', $this->spec->sort === 'newest' ? 'desc' : 'asc')
            ->orderBy('id', $this->spec->sort === 'newest' ? 'desc' : 'asc')
            ->limit(self::CANDIDATE_CAP)
            ->get();

        $matched = $candidates->filter(
            fn (Message $m) => collect($this->spec->match)->every(fn (Condition $c) => $c->evaluate($m)->passed)
        )->values();

        // The SQL prefilter can leave the candidate set empty while scope
        // still holds messages — fetch the newest scoped one purely so a
        // no_match diagnosis has a message to explain itself against.
        if ($candidates->isEmpty() && $candidatesSeen > 0) {
            $candidates = $this->scopeQuery()
                ->with('attachments')
                ->orderByDesc('received_at')->orderByDesc('id')
                ->limit(1)
                ->get();
        }

        $countSatisfied = $this->spec->exactly !== null
            ? $matched->count() === $this->spec->exactly
            : $matched->count() >= $this->spec->atLeast;

        // Assertions must hold on every matched message.
        $assertResults = [];
        $assertionsPassed = true;
        $failedOn = null;

        if ($matched->isNotEmpty() && $this->spec->assert !== []) {
            foreach ($matched as $message) {
                $results = array_map(fn (Condition $c) => $c->evaluate($message), $this->spec->assert);
                $failed = array_filter($results, fn (ConditionResult $r) => ! $r->passed);

                if ($failed !== [] && $assertionsPassed) {
                    $assertionsPassed = false;
                    $assertResults = $results;
                    $failedOn = $message->id;
                }
            }

            if ($assertionsPassed) {
                $assertResults = array_map(fn (Condition $c) => $c->evaluate($matched->first()), $this->spec->assert);
            }
        }

        return new ExpectOutcome(
            spec: $this->spec,
            candidatesSeen: $candidatesSeen,
            candidates: $candidates,
            matched: $matched,
            countSatisfied: $countSatisfied,
            assertionsPassed: $assertionsPassed,
            assertResults: $assertResults,
            assertionsFailedOn: $failedOn,
        );
    }

    private function scopeQuery()
    {
        return $this->inbox->messages()
            ->when($this->spec->testId !== null, fn ($q) => $q->where('test_id', $this->spec->testId))
            ->when($this->spec->receivedAfter !== null, fn ($q) => $q->where('received_at', '>', $this->spec->receivedAfter))
            ->when($this->spec->receivedBefore !== null, fn ($q) => $q->where('received_at', '<', $this->spec->receivedBefore))
            ->when($this->spec->afterMessageId !== null, fn ($q) => $q->where('id', '>', $this->spec->afterMessageId))
            ->when($this->spec->unreadOnly, fn ($q) => $q->where('is_read', false));
    }

    /**
     * Narrow candidates with the match conditions that are provably
     * expressible in SQL with identical semantics (byte-substring LIKE).
     * Anything else is left to the PHP evaluation — a prefilter must never
     * exclude a message the evaluator would have matched.
     */
    private function applyPrefilter($query): void
    {
        foreach ($this->spec->match as $condition) {
            $field = $condition->field;
            $column = match ($field->raw) {
                'subject' => 'subject',
                'from.address' => 'from_address',
                'test_id' => 'test_id',
                default => null,
            };

            if ($column === null) {
                continue;
            }

            $escaped = fn () => addcslashes((string) $condition->value, '\%_');

            match ($condition->operator) {
                'equals' => $query->where($column, $condition->value),
                'contains' => $query->where($column, 'like', '%'.$escaped().'%'),
                'starts_with' => $query->where($column, 'like', $escaped().'%'),
                'ends_with' => $query->where($column, 'like', '%'.$escaped()),
                default => null,
            };
        }
    }
}
