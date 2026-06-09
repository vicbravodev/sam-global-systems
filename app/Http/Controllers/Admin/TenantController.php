<?php

namespace App\Http\Controllers\Admin;

use App\Actions\Teams\CreateTeam;
use App\Domains\Assets\Enums\AssetStatus;
use App\Domains\Assets\Models\Asset;
use App\Domains\Audit\Actions\RecordAuditEntry;
use App\Domains\Audit\Enums\AuditActorType;
use App\Domains\Audit\Enums\AuditCategory;
use App\Domains\Tenancy\Actions\CreateTenant;
use App\Domains\Tenancy\Actions\DeleteTenant;
use App\Domains\Tenancy\Actions\ResolveAssetLimit;
use App\Domains\Tenancy\Actions\UpdateTenant;
use App\Domains\Tenancy\Models\Plan;
use App\Domains\Tenancy\Models\Subscription;
use App\Domains\Tenancy\Models\TenantBranding;
use App\Domains\Tenancy\Models\TenantFeature;
use App\Domains\Tenancy\Models\TenantUsageCounter;
use App\Enums\TeamRole;
use App\Http\Controllers\Controller;
use App\Models\Team;
use App\Models\User;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection as BaseCollection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Password;
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
    public function __construct(
        private readonly CreateTenant $createTenant,
        private readonly CreateTeam $createTeam,
    ) {}

    public function index(): Response
    {
        $teams = Team::query()
            ->withCount('members')
            ->orderByDesc('id')
            ->get();

        $subscriptions = $this->latestSubscriptionsByTeam($teams->pluck('id')->all());

        $tenants = $teams->map(function (Team $team) use ($subscriptions) {
            $subscription = $subscriptions->get($team->id);

            return [
                'id' => (int) $team->id,
                'name' => (string) $team->name,
                'slug' => (string) $team->slug,
                'isPersonal' => (bool) $team->is_personal,
                'membersCount' => (int) $team->members_count,
                'plan' => $subscription?->plan?->name,
                'subscriptionStatus' => $subscription?->status->value,
                'createdAt' => $team->created_at?->toIso8601String(),
            ];
        })->values()->all();

        return Inertia::render('admin/tenants/index', [
            'tenants' => $tenants,
            'stats' => $this->stats($teams, $subscriptions),
            'plans' => fn () => $this->planOptions(),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'plan_code' => ['nullable', 'string', 'exists:plans,code'],
            'owner_email' => ['required', 'email', 'max:255'],
            'owner_name' => ['nullable', 'string', 'max:255'],
        ]);

        $owner = User::where('email', $data['owner_email'])->first()
            ?? $this->provisionOwner($data);

        $team = $this->createTenant->execute(
            name: $data['name'],
            owner: $owner,
            planCode: $data['plan_code'] ?? null,
        );

        return redirect()
            ->route('admin.tenants.show', $team)
            ->with('status', 'Tenant creado correctamente.');
    }

    public function show(Team $team, ResolveAssetLimit $resolveAssetLimit): Response
    {
        $subscription = Subscription::withoutGlobalScopes()
            ->with('plan')
            ->where('team_id', $team->id)
            ->orderByDesc('starts_at')
            ->first();

        $members = $team->members()->get()->map(fn (User $member) => [
            'id' => (int) $member->id,
            'name' => (string) $member->name,
            'email' => (string) $member->email,
            'role' => $member->pivot->role instanceof TeamRole
                ? $member->pivot->role->value
                : (string) $member->pivot->role,
        ])->values()->all();

        $features = TenantFeature::withoutGlobalScopes()
            ->where('team_id', $team->id)
            ->orderBy('feature_key')
            ->get()
            ->map(fn (TenantFeature $feature) => [
                'key' => (string) $feature->feature_key,
                'enabled' => (bool) $feature->enabled,
                'source' => $feature->source->value,
                'limits' => $feature->limits_json,
            ])->values()->all();

        $usage = TenantUsageCounter::withoutGlobalScopes()
            ->with('usageMeter')
            ->where('team_id', $team->id)
            ->orderByDesc('period_start')
            ->limit(20)
            ->get()
            ->map(fn (TenantUsageCounter $counter) => [
                'meter' => (string) ($counter->usageMeter?->name ?? $counter->usageMeter?->code ?? '—'),
                'periodStart' => $counter->period_start?->toDateString(),
                'consumed' => (int) $counter->consumed_value,
                'included' => (int) $counter->included_value,
                'overage' => (int) $counter->overage_value,
            ])->values()->all();

        $branding = TenantBranding::withoutGlobalScopes()
            ->where('team_id', $team->id)
            ->first();

        return Inertia::render('admin/tenants/show', [
            'tenant' => [
                'id' => (int) $team->id,
                'name' => (string) $team->name,
                'slug' => (string) $team->slug,
                'isPersonal' => (bool) $team->is_personal,
                'createdAt' => $team->created_at?->toIso8601String(),
                'branding' => [
                    'displayName' => $branding?->display_name,
                    'primaryColor' => $branding?->primary_color,
                    'secondaryColor' => $branding?->secondary_color,
                    'logoUrl' => $branding?->logo_url,
                ],
            ],
            'subscription' => $subscription ? [
                'status' => $subscription->status->value,
                'plan' => $subscription->plan?->name,
                'billingCycle' => $subscription->billing_cycle?->value,
                'startsAt' => $subscription->starts_at?->toIso8601String(),
                'trialEndsAt' => $subscription->trial_ends_at?->toIso8601String(),
                'renewsAt' => $subscription->renews_at?->toIso8601String(),
            ] : null,
            'members' => $members,
            'features' => $features,
            'usage' => $usage,
            'plans' => $this->planOptions(),
            'assetUsage' => [
                'limit' => $resolveAssetLimit->execute((int) $team->id),
                'current' => Asset::withoutGlobalScopes()
                    ->where('team_id', $team->id)
                    ->where('status', '!=', AssetStatus::Inactive)
                    ->count(),
            ],
        ]);
    }

    public function update(Request $request, Team $team, UpdateTenant $updateTenant, RecordAuditEntry $audit): RedirectResponse
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'display_name' => ['nullable', 'string', 'max:255'],
            'primary_color' => ['nullable', 'string', 'max:32'],
            'secondary_color' => ['nullable', 'string', 'max:32'],
            'logo_url' => ['nullable', 'string', 'max:2048'],
        ]);

        $updateTenant->execute($team, $data);

        $user = $request->user();
        $audit->execute(
            actorType: AuditActorType::User,
            actorId: (int) $user->id,
            action: 'tenant.updated',
            category: AuditCategory::Security,
            entityType: Team::class,
            entityId: (int) $team->id,
            summary: "Tenant {$team->name} actualizado.",
            teamId: (int) $team->id,
            metadata: ['actor_email' => $user->email],
            signature: 'tenant.updated:'.$team->id.':'.Str::uuid()->toString(),
            ipAddress: $request->ip(),
            userAgent: $request->userAgent(),
        );

        return redirect()->route('admin.tenants.show', $team)->with('status', 'Tenant actualizado.');
    }

    public function destroy(Request $request, Team $team, DeleteTenant $deleteTenant, RecordAuditEntry $audit): RedirectResponse
    {
        if ($team->is_personal) {
            throw ValidationException::withMessages([
                'tenant' => 'No se puede eliminar un equipo personal.',
            ]);
        }

        $user = $request->user();
        $name = $team->name;
        $teamId = (int) $team->id;

        $deleteTenant->execute($team);

        $audit->execute(
            actorType: AuditActorType::User,
            actorId: (int) $user->id,
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

        return redirect()->route('admin.tenants.index')->with('status', 'Tenant eliminado.');
    }

    /**
     * Latest subscription (any status) per team, keyed by team id.
     *
     * @param  array<int, int>  $teamIds
     * @return BaseCollection<int, Subscription>
     */
    private function latestSubscriptionsByTeam(array $teamIds): BaseCollection
    {
        return Subscription::withoutGlobalScopes()
            ->with('plan')
            ->whereIn('team_id', $teamIds)
            ->get()
            ->groupBy('team_id')
            ->map(fn ($group) => $group->sortByDesc('starts_at')->first());
    }

    /**
     * @param  Collection<int, Team>  $teams
     * @param  BaseCollection<int, Subscription>  $subscriptions
     * @return array<string, int>
     */
    private function stats(Collection $teams, BaseCollection $subscriptions): array
    {
        $tenantTeams = $teams->where('is_personal', false);

        $statusCount = fn (string $status): int => $tenantTeams
            ->filter(fn (Team $team) => $subscriptions->get($team->id)?->status->value === $status)
            ->count();

        return [
            'total' => $tenantTeams->count(),
            'active' => $statusCount('active'),
            'trialing' => $statusCount('trialing'),
            'pastDue' => $statusCount('past_due'),
        ];
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
                'code' => (string) $plan->code,
                'name' => (string) $plan->name,
            ])->all();
    }

    /**
     * Provision a brand-new owner when the given email is unknown. Keeps the
     * "every user has a personal team" invariant and emails a password-reset
     * link so the owner can set their own credentials.
     *
     * @param  array{owner_email: string, owner_name?: string|null}  $data
     */
    private function provisionOwner(array $data): User
    {
        if (empty($data['owner_name'])) {
            throw ValidationException::withMessages([
                'owner_name' => 'El nombre del propietario es obligatorio para crear un usuario nuevo.',
            ]);
        }

        return DB::transaction(function () use ($data) {
            $user = User::create([
                'name' => $data['owner_name'],
                'email' => $data['owner_email'],
                'password' => Str::password(32),
            ]);

            $this->createTeam->handle($user, $data['owner_name']."'s Team", isPersonal: true);

            Password::sendResetLink(['email' => $user->email]);

            return $user;
        });
    }
}
