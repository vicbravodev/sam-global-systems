<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class EnsureSuperAdmin
{
    /**
     * Allow the request through only for SaaS operators (global super-admins).
     *
     * This guards the cross-tenant `/admin` panel, which lives outside the
     * `/{current_team}` group and reads data across every tenant.
     *
     * @param  Closure(Request): (Response)  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        abort_if(! $request->user()?->isSuperAdmin(), 403);

        return $next($request);
    }
}
