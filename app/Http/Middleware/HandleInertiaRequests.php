<?php

namespace App\Http\Middleware;

use App\Domains\Access\Actions\AuthorizeAction;
use Illuminate\Http\Request;
use Inertia\Middleware;

class HandleInertiaRequests extends Middleware
{
    /**
     * The root template that's loaded on the first page visit.
     *
     * @see https://inertiajs.com/server-side-setup#root-template
     *
     * @var string
     */
    protected $rootView = 'app';

    /**
     * Determines the current asset version.
     *
     * @see https://inertiajs.com/asset-versioning
     */
    public function version(Request $request): ?string
    {
        return parent::version($request);
    }

    /**
     * Define the props that are shared by default.
     *
     * @see https://inertiajs.com/shared-data
     *
     * @return array<string, mixed>
     */
    public function share(Request $request): array
    {
        $user = $request->user();

        return [
            ...parent::share($request),
            'name' => config('app.name'),
            'auth' => [
                'user' => $user,
                'permissions' => fn () => $user
                    ? app(AuthorizeAction::class)->resolvePermissions($user, $user->currentTeam)
                    : [],
            ],
            'sidebarOpen' => ! $request->hasCookie('sidebar_state') || $request->cookie('sidebar_state') === 'true',
            'currentTeam' => fn () => $user?->currentTeam ? $user->toUserTeam($user->currentTeam) : null,
            'teams' => fn () => $user?->toUserTeams(includeCurrent: true) ?? [],
            // Surfaces the impersonation banner: a super-admin whose current team
            // is one they do NOT belong to is, by definition, impersonating it.
            'impersonation' => fn () => $user
                && $user->isSuperAdmin()
                && $user->currentTeam
                && ! $user->belongsToTeam($user->currentTeam)
                    ? ['active' => true, 'team' => [
                        'name' => $user->currentTeam->name,
                        'slug' => $user->currentTeam->slug,
                    ]]
                    : null,
        ];
    }
}
