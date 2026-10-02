<?php

namespace App\Http\Middleware;

use App\Support\SystemLog;
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
        if ($request->user()?->isSuperAdmin() !== true) {
            SystemLog::skipped('access.super_admin.denied', reason: 'not_super_admin', input: [
                'user_id' => $request->user()?->getAuthIdentifier(),
                'route_name' => $request->route()?->getName(),
            ]);

            abort(403);
        }

        return $next($request);
    }
}
