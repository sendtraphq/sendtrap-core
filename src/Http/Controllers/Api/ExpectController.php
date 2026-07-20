<?php

namespace Sendtrap\Core\Http\Controllers\Api;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use InvalidArgumentException;
use Sendtrap\Core\Contracts\MessageWaiter;
use Sendtrap\Core\Expect\ExpectEvaluator;
use Sendtrap\Core\Expect\ExpectOutcome;
use Sendtrap\Core\Expect\ExpectSpec;
use Sendtrap\Core\Http\Controllers\Api\Concerns\ScopedToInboxToken;
use Sendtrap\Core\Http\Controllers\Controller;
use Sendtrap\Core\Http\Resources\MessageResource;

/**
 * POST /api/v1/expect — one deterministic request that waits for mail,
 * evaluates expressive match conditions, applies post-match assertions,
 * optionally extracts named values (verification codes, links, addresses,
 * attachments — Plan 05) atomically with the match, and returns a
 * machine-readable diagnostic that distinguishes "no mail arrived" from
 * "mail arrived but was wrong" (Plan 02). /assert stays unchanged for
 * compatibility; this is the endpoint test suites should reach for.
 *
 * Report mode (default) always answers 200. Strict mode answers 422 on an
 * unmet expectation with the same diagnostic body, so a plain HTTP-error
 * check fails a CI step without JSON inspection.
 */
class ExpectController extends Controller
{
    use ScopedToInboxToken;

    public function __invoke(Request $request, MessageWaiter $waiter): JsonResponse
    {
        $inbox = $this->inbox($request);
        $requestId = (string) Str::uuid();
        $started = microtime(true);

        try {
            $spec = ExpectSpec::fromArray(
                $request->json()->all(),
                (int) config('sendtrap.wait_max_seconds') * 1000,
            );
        } catch (InvalidArgumentException $e) {
            return response()->json([
                'message' => $e->getMessage(),
                'request_id' => $requestId,
            ], 422);
        }

        $evaluator = new ExpectEvaluator($inbox, $spec);

        /** @var ExpectOutcome $outcome */
        $outcome = $evaluator->evaluate();

        if (! $outcome->satisfied() && $spec->timeoutMs > 0) {
            $waiter->wait($spec->timeoutMs, function () use ($evaluator, &$outcome) {
                $outcome = $evaluator->evaluate();

                return $outcome->satisfied();
            });
        }

        if ($outcome->satisfied() && $spec->markRead) {
            $outcome->matched->each->update(['is_read' => true]);
        }

        $body = [
            'matched' => $outcome->satisfied(),
            'status' => $outcome->status(),
            'elapsed_ms' => (int) ((microtime(true) - $started) * 1000),
            'candidates_seen' => $outcome->candidatesSeen,
            'count' => [
                'required' => $spec->exactly !== null
                    ? ['exactly' => $spec->exactly]
                    : ['at_least' => $spec->atLeast],
                'actual' => $outcome->matched->count(),
            ],
            'messages' => MessageResource::collection($outcome->matched->take(10))->resolve(),
            'conditions' => $outcome->conditionDiagnostics(),
            'assertions_failed_on' => $outcome->assertionsFailedOn,
            'extract' => $outcome->extractDiagnostics(),
            'request_id' => $requestId,
        ];

        return response()->json($body, $spec->strict && ! $outcome->satisfied() ? 422 : 200);
    }
}
