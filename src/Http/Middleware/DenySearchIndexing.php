<?php

namespace Sendtrap\Core\Http\Middleware;

use Closure;
use Illuminate\Contracts\Debug\ExceptionHandler;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;
use Throwable;

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
        try {
            $response = $next($request);
        } catch (Throwable $e) {
            // An exception (a bad/expired-token 404/410 abort included)
            // would unwind past this middleware and be rendered without the
            // header — report + render here so error responses carry it too.
            $handler = app(ExceptionHandler::class);
            $handler->report($e);
            $response = $handler->render($request, $e);
        }

        $response->headers->set('X-Robots-Tag', 'noindex, nofollow');

        return $response;
    }
}
