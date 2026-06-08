<?php

namespace Tests\Feature\Http\Admin;

use App\Models\Team;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ImpersonationTest extends TestCase
{
    use RefreshDatabase;

    private function superAdmin(): User
    {
        return User::factory()->create(['global_role' => 'super_admin']);
    }

    public function test_super_admin_can_impersonate_a_tenant(): void
    {
        $admin = $this->superAdmin();
        $team = Team::factory()->create(['is_personal' => false]);

        $this->actingAs($admin)
            ->post(route('admin.impersonate.store', $team))
            ->assertRedirect(route('dashboard', $team));

        $this->assertSame($team->id, $admin->fresh()->current_team_id);
        $this->assertDatabaseHas('audit_logs', [
            'team_id' => $team->id,
            'action' => 'impersonation.started',
        ]);
    }

    public function test_super_admin_can_open_a_foreign_tenant_page_while_impersonating(): void
    {
        $admin = $this->superAdmin();
        $team = Team::factory()->create(['is_personal' => false]);

        // Not a member, yet the EnsureTeamMembership super-admin branch lets them in.
        $this->actingAs($admin)
            ->get(route('dashboard', $team))
            ->assertOk();

        $this->assertSame($team->id, $admin->fresh()->current_team_id);
    }

    public function test_stopping_impersonation_returns_to_personal_team(): void
    {
        $admin = $this->superAdmin();
        $personalId = $admin->personalTeam()->id;
        $team = Team::factory()->create(['is_personal' => false]);
        $admin->forceSwitchTeam($team);

        $this->actingAs($admin)
            ->delete(route('admin.impersonate.destroy'))
            ->assertRedirect(route('admin.tenants.index'));

        $this->assertSame($personalId, $admin->fresh()->current_team_id);
        $this->assertDatabaseHas('audit_logs', [
            'action' => 'impersonation.stopped',
        ]);
    }

    public function test_regular_user_cannot_impersonate(): void
    {
        $user = User::factory()->create();
        $team = Team::factory()->create(['is_personal' => false]);

        $this->actingAs($user)
            ->post(route('admin.impersonate.store', $team))
            ->assertForbidden();
    }

    public function test_regular_user_still_blocked_from_foreign_tenant_pages(): void
    {
        $user = User::factory()->create();
        $team = Team::factory()->create(['is_personal' => false]);

        $this->actingAs($user)
            ->get(route('dashboard', $team))
            ->assertForbidden();
    }
}
