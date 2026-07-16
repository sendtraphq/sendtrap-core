<?php

namespace Sendtrap\Core\Http\Controllers\Api;

use Illuminate\Cache\RateLimiter;
use Illuminate\Http\Request;
use Sendtrap\Core\Contracts\Entitlements;
use Sendtrap\Core\Http\Controllers\Api\Concerns\ScopedToInboxToken;
use Sendtrap\Core\Http\Controllers\Controller;
use Sendtrap\Core\Http\Resources\MessageDetailResource;
use Sendtrap\Core\Http\Resources\MessageResource;
use Sendtrap\Core\Models\Attachment;
use Sendtrap\Core\Models\Inbox;
use Sendtrap\Core\Models\Message;
use Sendtrap\Core\Support\MessageStorage;
use Symfony\Component\HttpFoundation\Response;

class MessageController extends Controller
{
    use ScopedToInboxToken;

    public function index(Request $request)
    {
        $inbox = $this->inbox($request);
        $perPage = $request->integer('per_page', 50);
        $waitSeconds = $this->resolveWaitSeconds($request, 'wait');

        $messages = $this->filteredMessages($inbox, $request)->paginate($perPage);

        if ($waitSeconds > 0 && $messages->isEmpty()) {
            $this->enforceWaitRateLimit($request);

            $this->busyWaitUntil($waitSeconds, function () use ($inbox, $request, $perPage, &$messages) {
                $messages = $this->filteredMessages($inbox, $request)->paginate($perPage);

                return $messages->isNotEmpty();
            });
        }

        return MessageResource::collection($messages);
    }

    /**
     * POST /assert — {to, subject_contains, test_id, timeout,
     * min_compatibility_score}. Blocks (like `wait` above) until a matching
     * message arrives or the timeout expires, then always returns 200 with a
     * pass/fail flag in the body — an empty result here is an expected
     * outcome for a test assertion, not an error.
     */
    public function assert(Request $request)
    {
        // Rate-limited via the `inbox-api-wait` route middleware (routes/api.php)
        // rather than the manual check index() uses — this route only ever
        // blocks, so a static middleware throttle is sufficient here.
        $inbox = $this->inbox($request);
        $waitSeconds = $this->resolveWaitSeconds($request, 'timeout');
        $minCompatibilityScore = $request->filled('min_compatibility_score')
            ? $request->float('min_compatibility_score')
            : null;

        if ($minCompatibilityScore !== null) {
            $this->ensureHtmlCheckApiAccess($inbox);
        }

        $matches = function () use ($inbox, $request, $minCompatibilityScore) {
            $message = $this->filteredMessages($inbox, $request)->first();

            if ($message === null) {
                return null;
            }

            // Only the one matched message is checked (not the whole
            // inbox), so this bounded compute stays cheap on the busy-wait path.
            if ($minCompatibilityScore !== null && $message->resolveHtmlCheck()->compatibility_ratio < $minCompatibilityScore) {
                return null;
            }

            return $message;
        };

        $message = $matches();

        if (! $message && $waitSeconds > 0) {
            $this->busyWaitUntil($waitSeconds, function () use ($matches, &$message) {
                $message = $matches();

                return $message !== null;
            });
        }

        return response()->json([
            'matched' => $message !== null,
            'message' => $message ? new MessageResource($message) : null,
        ]);
    }

    /**
     * Gate for the HTML Check API surface (dedicated endpoint, assert's
     * min_compatibility_score, and the checks[] summary entry) — Starter
     * plan and above. The web UI tab is ungated on every plan.
     *
     * Plan 06 Phase 2 (§5.1 item 9): resolves through Entitlements against
     * the inbox's workspace (Inbox::getWorkspaceAttribute()). A null
     * workspace (not yet backfilled) denies with the same 403 — never a
     * null flowing into the non-nullable contract parameter (§5.0.1).
     */
    protected function ensureHtmlCheckApiAccess(Inbox $inbox): void
    {
        $workspace = $inbox->workspace;

        abort_unless(
            $workspace !== null && app(Entitlements::class)->for($workspace)->hasHtmlCheckApi(),
            403,
            'HTML Check via the API requires the Starter plan or above.'
        );
    }

    /**
     * GET /messages/{message}/compatibility — HTML Check result (score +
     * per-issue/per-client breakdown), computed on demand and cached.
     * Starter plan and above.
     */
    public function compatibility(Request $request, Message $message)
    {
        $this->ensureOwned($request, $message);
        $this->ensureHtmlCheckApiAccess($this->inbox($request));

        $htmlCheck = $message->resolveHtmlCheck();

        return response()->json([
            'status' => 'ok',
            'compatibility_ratio' => $htmlCheck->compatibility_ratio,
            'issues' => $htmlCheck->report,
            'checked_at' => $htmlCheck->checked_at->toIso8601String(),
        ]);
    }

