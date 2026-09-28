<?php

namespace App\Http\Responses;

use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\URL;
use Laravel\Fortify\Contracts\LoginResponse as LoginResponseContract;
use Symfony\Component\HttpFoundation\Response;

class LoginResponse implements LoginResponseContract
{
    public function toResponse($request): Response
    {
        $user = $request->user();
        $team = $user?->currentTeam ?? $user?->personalTeam();

        if (! $team) {
            abort(403);
        }

        URL::defaults(['current_team' => $team->slug]);

        if ($request->wantsJson()) {
            return new JsonResponse(['two_factor' => false], 200);
        }

        // The SaaS operator works from the cross-tenant console, not from
        // their personal workspace.
        if ($user->isSuperAdmin()) {
            return redirect()->intended(route('admin.tenants.index'));
        }

        return redirect()->intended(route('dashboard'));
    }
}
