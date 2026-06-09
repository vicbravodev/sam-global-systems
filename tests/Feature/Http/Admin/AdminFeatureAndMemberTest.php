<?php

namespace Tests\Feature\Http\Admin;

use App\Domains\Tenancy\Models\TenantFeature;
use App\Enums\TeamRole;
use App\Models\Team;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AdminFeatureAndMemberTest extends TestCase
{
    use RefreshDatabase;

    private function superAdmin(): User
    {
        return User::factory()->create(['global_role' => 'super_admin']);
    }

    private function tenantWithOwner(): array
    {
        $team = Team::factory()->create(['is_personal' => false]);
        $owner = User::factory()->create();
        $team->members()->attach($owner, ['role' => TeamRole::Owner->value]);

        return [$team, $owner];
    }

    public function test_super_admin_toggles_a_feature_as_manual_override(): void
    {
        $admin = $this->superAdmin();
        [$team] = $this->tenantWithOwner();

        $this->actingAs($admin)
            ->put(route('admin.tenants.features.update', [$team, 'live_map']), [
                'enabled' => true,
                'included_quantity' => 50,
            ])
            ->assertRedirect(route('admin.tenants.show', $team));

        $feature = TenantFeature::withoutGlobalScopes()
            ->where('team_id', $team->id)
            ->where('feature_key', 'live_map')
            ->first();

        $this->assertNotNull($feature);
        $this->assertTrue($feature->enabled);
        $this->assertSame('manual_override', $feature->source->value);
        $this->assertSame(50, (int) $feature->limits_json['included_quantity']);

        $this->assertDatabaseHas('audit_logs', [
            'team_id' => $team->id,
            'action' => 'tenant.feature_updated',
        ]);
    }

    public function test_feature_toggle_blocked_for_regular_user(): void
    {
        $user = User::factory()->create();
        [$team] = $this->tenantWithOwner();

        $this->actingAs($user)
            ->put(route('admin.tenants.features.update', [$team, 'live_map']), ['enabled' => true])
            ->assertForbidden();
    }

    public function test_super_admin_adds_changes_and_removes_members(): void
    {
        $admin = $this->superAdmin();
        [$team] = $this->tenantWithOwner();
        $newcomer = User::factory()->create();

        // Add.
        $this->actingAs($admin)
            ->post(route('admin.tenants.members.store', $team), [
                'email' => $newcomer->email,
                'role' => 'member',
            ])
            ->assertRedirect();
        $this->assertTrue($team->members()->where('users.id', $newcomer->id)->exists());

        // Change role.
        $this->actingAs($admin)
            ->put(route('admin.tenants.members.update', [$team, $newcomer]), ['role' => 'admin'])
            ->assertRedirect();
        $this->assertSame(
            'admin',
            $team->memberships()->where('user_id', $newcomer->id)->first()->role->value,
        );

        // Remove.
        $this->actingAs($admin)
            ->delete(route('admin.tenants.members.destroy', [$team, $newcomer]))
            ->assertRedirect();
        $this->assertFalse($team->members()->where('users.id', $newcomer->id)->exists());
    }

    public function test_owner_cannot_be_removed(): void
    {
        $admin = $this->superAdmin();
        [$team, $owner] = $this->tenantWithOwner();

        $this->actingAs($admin)
            ->delete(route('admin.tenants.members.destroy', [$team, $owner]))
            ->assertSessionHasErrors('member');

        $this->assertTrue($team->members()->where('users.id', $owner->id)->exists());
    }

    public function test_make_owner_reassigns_and_demotes_previous_owner(): void
    {
        $admin = $this->superAdmin();
        [$team, $owner] = $this->tenantWithOwner();
        $member = User::factory()->create();
        $team->members()->attach($member, ['role' => TeamRole::Member->value]);

        $this->actingAs($admin)
            ->post(route('admin.tenants.members.make-owner', [$team, $member]))
            ->assertRedirect();

        $this->assertSame(
            'owner',
            $team->memberships()->where('user_id', $member->id)->first()->role->value,
        );
        $this->assertSame(
            'admin',
            $team->memberships()->where('user_id', $owner->id)->first()->role->value,
        );
    }
}