    /**
     * The shared filter set behind `index`, `wait`, and `assert`: search,
     * exact test_id, substring recipient match (against both the To/Cc
     * headers and the SMTP envelope, since BCC only shows up in the
     * latter), and — for `assert` — a subject substring filter.
     */
    protected function filteredMessages(Inbox $inbox, Request $request)
    {
        $search = $request->string('search')->trim();
        $subjectContains = $request->string('subject_contains')->trim();

        return $inbox->messages()
            ->when($search->isNotEmpty(), function ($query) use ($search) {
                $term = '%'.$search.'%';
                $query->where(fn ($q) => $q->where('subject', 'like', $term)
                    ->orWhere('from_address', 'like', $term)
                    ->orWhere('from_name', 'like', $term));
            })
            ->when($subjectContains->isNotEmpty(), function ($query) use ($subjectContains) {
                $query->where('subject', 'like', '%'.$subjectContains.'%');
            })
            ->when($request->filled('test_id'), function ($query) use ($request) {
                $query->where('test_id', $request->string('test_id'));
            })
            ->when($request->filled('to'), function ($query) use ($request) {
                $needle = '%'.$request->string('to').'%';
                $query->where(fn ($q) => $q->where('to', 'like', $needle)
                    ->orWhere('envelope_to', 'like', $needle));
            })
            ->orderByDesc('received_at');
    }

    /**
     * Clamp a `wait`/`timeout` request param to `sendtrap.wait_max_seconds`.
     */
    protected function resolveWaitSeconds(Request $request, string $param): int
    {
        return max(0, min($request->integer($param, 0), config('sendtrap.wait_max_seconds')));
    }

    /**
     * Busy-wait inside this request, re-checking $isMatch on a short,
     * backing-off interval, until it returns true or $seconds elapses.
     * Occupies one HTTP worker for up to $seconds — bounded by a hard
     * per-request cap and a much tighter rate limit than normal API calls.
     */
    protected function busyWaitUntil(int $seconds, \Closure $isMatch): void
    {
        $deadline = microtime(true) + $seconds;
        $intervalMicroseconds = 250_000;

        while (microtime(true) < $deadline) {
            usleep($intervalMicroseconds);
            $intervalMicroseconds = min((int) ($intervalMicroseconds * 1.5), 1_000_000);

            if ($isMatch()) {
                return;
            }
        }
    }

    /**
     * Manually enforce the `inbox-api-wait` limiter for the blocking
     * GET /messages?wait=… path, which can't use route middleware since the
     * same route also serves ordinary (non-blocking) listing requests.
     */
    protected function enforceWaitRateLimit(Request $request): void
    {
        $limiter = app(RateLimiter::class);
        $limit = call_user_func($limiter->limiter('inbox-api-wait'), $request);

        abort_if(
            $limiter->tooManyAttempts($limit->key, $limit->maxAttempts),
            429,
            'Too many wait/assert requests.'
        );

        $limiter->hit($limit->key, $limit->decaySeconds);
    }

    public function show(Request $request, Message $message)
    {
        $this->ensureOwned($request, $message);

        return new MessageDetailResource($message->load('attachments'));
    }

    public function raw(Request $request, Message $message): Response
    {
        $this->ensureOwned($request, $message);

        return response($message->raw())->header('Content-Type', 'text/plain; charset=utf-8');
    }

    public function html(Request $request, Message $message): Response
    {
        $this->ensureOwned($request, $message);

        return response($message->renderedHtml())->header('Content-Type', 'text/html; charset=utf-8');
    }

    public function attachment(Request $request, Message $message, Attachment $attachment): Response
    {
        $this->ensureOwned($request, $message);

        abort_if($attachment->message_id !== $message->id, 404);

        return MessageStorage::disk()->download(
            $attachment->path,
            $attachment->filename,
            ['Content-Type' => $attachment->content_type ?? 'application/octet-stream'],
        );
    }

    public function update(Request $request, Message $message)
    {
        $this->ensureOwned($request, $message);

        $message->update(['is_read' => $request->boolean('is_read', true)]);

        return new MessageResource($message);
    }

    public function destroy(Request $request, Message $message)
    {
        $this->ensureOwned($request, $message);

        $message->delete();

        return response()->json(['deleted' => true]);
    }

    /**
     * Delete every message in the authenticated inbox.
     */
    public function destroyAll(Request $request)
    {
        $deleted = $this->inbox($request)->messages()->get()->each->delete()->count();

        return response()->json(['deleted' => $deleted]);
    }
}
