<?php

namespace Sendtrap\Core\Http\Controllers\Api;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use InvalidArgumentException;
use Sendtrap\Core\Extract\ExtractSpec;
use Sendtrap\Core\Http\Controllers\Api\Concerns\ScopedToInboxToken;
use Sendtrap\Core\Http\Controllers\Controller;
use Sendtrap\Core\Models\Message;

/**
 * POST /api/v1/messages/{message}/extract — deterministic extraction from a
 * known message (Plan 05): named regex captures, verification codes, link
 * selection, addresses and attachment metadata, with explicit found /
 * not_found / ambiguous states instead of guesses. Nothing is ever fetched;
 * bodies and attachment bytes are never echoed back.
 *
 * Report mode (default) always answers 200 — a miss is data. Strict mode
 * answers 422 with the same body when any non-optional extractor misses,
 * so a plain HTTP-error check fails a CI step.
 */
class ExtractController extends Controller
{
    use ScopedToInboxToken;

    public function __invoke(Request $request, Message $message): JsonResponse
    {
        $this->ensureOwned($request, $message);

        $requestId = (string) Str::uuid();

        try {
            $spec = ExtractSpec::fromArray($request->json('extract'));

            $mode = $request->json('mode', 'report');

            if (! in_array($mode, ['report', 'strict'], true)) {
                throw new InvalidArgumentException('"mode" must be "report" or "strict".');
            }
        } catch (InvalidArgumentException $e) {
            return response()->json([
                'message' => $e->getMessage(),
                'request_id' => $requestId,
            ], 422);
        }

        $results = $spec->run($message->load('attachments'));
        $satisfied = $spec->satisfiedBy($results);

        return response()->json([
            'message_id' => $message->id,
            'found_all' => $satisfied,
            'extract' => ExtractSpec::toDiagnostics($results),
            'request_id' => $requestId,
        ], $mode === 'strict' && ! $satisfied ? 422 : 200);
    }
}
