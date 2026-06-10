<?php

namespace Tests\Feature\Domains\Tenancy;

use App\Domains\Access\Enums\RoleScope;
use App\Domains\Access\Models\Permission;
use App\Domains\Access\Models\Role;
use App\Domains\Tenancy\Models\Subscription;
use App\Domains\Tenancy\Models\TenantBranding;
use App\Domains\Tenancy\Models\TenantFeature;
use App\Enums\TeamRole;
use App\Models\Team;
use App\Models\User;
use Database\Seeders\AccessSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class TenancyPoliciesTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(AccessSeeder::class);
    }

    public function test_member_with_billing_view_can_view_subscription_but_not_update(): void
    {
        [$user, $team] = $this->createUserWithRole('billing_viewer', ['tenancy.billing.view']);
        $subscription = Subscription::factory()->create(['team_id' => $team->id]);

        $this->actingAs($user);

        $this->assertTrue($user->can('viewAny', Subscription::class));
        $this->assertTrue($user->can('view', $subscription));
        $this->assertFalse($user->can('update', $subscription));
    }

    public function test_member_with_billing_manage_can_update_subscription(): void
    {
        [$user, $team] = $this->createUserWithRole('billing_mgr', ['tenancy.billing.view', 'tenancy.billing.manage']);
        $subscription = Subscription::factory()->create(['team_id' => $team->id]);

        $this->actingAs($user);

        $this->assertTrue($user->can('update', $subscription));
    }

    public function test_member_without_billing_permissions_cannot_view_subscription(): void
    {
        [$user, $team] = $this->createUserWithRole('no_perms', []);
        $subscription = Subscription::factory()->create(['team_id' => $team->id]);

        $this->actingAs($user);

        $this->assertFalse($user->can('viewAny', Subscription::class));
        $this->assertFalse($user->can('view', $subscription));
        $this->assertFalse($user->can('update', $subscription));
    }

    public function test_subscription_of_another_team_is_denied_even_with_permission(): void
    {
        [$user] = $this->createUserWithRole('billing_mgr_cross', ['tenancy.billing.view', 'tenancy.billing.manage']);

        $foreignOwner = User::factory()->create();
        $foreignSubscription = Subscription::factory()->create(['team_id' => $foreignOwner->currentTeam->id]);

        $this->actingAs($user);

        $this->assertFalse($user->can('view', $foreignSubscription));
        $this->assertFalse($user->can('update', $foreignSubscription));
    }

    public function test_member_with_tenancy_manage_can_view_and_update_branding(): void
    {
        [$user, $team] = $this->createUserWithRole('brand_mgr', ['tenancy.manage']);
        $branding = TenantBranding::factory()->create(['team_id' => $team->id]);

        $this->actingAs($user);

        $this->assertTrue($user->can('view', $branding));
        $this->assertTrue($user->can('update', $branding));
    }

    public function test_member_without_tenancy_manage_cannot_touch_branding(): void
    {
        [$user, $team] = $this->createUserWithRole('no_brand', ['tenancy.billing.view']);
        $branding = TenantBranding::factory()->create(['team_id' => $team->id]);

        $this->actingAs($user);

        $this->assertFalse($user->can('view', $branding));
        $this->assertFalse($user->can('update', $branding));
    }

    public function test_branding_of_another_team_is_denied_even_with_permission(): void
    {
        [$user] = $this->createUserWithRole('brand_mgr_cross', ['tenancy.manage']);

        $foreignOwner = User::factory()->create();
        $foreignBranding = TenantBranding::factory()->create(['team_id' => $foreignOwner->currentTeam->id]);

        $this->actingAs($user);

        $this->assertFalse($user->can('view', $foreignBranding));
        $this->assertFalse($user->can('update', $foreignBranding));
    }

    public function test_member_with_tenancy_manage_can_view_and_update_features(): void
    {
        [$user, $team] = $this->createUserWithRole('feature_mgr', ['tenancy.manage']);
        $feature = TenantFeature::factory()->create(['team_id' => $team->id]);

        $this->actingAs($user);

        $this->assertTrue($user->can('viewAny', TenantFeature::class));
        $this->assertTrue($user->can('view', $feature));
        $this->assertTrue($user->can('update', $feature));
    }

    public function test_member_without_tenancy_manage_cannot_touch_features(): void
    {
        [$user, $team] = $this->createUserWithRole('no_features', ['tenancy.billing.view']);
        $feature = TenantFeature::factory()->create(['team_id' => $team->id]);

        $this->actingAs($user);

        $this->assertFalse($user->can('viewAny', TenantFeature::class));
        $this->assertFalse($user->can('view', $feature));
        $this->assertFalse($user->can('update', $feature));
    }

    public function test_feature_of_another_team_is_denied_even_with_permission(): void
    {
        [$user] = $this->createUserWithRole('feature_mgr_cross', ['tenancy.manage']);

        $foreignOwner = User::factory()->create();
        $foreignFeature = TenantFeature::factory()->create(['team_id' => $foreignOwner->currentTeam->id]);

        $this->actingAs($user);

        $this->assertFalse($user->can('view', $foreignFeature));
        $this->assertFalse($user->can('update', $foreignFeature));
    }

    public function test_guest_without_current_team_is_denied(): void
    {
        $user = User::factory()->create(['current_team_id' => null]);
        $user->teams()->detach();

        $this->actingAs($user);

        $this->assertFalse($user->can('viewAny', Subscription::class));
        $this->assertFalse($user->can('viewAny', TenantFeature::class));
    }

    /**
     * @param  array<string>  $permissionCodes
     * @return array{0: User, 1: Team}
     */
    private function createUserWithRole(string $roleCode, array $permissionCodes): array
    {
        $user = User::factory()->create();
        $team = $user->currentTeam;

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

        $team->members()->updateExistingPivot($user->id, [
            'role' => TeamRole::Member->value,
            'role_id' => $role->id,
        ]);

        return [$user, $team];
    }
}
