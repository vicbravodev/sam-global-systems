<?php

namespace App\Http\Responses;

use Illuminate\Http\JsonResponse;
use Laravel\Fortify\Contracts\TwoFactorLoginResponse as TwoFactorLoginResponseContract;
use Symfony\Component\HttpFoundation\Response;

class TwoFactorLoginResponse implements TwoFactorLoginResponseContract
{
    public function toResponse($request): Response
    {
        $user = $request->user();
        $team = $user?->currentTeam ?? $user?->personalTeam();

        if (! $team) {
            abort(403);
        }

        if ($request->wantsJson()) {
            return new JsonResponse(['two_factor' => false], 200);
        }

        // The SaaS operator works from the cross-tenant console.
        if ($user->isSuperAdmin()) {
            return redirect()->intended(route('admin.tenants.index'));
        }

        return redirect()->intended("/{$team->slug}/dashboard");
    }
}
