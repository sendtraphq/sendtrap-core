<?php

namespace Sendtrap\Core\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Marks a response noindex/nofollow. Applied to the public share routes in
 * the package (captured mail behind a token URL must never enter a search
 * index, and robots.txt disallow rules alone don't stop URL-only indexing
 * of links people post somewhere crawlable). Hosts may also apply it more
 * broadly — Community adds it globally.
 */
class DenySearchIndexing
{
    public function handle(Request $request, Closure $next): Response
    {
        $response = $next($request);

        $response->headers->set('X-Robots-Tag', 'noindex, nofollow');

        return $response;
    }
}
