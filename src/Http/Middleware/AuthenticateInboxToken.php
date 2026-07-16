<?php

namespace Sendtrap\Core\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Sendtrap\Core\Models\Inbox;
use Sendtrap\Core\Support\IpAllowList;
use Symfony\Component\HttpFoundation\Response;

/**
 * Authenticates an API request by its inbox API token (Authorization: Bearer
 * <token> or X-Api-Token header) and binds the resolved inbox to the request.
 *
 * Each host's `inbox-api`/`inbox-api-wait` rate limiter closures (registered
 * in the host's own service provider — limiter *registration* stays
 * host-side) call `resolve()` directly.
 */
class AuthenticateInboxToken
{
    public function handle(Request $request, Closure $next): Response
    {
        $token = $request->bearerToken() ?: $request->header('X-Api-Token');

        if (! $token) {
            return response()->json(['message' => 'API token missing.'], 401);
        }

        $inbox = static::resolve($request);

        if (! $inbox) {
            return response()->json(['message' => 'Invalid API token.'], 401);
        }

        if (! IpAllowList::allows($inbox->effectiveAllowedIps(), $request->ip())) {
            return response()->json(['message' => 'Access denied for your IP address.'], 403);
        }

        $request->attributes->set('inbox', $inbox);

        return $next($request);
    }

    /**
     * Resolve the inbox for a request's bearer token, independent of
     * middleware order. Used both here and by the `inbox-api`/
     * `inbox-api-wait` rate limiters, which run before this middleware in
     * the actual dispatch order (Laravel's global middleware-priority list
     * places ThrottleRequests ahead of custom middleware regardless of
     * declared order) and so can't rely on `$request->attributes` having
     * been set yet.
     */
    public static function resolve(Request $request): ?Inbox
    {
        $token = $request->bearerToken() ?: $request->header('X-Api-Token');

        if (! $token) {
            return null;
        }

        // project.workspace is eager-loaded: the flipped
        // effectiveAllowedIps() account tier and the inbox-api limiter's
        // Entitlements resolution both read $inbox->project->workspace, and
        // this closure-adjacent path runs on every token-authenticated API
        // request — eager-loading it keeps it at one extra indexed query
        // instead of a lazy load per request (hot-path query-count
        // discipline, pinned by InboxApiQueryCountTest). Core has no Team
        // concept (§1.2) and defines no `project.team` relation at all —
        // any host that needs a Workspace's owning Team (Cloud's
        // adapters unwrap Workspace -> team on every Entitlements/UsageMeter
        // call) gets it via a plain lazy load on `$workspace->team`, a
        // one-shot query cached on the instance thereafter; nothing in core
        // primes or reads it.
        return Inbox::with('project.workspace')->where('api_token', $token)->first();
    }
}
