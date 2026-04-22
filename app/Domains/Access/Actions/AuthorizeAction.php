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
    ];

    private const TEAM_ROLE_FALLBACK_MAP = [
        'owner' => 'tenant_admin',
        'admin' => 'supervisor',
        'member' => 'viewer',
    ];

    public function execute(User $user, string $permissionCode, ?Team $team = null): bool
    {
        if ($user->isSuperAdmin()) {
            return true;
        }

        $team = $team ?? currentTeam();

        if (! $team) {
            return false;
        }

        $permissions = $this->resolvePermissions($user, $team);

        if (! in_array($permissionCode, $permissions)) {
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

        if (! $team) {
            return [];
        }

        $cacheKey = "access:perms:{$user->id}:{$team->id}";

        return Cache::remember($cacheKey, self::CACHE_TTL_SECONDS, function () use ($user, $team) {
            $role = $this->resolveRole($user, $team);

            if (! $role) {
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

        if (! $membership) {
            return null;
        }

        if ($membership->role_id) {
            return $membership->accessRole;
        }

        $fallbackCode = self::TEAM_ROLE_FALLBACK_MAP[$membership->getRawOriginal('role')] ?? null;

        if (! $fallbackCode) {
            return null;
        }

        return Role::where('code', $fallbackCode)->first();
    }

    private function checkSubscriptionAccess(Team $team, string $permissionCode): bool
    {
        $module = $this->extractModule($permissionCode);

        if (! in_array($module, self::OPERATIONAL_MODULES)) {
            return true;
        }

        $subscription = Subscription::withoutGlobalScopes()
            ->where('team_id', $team->id)
            ->latest('starts_at')
            ->first();

        if (! $subscription) {
            return true;
        }

        return $subscription->status->grantsOperationalAccess();
    }

    private function checkFeatureAccess(Team $team, string $permissionCode): bool
    {
        $module = $this->extractModule($permissionCode);

        $feature = TenantFeature::withoutGlobalScopes()
            ->where('team_id', $team->id)
            ->where('feature_key', $module)
            ->first();

        if (! $feature) {
            return true;
        }

        return $feature->enabled;
    }

    private function extractModule(string $permissionCode): string
    {
        return explode('.', $permissionCode, 2)[0];
    }
}
