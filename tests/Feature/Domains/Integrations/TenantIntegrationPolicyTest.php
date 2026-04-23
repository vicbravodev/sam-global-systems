<?php

namespace Tests\Feature\Domains\Integrations;

use App\Domains\Access\Actions\AuthorizeAction;
use App\Domains\Access\Enums\RoleScope;
use App\Domains\Access\Models\Permission;
use App\Domains\Access\Models\Role;
use App\Domains\Integrations\Models\TenantIntegration;
use App\Domains\Integrations\Policies\TenantIntegrationPolicy;
use App\Enums\TeamRole;
use App\Models\Membership;
use App\Models\Team;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class TenantIntegrationPolicyTest extends TestCase
{
    use RefreshDatabase;

    private function attachRoleWithPermissions(User $user, Team $team, array $codes): void
    {
        $role = Role::factory()->create([
            'code' => 'policy-test-role-'.uniqid(),
            'scope' => RoleScope::Tenant,
        ]);

        $permissionIds = [];
        foreach ($codes as $code) {
            $permission = Permission::firstOrCreate(
                ['code' => $code],
                ['name' => $code, 'module' => explode('.', $code, 2)[0]],
            );
            $permissionIds[] = $permission->id;
        }

        $role->permissions()->sync($permissionIds);

        if (! $user->belongsToTeam($team)) {
            $team->members()->attach($user, [
                'role' => TeamRole::Member->value,
                'role_id' => $role->id,
            ]);
        } else {
            Membership::where('user_id', $user->id)
                ->where('team_id', $team->id)
                ->update(['role_id' => $role->id]);
        }
    }

    public function test_view_any_requires_current_team_and_permission(): void
    {
        $user = User::factory()->create();
        $team = $user->currentTeam;

        $policy = app(TenantIntegrationPolicy::class);
        $this->actingAs($user);

        // No permission yet
        $this->assertFalse($policy->viewAny($user));

        // Grant integrations.view
        $this->attachRoleWithPermissions($user, $team, ['integrations.view']);
        app(AuthorizeAction::class)
            ->invalidateCache($user->id, $team->id);

        $this->assertTrue($policy->viewAny($user));
    }

    public function test_view_any_returns_false_without_current_team(): void
    {
        $user = User::factory()->create();
        $user->forceFill(['current_team_id' => null])->save();

        $this->actingAs($user);

        $this->assertFalse(app(TenantIntegrationPolicy::class)->viewAny($user));
    }

    public function test_view_rejects_integrations_from_other_teams(): void
    {
        $user = User::factory()->create();
        $team = $user->currentTeam;

        $this->attachRoleWithPermissions($user, $team, ['integrations.view']);
        $this->actingAs($user);

        $otherTeam = Team::factory()->create();
        $foreignIntegration = TenantIntegration::factory()->create([
            'team_id' => $otherTeam->id,
        ]);

        $ownIntegration = TenantIntegration::factory()->create([
            'team_id' => $team->id,
        ]);

        $policy = app(TenantIntegrationPolicy::class);

        $this->assertFalse(
            $policy->view($user, $foreignIntegration),
            'view() should reject integrations not owned by the current team',
        );

        $this->assertTrue(
            $policy->view($user, $ownIntegration),
            'view() should accept integrations owned by the current team',
        );
    }

    public function test_create_update_delete_require_manage_permission(): void
    {
        $user = User::factory()->create();
        $team = $user->currentTeam;

        $this->attachRoleWithPermissions($user, $team, ['integrations.view']);
        $this->actingAs($user);

        $integration = TenantIntegration::factory()->create(['team_id' => $team->id]);
        $policy = app(TenantIntegrationPolicy::class);

        $this->assertFalse(
            $policy->create($user),
            'create() should be denied without integrations.manage',
        );
        $this->assertFalse(
            $policy->update($user, $integration),
            'update() should be denied without integrations.manage',
        );
        $this->assertFalse(
            $policy->delete($user, $integration),
            'delete() should be denied without integrations.manage',
        );

        $this->attachRoleWithPermissions($user, $team, [
            'integrations.view',
            'integrations.manage',
        ]);
        app(AuthorizeAction::class)
            ->invalidateCache($user->id, $team->id);

        $this->assertTrue($policy->create($user));
        $this->assertTrue($policy->update($user, $integration));
        $this->assertTrue($policy->delete($user, $integration));
    }

    public function test_update_and_delete_reject_integrations_from_other_teams(): void
    {
        $user = User::factory()->create();
        $team = $user->currentTeam;

        $this->attachRoleWithPermissions($user, $team, [
            'integrations.view',
            'integrations.manage',
        ]);
        $this->actingAs($user);

        $otherTeam = Team::factory()->create();
        $foreignIntegration = TenantIntegration::factory()->create([
            'team_id' => $otherTeam->id,
        ]);

        $policy = app(TenantIntegrationPolicy::class);

        $this->assertFalse($policy->update($user, $foreignIntegration));
        $this->assertFalse($policy->delete($user, $foreignIntegration));
    }

    public function test_create_returns_false_without_current_team(): void
    {
        $user = User::factory()->create();
        $user->forceFill(['current_team_id' => null])->save();
        $this->actingAs($user);

        $this->assertFalse(app(TenantIntegrationPolicy::class)->create($user));
    }

    public function test_view_update_delete_return_false_without_current_team(): void
    {
        $user = User::factory()->create();
        $team = Team::factory()->create();
        $integration = TenantIntegration::factory()->create(['team_id' => $team->id]);

        $user->forceFill(['current_team_id' => null])->save();
        $this->actingAs($user);

        $policy = app(TenantIntegrationPolicy::class);

        $this->assertFalse($policy->view($user, $integration));
        $this->assertFalse($policy->update($user, $integration));
        $this->assertFalse($policy->delete($user, $integration));
    }
}
