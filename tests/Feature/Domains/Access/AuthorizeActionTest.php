<?php

namespace Tests\Feature\Domains\Access;

use App\Domains\Access\Actions\AssignRoleToMember;
use App\Domains\Access\Actions\AuthorizeAction;
use App\Domains\Access\Enums\RoleScope;
use App\Domains\Access\Models\Permission;
use App\Domains\Access\Models\Role;
use App\Domains\Tenancy\Enums\BillingCycle;
use App\Domains\Tenancy\Enums\FeatureSource;
use App\Domains\Tenancy\Enums\SubscriptionStatus;
use App\Domains\Tenancy\Models\Plan;
use App\Domains\Tenancy\Models\Subscription;
use App\Domains\Tenancy\Models\TenantFeature;
use App\Enums\TeamRole;
use App\Models\Membership;
use App\Models\Team;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Tests\TestCase;

class AuthorizeActionTest extends TestCase
{
    use RefreshDatabase;

    private AuthorizeAction $authorizeAction;

    protected function setUp(): void
    {
        parent::setUp();

        $this->authorizeAction = app(AuthorizeAction::class);
    }

    public function test_user_with_viewer_role_cannot_manage_incidents(): void
    {
        [$user, $team] = $this->createUserWithRole('viewer', ['incidents.view']);

        $this->assertTrue(
            $this->authorizeAction->execute($user, 'incidents.view', $team),
            'Viewer should be able to view incidents',
        );

        $this->assertFalse(
            $this->authorizeAction->execute($user, 'incidents.manage', $team),
            'Viewer should NOT be able to manage incidents — viewer role lacks incidents.manage permission',
        );
    }

    public function test_user_with_supervisor_role_can_resolve_incidents(): void
    {
        [$user, $team] = $this->createUserWithRole('supervisor', [
            'incidents.view',
            'incidents.manage',
            'incidents.resolve',
        ]);

        $this->assertTrue(
            $this->authorizeAction->execute($user, 'incidents.resolve', $team),
            'Supervisor should be able to resolve incidents',
        );
    }

    public function test_super_admin_bypasses_all_tenant_checks(): void
    {
        $user = User::factory()->create(['global_role' => 'super_admin']);
        $team = Team::factory()->create();

        Permission::factory()->create(['code' => 'incidents.manage', 'module' => 'incidents']);

        $this->assertTrue(
            $this->authorizeAction->execute($user, 'incidents.manage', $team),
            'Super admin should bypass all tenant-level permission checks regardless of team membership',
        );
    }

    public function test_user_has_different_roles_in_different_teams(): void
    {
        $user = User::factory()->create();

        $teamA = Team::factory()->create();
        $teamB = Team::factory()->create();

        $supervisorRole = $this->createRoleWithPermissions('supervisor_test', [
            'incidents.view',
            'incidents.manage',
            'incidents.resolve',
        ]);

        $viewerRole = $this->createRoleWithPermissions('viewer_test', [
            'incidents.view',
        ]);

        $teamA->members()->attach($user, ['role' => TeamRole::Admin->value, 'role_id' => $supervisorRole->id]);
        $teamB->members()->attach($user, ['role' => TeamRole::Member->value, 'role_id' => $viewerRole->id]);

        $this->assertTrue(
            $this->authorizeAction->execute($user, 'incidents.manage', $teamA),
            'User should have incidents.manage in Team A where they are a supervisor',
        );

        $this->assertFalse(
            $this->authorizeAction->execute($user, 'incidents.manage', $teamB),
            'User should NOT have incidents.manage in Team B where they are a viewer',
        );
    }

