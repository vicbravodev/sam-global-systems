<?php

namespace App\Domains\Access\Actions;

use App\Domains\Access\Models\Permission;
use App\Domains\Access\Models\Role;
use App\Domains\Tenancy\Models\Subscription;
use App\Domains\Tenancy\Models\TenantFeature;
use App\Models\Membership;
use App\Models\Team;
use App\Models\User;
use Illuminate\Support\Facades\Cache;

class AuthorizeAction
{
    private const CACHE_TTL_SECONDS = 300;

    private const OPERATIONAL_MODULES = [
        'incidents',
        'assets',
        'drivers',
        'ai',
        'automation',
        'copilot',
    ];

    private const TEAM_ROLE_FALLBACK_MAP = [
        'owner' => 'tenant_admin',
        'admin' => 'supervisor',
        'member' => 'viewer',
    ];

    /**
     * Per-request memo (the action is bound `scoped`): every policy check
     * used to re-query the subscription and the feature row, 4–10 times on
     * a typical Inertia page.
     *
     * @var array<int, array{subscription: Subscription|null, features: array<string, bool>}>
     */
    private array $teamAccess = [];

    public function execute(User $user, string $permissionCode, ?Team $team = null): bool
    {
        if ($user->isSuperAdmin()) {
            return true;
        }

        $team = $team ?? currentTeam();

        if ($team === null) {
            return false;
        }

        $permissions = $this->resolvePermissions($user, $team);

        if (! in_array($permissionCode, $permissions, true)) {
            return false;
        }

        if (! $this->checkSubscriptionAccess($team, $permissionCode)) {
            return false;
        }

        if (! $this->checkFeatureAccess($team, $permissionCode)) {
            return false;
        }

        return true;
    }

    /**
     * Resolve all permission codes the user has for the given team.
     *
     * @return array<string>
     */
    public function resolvePermissions(User $user, ?Team $team = null): array
    {
        if ($user->isSuperAdmin()) {
            return Permission::pluck('code')->all();
        }

        $team = $team ?? currentTeam();

        if ($team === null) {
            return [];
        }

        $cacheKey = "access:perms:{$user->id}:{$team->id}";

        return Cache::remember($cacheKey, self::CACHE_TTL_SECONDS, function () use ($user, $team) {
            $role = $this->resolveRole($user, $team);

            if ($role === null) {
                return [];
            }

            return $role->permissions()->pluck('code')->all();
        });
    }

    /**
     * Invalidate cached permissions for a user+team combination.
     */
    public function invalidateCache(int $userId, int $teamId): void
    {
        Cache::forget("access:perms:{$userId}:{$teamId}");
    }

    /**
     * Invalidate cached permissions for all memberships of a given role.
     */
    public function invalidateCacheForRole(Role $role): void
    {
        $role->memberships()->each(function (Membership $membership) {
            $this->invalidateCache($membership->user_id, $membership->team_id);
        });
    }

    private function resolveRole(User $user, Team $team): ?Role
    {
        $membership = Membership::where('team_id', $team->id)
            ->where('user_id', $user->id)
            ->first();

        if ($membership === null) {
            return null;
        }

        // role_id es FK a roles.id (secuencia): nunca vale 0.
        if ($membership->role_id !== null) {
            return $membership->accessRole;
        }

        $fallbackCode = self::TEAM_ROLE_FALLBACK_MAP[$membership->getRawOriginal('role')] ?? null;

        if ($fallbackCode === null) {
            return null;
        }

        return Role::where('code', $fallbackCode)->first();
    }

    private function checkSubscriptionAccess(Team $team, string $permissionCode): bool
    {
        $module = $this->extractModule($permissionCode);

        if (! in_array($module, self::OPERATIONAL_MODULES, true)) {
            return true;
        }

        $subscription = $this->teamAccess($team)['subscription'];

        if ($subscription === null) {
            return true;
        }

        return $subscription->status->grantsOperationalAccess();
    }

    private function checkFeatureAccess(Team $team, string $permissionCode): bool
    {
        $module = $this->extractModule($permissionCode);

        return $this->teamAccess($team)['features'][$module] ?? true;
    }

    /**
     * Drop the memoized subscription/features of a team (called when either
     * changes, so a check later in the same request sees the new state).
     */
    public function forgetTeamAccess(int $teamId): void
    {
        unset($this->teamAccess[$teamId]);
    }

    /**
     * @return array{subscription: Subscription|null, features: array<string, bool>}
     */
    private function teamAccess(Team $team): array
    {
        // Explicit team lookups, independent of the ambient tenant scope: the
        // target team may differ from the current one (or from a queued
        // TenantContext), and a scoped miss here would fail OPEN.
        return $this->teamAccess[$team->id] ??= [
            'subscription' => Subscription::withoutGlobalScopes()
                ->where('team_id', $team->id)
                ->latest('starts_at')
                ->first(),
            'features' => TenantFeature::withoutGlobalScopes()
                ->where('team_id', $team->id)
                ->get(['feature_key', 'enabled'])
                ->mapWithKeys(fn (TenantFeature $feature) => [$feature->feature_key => $feature->enabled])
                ->all(),
        ];
    }

    private function extractModule(string $permissionCode): string
    {
        return explode('.', $permissionCode, 2)[0];
    }
}
