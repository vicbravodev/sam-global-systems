<?php

namespace Tests\Feature\Domains\Access;

use App\Domains\Access\Actions\AuthorizeAction;
use App\Domains\Access\Enums\RoleScope;
use App\Domains\Access\Models\Permission;
use App\Domains\Access\Models\Role;
use App\Enums\TeamRole;
use App\Models\Membership;
use App\Models\Team;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Tests\TestCase;

class AuthorizeActionEdgeCasesTest extends TestCase
{
    use RefreshDatabase;

    public function test_execute_returns_false_when_no_team_can_be_resolved(): void
    {
        $user = User::factory()->create();
        $user->forceFill(['current_team_id' => null])->save();

        $this->assertFalse(
            app(AuthorizeAction::class)->execute($user, 'incidents.view'),
            'execute() without team should return false when user has no current_team',
        );
    }

    public function test_resolve_permissions_returns_every_permission_for_super_admin(): void
    {
        $user = User::factory()->create(['global_role' => 'super_admin']);

        Permission::factory()->create(['code' => 'incidents.view', 'module' => 'incidents']);
        Permission::factory()->create(['code' => 'assets.manage', 'module' => 'assets']);

        $permissions = app(AuthorizeAction::class)->resolvePermissions($user);

        $this->assertContains('incidents.view', $permissions);
        $this->assertContains('assets.manage', $permissions);
    }

    public function test_resolve_permissions_returns_empty_when_no_team_context(): void
    {
        $user = User::factory()->create();
        $user->forceFill(['current_team_id' => null])->save();

        $this->assertEquals(
            [],
            app(AuthorizeAction::class)->resolvePermissions($user),
        );
    }

    public function test_resolve_permissions_returns_empty_when_user_has_no_membership(): void
    {
        $user = User::factory()->create();
        $team = Team::factory()->create();

        $permissions = app(AuthorizeAction::class)->resolvePermissions($user, $team);

        $this->assertEquals(
            [],
            $permissions,
            'Users without a membership row for the given team should have zero permissions',
        );
    }

    public function test_invalidate_cache_for_role_clears_all_memberships(): void
    {
        Permission::factory()->create(['code' => 'incidents.view', 'module' => 'incidents']);

        $role = Role::factory()->create([
            'code' => 'cache-clearing-role',
            'scope' => RoleScope::Tenant,
        ]);

        $userA = User::factory()->create();
        $userB = User::factory()->create();
        $teamA = $userA->currentTeam;
        $teamB = $userB->currentTeam;

        Membership::where('user_id', $userA->id)->update(['role_id' => $role->id]);
        Membership::where('user_id', $userB->id)->update(['role_id' => $role->id]);

        $authorize = app(AuthorizeAction::class);

        // prime the cache for both users
        $authorize->execute($userA, 'incidents.view', $teamA);
        $authorize->execute($userB, 'incidents.view', $teamB);

        $this->assertTrue(Cache::has("access:perms:{$userA->id}:{$teamA->id}"));
        $this->assertTrue(Cache::has("access:perms:{$userB->id}:{$teamB->id}"));

        $authorize->invalidateCacheForRole($role);

        $this->assertFalse(Cache::has("access:perms:{$userA->id}:{$teamA->id}"));
        $this->assertFalse(Cache::has("access:perms:{$userB->id}:{$teamB->id}"));
    }

    public function test_membership_with_no_role_id_and_no_fallback_mapping_returns_no_permissions(): void
    {
        $user = User::factory()->create();
        $team = Team::factory()->create();

        // Attach with a raw pivot value that is not in TEAM_ROLE_FALLBACK_MAP
        $team->members()->attach($user, ['role' => TeamRole::Member->value]);

        Membership::where('user_id', $user->id)
            ->where('team_id', $team->id)
            ->update(['role' => 'unmapped-legacy-role']);

        Permission::factory()->create(['code' => 'incidents.view', 'module' => 'incidents']);

        $this->assertFalse(
            app(AuthorizeAction::class)->execute($user, 'incidents.view', $team),
            'A membership without role_id and outside the legacy fallback map should yield no permissions',
        );
    }
}
