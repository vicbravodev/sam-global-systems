<?php

namespace App\Http\Controllers\Admin;

use App\Domains\Assets\Enums\AssetMonitoringState;
use App\Domains\Assets\Models\Asset;
use App\Domains\Audit\Actions\RecordAuditEntry;
use App\Domains\Audit\Enums\AuditActorType;
use App\Domains\Audit\Enums\AuditCategory;
use App\Domains\Integrations\Enums\TenantIntegrationStatus;
use App\Domains\Integrations\Models\TenantIntegration;
use App\Domains\Tenancy\Actions\DeleteTenant;
use App\Domains\Tenancy\Actions\OnboardTenant;
use App\Domains\Tenancy\Actions\ResolveAssetLimit;
use App\Domains\Tenancy\Actions\ResolveBillingTerms;
use App\Domains\Tenancy\Actions\ResolveTenantSetupStatus;
use App\Domains\Tenancy\Actions\UpdateTenant;
use App\Domains\Tenancy\Models\InvoiceSnapshot;
use App\Domains\Tenancy\Models\Plan;
use App\Domains\Tenancy\Models\Subscription;
use App\Domains\Tenancy\Models\TenantBranding;
use App\Domains\Tenancy\Models\TenantFeature;
use App\Domains\Tenancy\Models\TenantUsageCounter;
use App\Domains\Tenancy\Support\CostPlusPricing;
use App\Enums\TeamRole;
use App\Http\Controllers\Controller;
use App\Models\Membership;
use App\Models\Team;
use App\Models\User;
use App\Support\TenantContext;
use Illuminate\Container\Attributes\CurrentUser;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection as BaseCollection;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Cross-tenant tenant directory for the SaaS operator (super-admin). All queries
 * deliberately bypass the BelongsToTenant global scope: Team is the tenant and is
 * not tenant-scoped, while Subscription/Feature/UsageCounter are read with
 * `withoutGlobalScopes()` so the operator sees every tenant, not just their own.
 */
class TenantController extends Controller
{
    public function index(): Response
    {
        // Personal teams are a per-user workspace invariant, not customers:
        // the tenant directory lists only real (non-personal) tenants.
        $teams = Team::query()
            ->where('is_personal', false)
            ->withCount('members')
            ->orderByDesc('id')
            ->get();

        $teamIds = $teams->pluck('id')->all();
        $subscriptions = $this->latestSubscriptionsByTeam($teamIds);
        $owners = $this->ownersByTeam($teamIds);
        // Directorio de la consola: cruza tenants a propósito (§2.1).
        $integrations = TenantContext::withoutTenant(fn () => TenantIntegration::query()
            ->whereIn('team_id', $teamIds)
            ->where('status', TenantIntegrationStatus::Active)
            ->selectRaw('team_id, COUNT(*) as aggregate')
            ->groupBy('team_id')
            ->pluck('aggregate', 'team_id'));
        $monitored = TenantContext::withoutTenant(fn () => Asset::query()
            ->whereIn('team_id', $teamIds)
            ->where('monitoring_state', AssetMonitoringState::Monitored)
            ->selectRaw('team_id, COUNT(*) as aggregate')
            ->groupBy('team_id')
            ->pluck('aggregate', 'team_id'));

        $tenants = $teams->map(function (Team $team) use ($subscriptions, $owners, $integrations, $monitored) {
            $subscription = $subscriptions->get($team->id);
            $owner = $owners->get($team->id);
            $integrationsCount = (int) ($integrations[$team->id] ?? 0);
            $monitoredCount = (int) ($monitored[$team->id] ?? 0);

            return [
                'id' => $team->id,
                'name' => $team->name,
                'slug' => $team->slug,
                'isPersonal' => $team->is_personal,
                'membersCount' => $team->members_count,
                'owner' => $owner === null ? null : [
                    'name' => $owner->name,
                    'email' => $owner->email,
                    'pendingAccess' => $owner->email_verified_at === null,
                ],
                'plan' => $subscription?->plan?->name,
                'subscriptionStatus' => $subscription?->status->value,
                'integrationsCount' => $integrationsCount,
                'monitoredAssets' => $monitoredCount,
                'stage' => $this->onboardingStage($owner, $integrationsCount, $monitoredCount),
                'createdAt' => $team->created_at?->toIso8601String(),
            ];
        })->values();

        return Inertia::render('admin/tenants/index', [
            'tenants' => $tenants->all(),
            'stats' => [
                'total' => $tenants->count(),
                'operating' => $tenants->where('stage', 'operating')->count(),
                'onboarding' => $tenants->where('stage', '!=', 'operating')->count(),
                'pastDue' => $tenants->where('subscriptionStatus', 'past_due')->count(),
                'suspended' => $tenants->where('subscriptionStatus', 'suspended')->count(),
            ],
            'plans' => fn () => $this->planOptions(),
        ]);
    }

