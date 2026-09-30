<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * For endpoints whose success is not JSON (the Copilot SSE stream, sent with
 * `Accept: text/event-stream`): puts JSON first in the Accept header so a
 * throttle, policy, 404 or validation error comes back as JSON instead of
 * the Inertia error page or a redirect. The response body on success is
 * unaffected: the stream never negotiates on Accept.
 */
class RendersErrorsAsJson
{
    /**
     * @param  Closure(Request): (Response)  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        // Read the raw header: asking the request (expectsJson) would cache
        // the negotiated types before the change.
        $accept = trim((string) $request->headers->get('Accept', ''));

        if (! str_starts_with($accept, 'application/json')) {
            $request->headers->set('Accept', trim('application/json, '.$accept, ', '));
        }

        return $next($request);
    }
}
