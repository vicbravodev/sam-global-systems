<?php

namespace App\Http\Middleware;

use App\Domains\Access\Actions\AuthorizeAction;
use App\Domains\Access\Models\Role;
use App\Domains\Analytics\Models\KpiRecord;
use App\Domains\Audit\Models\AuditLog;
use App\Domains\Automation\Models\AutomationWorkflow;
use App\Domains\Decisions\Models\DecisionRule;
use App\Domains\Drivers\Models\Driver;
use App\Domains\Incidents\Models\Incident;
use App\Domains\Integrations\Models\TenantIntegration;
use App\Domains\Normalization\Models\NormalizedEvent;
use App\Domains\Notifications\Channels\WebPushMessenger;
use App\Domains\Notifications\Models\Notification;
use App\Domains\Tenancy\Enums\SubscriptionStatus;
use App\Domains\Tenancy\Models\Subscription;
use App\Domains\Tenancy\Support\TenantContactReadiness;
use App\Domains\TenantConfig\Models\TenantSetting;
use App\Models\User;
use App\Support\NavBadgeCache;
use App\Support\TenantContext;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Gate;
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
        $team = fn () => $user !== null ? currentTeam() : null;

        return [
            ...parent::share($request),
            'name' => config('app.name'),
            'auth' => [
                // Sin relaciones: resolver el team actual carga `currentTeam` en el
                // modelo y no debe viajar al navegador (puede ser un team ajeno).
                'user' => $user?->withoutRelations(),
                'permissions' => fn () => $user !== null
                    ? app(AuthorizeAction::class)->resolvePermissions($user, $team())
                    : [],
            ],
            'sidebarOpen' => ! $request->hasCookie('sidebar_state') || $request->cookie('sidebar_state') === 'true',
            'currentTeam' => fn () => $user !== null && ($current = $team()) !== null ? $user->toUserTeam($current) : null,
            'teams' => fn () => $user?->toUserTeams(includeCurrent: true) ?? [],
            // Surfaces the impersonation banner: a super-admin whose current team
            // is one they do NOT belong to is, by definition, impersonating it.
            'impersonation' => fn () => $user !== null
                && $user->isSuperAdmin()
                && $user->currentTeam !== null
                && ! $user->belongsToTeam($user->currentTeam)
                    ? ['active' => true, 'team' => [
                        'name' => $user->currentTeam->name,
                        'slug' => $user->currentTeam->slug,
                    ]]
                    : null,
            // Cross-tenant counters for the super-admin console badges. Only
            // resolved (and only queried) for the SaaS operator.
            'adminBadges' => fn () => $user?->isSuperAdmin() === true
                ? $this->adminBadges()
                : null,
            // SAM Copilot availability for the sidebar entry and the floating
            // bubble. Resolved through AuthorizeAction so the tenant feature
            // flag and the subscription state are honoured, not just the role.
            // Llave pública VAPID para suscribir este dispositivo; null si la
            // plataforma no tiene avisos al dispositivo configurados.
            'webPush' => fn () => [
                'publicKey' => app(WebPushMessenger::class)->isConfigured()
                    ? (string) config('webpush.vapid.public_key')
                    : null,
            ],
            'copilot' => fn () => $user !== null && ($current = $team()) !== null
                ? [
                    'enabled' => app(AuthorizeAction::class)->execute($user, 'copilot.use', $current),
                    'canViewUsage' => app(AuthorizeAction::class)->execute($user, 'copilot.usage.view', $current),
                ]
                : null,
            // Which workspace sections the user may open. Resolved through the
            // same policies the page controllers authorize with, so the
            // sidebar never links to a page that answers 403.
            'nav' => fn () => $user !== null && $team() !== null
                ? $this->navPermissions($user)
                : null,
            // Canal de arranque del cliente (decisión 2026-09-28): si ningún
            // admin/supervisor tiene teléfono Y correo verificados, SAM no
            // tiene a quién avisar de una emergencia. Sólo se calcula para
            // quien puede arreglarlo (gestiona incidentes).
            'tenantSetup' => fn () => $user !== null && ($current = $team()) !== null
                && app(AuthorizeAction::class)->execute($user, 'incidents.manage', $current)
                    ? Cache::remember(
                        'tenant_setup:'.$current->id,
                        60,
                        fn () => TenantContactReadiness::for($current->id),
                    )
                    : null,
            // Tenant-scoped counters for the workspace sidebar badges.
            'navBadges' => fn () => ($current = $team()) !== null
                ? $this->navBadges($current->id)
                : null,
        ];
    }

    /**
     * @return array<string, bool>
     */
    private function navPermissions(User $user): array
    {
        $gate = Gate::forUser($user);

        return [
            'incidents' => $gate->allows('viewAny', Incident::class),
            'events' => $gate->allows('viewAny', NormalizedEvent::class),
            'drivers' => $gate->allows('viewAny', Driver::class),
            'rules' => $gate->allows('viewAny', DecisionRule::class),
            'automation' => $gate->allows('viewAny', AutomationWorkflow::class),
            'analytics' => $gate->allows('viewAny', KpiRecord::class),
            'integrations' => $gate->allows('viewAny', TenantIntegration::class),
            'notifications' => $gate->allows('viewAny', Notification::class),
            'audit' => $gate->allows('viewAny', AuditLog::class),
            'billing' => $gate->allows('viewAny', Subscription::class),
            'tenantConfig' => $gate->allows('viewAny', TenantSetting::class),
            'roles' => $gate->allows('viewAny', Role::class),
        ];
    }

    /**
     * @return array{inbox: int}
     */
    private function navBadges(int $teamId): array
    {
        return Cache::remember(
            NavBadgeCache::key($teamId),
            NavBadgeCache::TTL_SECONDS,
            fn (): array => TenantContext::for($teamId, fn (): array => [
                'inbox' => Incident::query()->open()->count(),
            ]),
        );
    }

    /**
     * @return array{tenantsPastDue: int}
     */
    private function adminBadges(): array
    {
        // Badges de la consola de operador: cuentan tenants, así que cruzan
        // todos a propósito. Ver §2.1.
        $counts = TenantContext::withoutTenant(fn () => Subscription::query()
            ->where('status', SubscriptionStatus::PastDue)
            ->selectRaw('status, count(*) as aggregate')
            ->groupBy('status')
            ->pluck('aggregate', 'status'));

        return [
            'tenantsPastDue' => (int) ($counts[SubscriptionStatus::PastDue->value] ?? 0),
        ];
    }
}
