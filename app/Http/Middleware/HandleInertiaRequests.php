<?php

namespace App\Http\Middleware;

use App\Domains\Access\Actions\AuthorizeAction;
use App\Domains\Incidents\Models\Incident;
use App\Domains\Tenancy\Enums\SubscriptionStatus;
use App\Domains\Tenancy\Models\Subscription;
use App\Support\TenantContext;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
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
        // Team actual validado (membresía o super-admin): `current_team_id` es
        // sólo una preferencia y puede apuntar a un team del que ya no es miembro.
        $team = fn () => $user ? currentTeam() : null;

        return [
            ...parent::share($request),
            'name' => config('app.name'),
            'auth' => [
                // Sin relaciones: resolver el team actual carga `currentTeam` en el
                // modelo y no debe viajar al navegador (puede ser un team ajeno).
                'user' => $user?->withoutRelations(),
                'permissions' => fn () => $user
                    ? app(AuthorizeAction::class)->resolvePermissions($user, $team())
                    : [],
            ],
            'sidebarOpen' => ! $request->hasCookie('sidebar_state') || $request->cookie('sidebar_state') === 'true',
            'currentTeam' => fn () => ($current = $team()) ? $user->toUserTeam($current) : null,
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
            // Cross-tenant counters for the super-admin console badges. Only
            // resolved (and only queried) for the SaaS operator.
            'adminBadges' => fn () => $user?->isSuperAdmin()
                ? $this->adminBadges()
                : null,
            // SAM Copilot availability for the sidebar entry and the floating
            // bubble. Resolved through AuthorizeAction so the tenant feature
            // flag and the subscription state are honoured, not just the role.
            'copilot' => fn () => $user && ($current = $team())
                ? [
                    'enabled' => app(AuthorizeAction::class)->execute($user, 'copilot.use', $current),
                    'canViewUsage' => app(AuthorizeAction::class)->execute($user, 'copilot.usage.view', $current),
                ]
                : null,
            // Tenant-scoped counters for the workspace sidebar badges.
            'navBadges' => fn () => ($current = $team())
                ? $this->navBadges($current->id)
                : null,
        ];
    }

    /**
     * @return array{inbox: int}
     */
    private function navBadges(int $teamId): array
    {
        return Cache::remember(
            "nav-badges:{$teamId}",
            now()->addMinute(),
            fn (): array => TenantContext::for($teamId, fn (): array => [
                'inbox' => Incident::query()->open()->count(),
            ]),
        );
    }

    /**
     * @return array{tenantsPastDue: int, tenantsTrialing: int}
     */
    private function adminBadges(): array
    {
        // Badges de la consola de operador: cuentan tenants, así que cruzan
        // todos a propósito. Ver §2.1.
        $counts = TenantContext::withoutTenant(fn () => Subscription::query()
            ->whereIn('status', [SubscriptionStatus::PastDue, SubscriptionStatus::Trialing])
            ->selectRaw('status, count(*) as aggregate')
            ->groupBy('status')
            ->pluck('aggregate', 'status'));

        return [
            'tenantsPastDue' => (int) ($counts[SubscriptionStatus::PastDue->value] ?? 0),
            'tenantsTrialing' => (int) ($counts[SubscriptionStatus::Trialing->value] ?? 0),
        ];
    }
}