    public function store(Request $request, OnboardTenant $onboardTenant, #[CurrentUser] User $actor): RedirectResponse
    {
        if (is_string($request->input('owner_email'))) {
            $request->merge(['owner_email' => User::normalizeEmail($request->input('owner_email'))]);
        }

        /** @var array{name: string, owner_email: string, owner_name?: string|null, plan_code?: string|null, timezone?: string|null} $data */
        $data = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'plan_code' => ['nullable', 'string', 'exists:plans,code'],
            'owner_email' => ['required', 'email', 'max:255'],
            'owner_name' => ['nullable', 'string', 'max:255'],
            'timezone' => ['nullable', 'string', 'timezone:all'],
        ]);

        $team = $onboardTenant->execute($data, $actor, $request->ip(), $request->userAgent());

        $owner = $team->owner();

        $this->toast($owner !== null && $owner->email_verified_at === null
            ? "Cliente {$team->name} creado. Enviamos a {$owner->email} su enlace de acceso."
            : "Cliente {$team->name} creado.");

        return redirect()->route('admin.tenants.show', $team);
    }

    public function show(Team $team, ResolveAssetLimit $resolveAssetLimit, ResolveBillingTerms $resolveBillingTerms, ResolveTenantSetupStatus $resolveSetup): Response
    {
        // El operador está viendo UN tenant: entrar en él hace que todo lo
        // que se lea aquí sea suyo, sin depender de filtros a mano. Ver §2.1.
        return TenantContext::for($team->id, function () use ($team, $resolveAssetLimit, $resolveBillingTerms, $resolveSetup) {
            $subscription = Subscription::query()
                ->with('plan')
                ->where('team_id', $team->id)
                ->orderByDesc('starts_at')
                ->first();

            $members = $team->members()->get()->map(function (User $member): array {
                // El pivot (Membership) llega como relación hidratada por
                // BelongsToMany::using(); se lee tipado en vez de vía $pivot.
                $pivot = $member->getRelation('pivot');

                return [
                    'id' => $member->id,
                    'name' => $member->name,
                    'email' => $member->email,
                    'role' => $pivot instanceof Membership ? $pivot->role->value : '',
                    // Dado de alta desde la consola y aún sin usar su enlace.
                    'pendingAccess' => $member->email_verified_at === null,
                ];
            })->values()->all();

            $features = TenantFeature::query()
                ->where('team_id', $team->id)
                ->orderBy('feature_key')
                ->get()
                ->map(fn (TenantFeature $feature) => [
                    'key' => $feature->feature_key,
                    'enabled' => $feature->enabled,
                    'source' => $feature->source->value,
                    'limits' => $feature->limits_json,
                ])->values()->all();

            $usage = TenantUsageCounter::query()
                ->with('usageMeter')
                ->where('team_id', $team->id)
                ->orderByDesc('period_start')
                ->limit(20)
                ->get()
                ->map(fn (TenantUsageCounter $counter) => [
                    'meter' => $counter->usageMeter?->name ?? $counter->usageMeter?->code ?? '—',
                    'meterCode' => $counter->usageMeter?->code,
                    'periodStart' => $counter->period_start?->toDateString(),
                    'consumed' => $counter->consumed_value,
                    'included' => $counter->included_value,
                    'overage' => $counter->overage_value,
                    // Cost-plus meters (Twilio): provider cost and what the
                    // tenant is charged, in USD — not a raw micro count.
                    'money' => $counter->usageMeter?->unit === CostPlusPricing::MICRO_UNIT
                        ? [
                            'providerCost' => CostPlusPricing::providerCost((float) $counter->consumed_value),
                            'charged' => CostPlusPricing::charged(
                                (float) $counter->consumed_value,
                                CostPlusPricing::markupFor($team->id, $counter->usage_meter_id),
                            ),
                        ]
                        : null,
                ])->values()->all();

            $branding = TenantBranding::query()
                ->where('team_id', $team->id)
                ->first();

            return Inertia::render('admin/tenants/show', [
                'tenant' => [
                    'id' => $team->id,
                    'name' => $team->name,
                    'slug' => $team->slug,
                    'isPersonal' => $team->is_personal,
                    'timezone' => $team->timezone,
                    'createdAt' => $team->created_at?->toIso8601String(),
                    'branding' => [
                        'displayName' => $branding?->display_name,
                        'primaryColor' => $branding?->primary_color,
                        'secondaryColor' => $branding?->secondary_color,
                        'logoUrl' => $branding?->logo_url,
                    ],
                ],
                'subscription' => $subscription !== null ? [
                    'status' => $subscription->status->value,
                    'plan' => $subscription->plan?->name,
                    'billingCycle' => $subscription->billing_cycle?->value,
                    'startsAt' => $subscription->starts_at?->toIso8601String(),
                    'renewsAt' => $subscription->renews_at?->toIso8601String(),
                ] : null,
                'members' => $members,
                'setup' => $resolveSetup->execute($team),
                'features' => $features,
                'usage' => $usage,
                'invoices' => InvoiceSnapshot::query()
                    ->where('team_id', $team->id)
                    ->orderByDesc('period_start')
                    ->limit(12)
                    ->get()
                    ->map(fn ($invoice) => [
                        'id' => $invoice->id,
                        'periodStart' => $invoice->period_start?->toDateString(),
                        'periodEnd' => $invoice->period_end?->toDateString(),
                        'total' => (float) $invoice->total,
                        'currency' => $invoice->currency,
                        'status' => $invoice->status->value,
                        'hasReceipt' => $invoice->payment_receipt_file_object_id !== null,
                        'paidAt' => $invoice->paid_at?->toDateString(),
                    ])->values()->all(),
                'plans' => $this->planOptions(),
                'assetUsage' => $this->assetUsage($team, $resolveAssetLimit),
                'billingTerms' => $resolveBillingTerms->execute($team->id)->toArray(),
                'billingDefaults' => [
                    'currency' => config('billing.currency'),
                    'unit_price' => (float) config('billing.unit_price'),
                    'min_billable_assets' => (int) config('billing.min_billable_assets'),
                    'ai_fair_use_per_asset' => (int) config('billing.ai_fair_use_per_asset'),
                    'ai_overage_unit_price' => (float) config('billing.ai_overage_unit_price'),
                    'fx_usd_rate' => (float) config('billing.fx_usd_rate'),
                ],
            ]);
        });
    }

    public function update(Request $request, Team $team, UpdateTenant $updateTenant, RecordAuditEntry $audit, #[CurrentUser] User $user): RedirectResponse
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'display_name' => ['nullable', 'string', 'max:255'],
            'primary_color' => ['nullable', 'string', 'regex:/^#[0-9a-fA-F]{6}$/'],
            'secondary_color' => ['nullable', 'string', 'regex:/^#[0-9a-fA-F]{6}$/'],
            'logo_url' => ['nullable', 'string', 'max:2048'],
            'timezone' => ['nullable', 'string', 'timezone:all'],
        ], [
            'primary_color.regex' => 'El color primario debe ser un hex de 6 dígitos (ej. #2563eb).',
            'secondary_color.regex' => 'El color secundario debe ser un hex de 6 dígitos (ej. #2563eb).',
        ]);

        $updateTenant->execute($team, $data);

        $audit->execute(
            actorType: AuditActorType::User,
            actorId: $user->id,
            action: 'tenant.updated',
            category: AuditCategory::Security,
            entityType: Team::class,
            entityId: $team->id,
            summary: "Tenant {$team->name} actualizado.",
            teamId: $team->id,
            metadata: ['actor_email' => $user->email],
            signature: 'tenant.updated:'.$team->id.':'.Str::uuid()->toString(),
            ipAddress: $request->ip(),
            userAgent: $request->userAgent(),
        );

        $this->toast('Cliente actualizado.');

        return redirect()->route('admin.tenants.show', $team);
    }

    public function destroy(Request $request, Team $team, DeleteTenant $deleteTenant, RecordAuditEntry $audit, #[CurrentUser] User $user): RedirectResponse
    {
        if ($team->is_personal) {
            throw ValidationException::withMessages([
                'tenant' => 'No se puede eliminar un equipo personal.',
            ]);
        }

        $name = $team->name;
        $teamId = $team->id;

        $deleteTenant->execute($team);

        $audit->execute(
            actorType: AuditActorType::User,
            actorId: $user->id,
            action: 'tenant.deleted',
            category: AuditCategory::Security,
            entityType: Team::class,
            entityId: $teamId,
            summary: "Tenant {$name} eliminado (soft-delete).",
            teamId: $teamId,
            metadata: ['actor_email' => $user->email, 'team_name' => $name],
            signature: 'tenant.deleted:'.$teamId.':'.Str::uuid()->toString(),
            ipAddress: $request->ip(),
            userAgent: $request->userAgent(),
        );

        $this->toast('Cliente eliminado.');

        return redirect()->route('admin.tenants.index');
    }

    /**
     * Unidades vigiladas contra el tope contratado, más las que el sync dejó
     * pendientes. Tope suave: `current > limit` es excedente cobrado, no bloqueo.
     *
     * @return array{limit: int|null, current: int, pending: int, excluded: int}
     */
    private function assetUsage(Team $team, ResolveAssetLimit $resolveAssetLimit): array
    {
        $byState = Asset::query()
            ->where('team_id', $team->id)
            ->selectRaw('monitoring_state, COUNT(*) as aggregate')
            ->groupBy('monitoring_state')
            ->pluck('aggregate', 'monitoring_state');

        return [
            'limit' => $resolveAssetLimit->execute($team->id),
            'current' => (int) ($byState[AssetMonitoringState::Monitored->value] ?? 0),
            'pending' => (int) ($byState[AssetMonitoringState::Pending->value] ?? 0),
            'excluded' => (int) ($byState[AssetMonitoringState::Excluded->value] ?? 0),
        ];
    }

    /**
     * Latest subscription (any status) per team, keyed by team id.
     *
     * @param  array<int, int>  $teamIds
     * @return BaseCollection<int, Subscription>
     */
    private function latestSubscriptionsByTeam(array $teamIds): BaseCollection
    {
        // Listado de la consola de operador: cruza tenants a propósito.
        return TenantContext::withoutTenant(fn () => Subscription::query()
            ->with('plan')
            ->whereIn('team_id', $teamIds)
            ->get()
            ->groupBy('team_id')
            ->map(fn ($group) => $group->sortByDesc('starts_at')->first()));
    }

    /**
     * Dónde va el alta del cliente: primero su dueño entra, luego conecta su
     * proveedor, luego vigila unidades. Sólo entonces está operando.
     */
    private function onboardingStage(?User $owner, int $integrations, int $monitoredAssets): string
    {
        return match (true) {
            $owner === null || $owner->email_verified_at === null => 'owner_pending',
            $integrations === 0 => 'integration_pending',
            $monitoredAssets === 0 => 'assets_pending',
            default => 'operating',
        };
    }

    /**
     * @param  array<int, int>  $teamIds
     * @return BaseCollection<int, User>
     */
    private function ownersByTeam(array $teamIds): BaseCollection
    {
        $owners = new BaseCollection;

        Membership::query()
            ->whereIn('team_id', $teamIds)
            ->where('role', TeamRole::Owner->value)
            ->with('user')
            ->get()
            ->each(function (Membership $membership) use ($owners): void {
                if ($membership->user instanceof User) {
                    $owners->put($membership->team_id, $membership->user);
                }
            });

        return $owners;
    }

    /**
     * @return array<int, array<string, string>>
     */
    private function planOptions(): array
    {
        return Plan::query()
            ->where('is_active', true)
            ->orderBy('name')
            ->get(['code', 'name'])
            ->map(fn (Plan $plan) => [
                'code' => $plan->code,
                'name' => $plan->name,
            ])->all();
    }
}