    public function test_suspended_subscription_restricts_operational_permissions(): void
    {
        [$user, $team] = $this->createUserWithRole('supervisor_suspended', [
            'incidents.manage',
            'tenancy.billing.view',
        ]);

        Subscription::withoutGlobalScopes()->create([
            'team_id' => $team->id,
            'plan_id' => Plan::factory()->create()->id,
            'status' => SubscriptionStatus::Suspended,
            'billing_cycle' => BillingCycle::Monthly,
            'starts_at' => now(),
        ]);

        $this->assertFalse(
            $this->authorizeAction->execute($user, 'incidents.manage', $team),
            'Suspended subscription should block operational permissions like incidents.manage',
        );

        $this->assertTrue(
            $this->authorizeAction->execute($user, 'tenancy.billing.view', $team),
            'Suspended subscription should NOT block non-operational permissions like tenancy.billing.view',
        );
    }

    public function test_permission_check_respects_tenant_features(): void
    {
        [$user, $team] = $this->createUserWithRole('feature_check', [
            'incidents.view',
            'assets.view',
        ]);

        TenantFeature::withoutGlobalScopes()->create([
            'team_id' => $team->id,
            'feature_key' => 'incidents',
            'enabled' => false,
            'source' => FeatureSource::DefaultPlan,
        ]);

        $this->assertFalse(
            $this->authorizeAction->execute($user, 'incidents.view', $team),
            'Disabled tenant feature should block the corresponding module permissions',
        );

        $this->assertTrue(
            $this->authorizeAction->execute($user, 'assets.view', $team),
            'Permissions for modules without a disabled feature should still be granted',
        );
    }

    public function test_fallback_to_team_role_enum_when_role_id_is_null(): void
    {
        $user = User::factory()->create();
        $team = Team::factory()->create();

        $team->members()->attach($user, ['role' => TeamRole::Owner->value]);

        $tenantAdminRole = $this->createRoleWithPermissions('tenant_admin', [
            'incidents.view',
            'incidents.manage',
        ]);

        $this->assertTrue(
            $this->authorizeAction->execute($user, 'incidents.view', $team),
            'Owner without role_id should fall back to tenant_admin role and have incidents.view',
        );

        $this->assertTrue(
            $this->authorizeAction->execute($user, 'incidents.manage', $team),
            'Owner without role_id should fall back to tenant_admin role and have incidents.manage',
        );
    }

    public function test_permission_cache_is_invalidated_on_role_change(): void
    {
        [$user, $team] = $this->createUserWithRole('cache_role', ['incidents.view']);

        $this->authorizeAction->execute($user, 'incidents.view', $team);

        $this->assertTrue(
            Cache::has("access:perms:{$user->id}:{$team->id}"),
            'Permission cache key should be set after first authorization check',
        );

        $newRole = $this->createRoleWithPermissions('upgraded_role', [
            'incidents.view',
            'incidents.manage',
        ]);

        $membership = Membership::where('user_id', $user->id)->where('team_id', $team->id)->first();

        app(AssignRoleToMember::class)->execute($membership, 'upgraded_role');

        $this->assertFalse(
            Cache::has("access:perms:{$user->id}:{$team->id}"),
            'Permission cache should be invalidated after role assignment change',
        );
    }

    /**
     * @param  array<string>  $permissionCodes
     * @return array{0: User, 1: Team}
     */
    private function createUserWithRole(string $roleCode, array $permissionCodes): array
    {
        $user = User::factory()->create();
        $team = Team::factory()->create();
        $role = $this->createRoleWithPermissions($roleCode, $permissionCodes);

        $team->members()->attach($user, [
            'role' => TeamRole::Member->value,
            'role_id' => $role->id,
        ]);

        return [$user, $team];
    }

    /**
     * @param  array<string>  $permissionCodes
     */
    private function createRoleWithPermissions(string $roleCode, array $permissionCodes): Role
    {
        $role = Role::factory()->create([
            'code' => $roleCode,
            'scope' => RoleScope::Tenant,
        ]);

        $permissionIds = [];
        foreach ($permissionCodes as $code) {
            $permission = Permission::firstOrCreate(
                ['code' => $code],
                [
                    'name' => ucfirst(str_replace('.', ' ', $code)),
                    'module' => explode('.', $code, 2)[0],
                ],
            );
            $permissionIds[] = $permission->id;
        }

        $role->permissions()->sync($permissionIds);

        return $role;
    }
}
