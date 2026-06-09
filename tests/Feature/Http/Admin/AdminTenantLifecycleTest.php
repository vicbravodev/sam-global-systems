<?php

namespace Tests\Feature\Http\Admin;

use App\Models\Team;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AdminTenantLifecycleTest extends TestCase
{
    use RefreshDatabase;

    private function superAdmin(): User
    {
        return User::factory()->create(['global_role' => 'super_admin']);
    }

    public function test_super_admin_updates_tenant_name_and_branding(): void
    {
        $admin = $this->superAdmin();
        $team = Team::factory()->create(['is_personal' => false, 'name' => 'Old']);

        $this->actingAs($admin)
            ->put(route('admin.tenants.update', $team), [
                'name' => 'New Name',
                'display_name' => 'New Brand',
                'primary_color' => '#2563eb',
            ])
            // Renaming regenerates the slug, so the redirect targets the fresh slug.
            ->assertRedirect(route('admin.tenants.show', $team->fresh()));

        $this->assertDatabaseHas('teams', ['id' => $team->id, 'name' => 'New Name']);
        $this->assertDatabaseHas('tenant_brandings', [
            'team_id' => $team->id,
            'display_name' => 'New Brand',
            'primary_color' => '#2563eb',
        ]);
        $this->assertDatabaseHas('audit_logs', [
            'team_id' => $team->id,
            'action' => 'tenant.updated',
        ]);
    }

    public function test_super_admin_soft_deletes_a_tenant(): void
    {
        $admin = $this->superAdmin();
        $team = Team::factory()->create(['is_personal' => false]);

        $this->actingAs($admin)
            ->delete(route('admin.tenants.destroy', $team))
            ->assertRedirect(route('admin.tenants.index'));

        $this->assertSoftDeleted('teams', ['id' => $team->id]);
        $this->assertDatabaseHas('audit_logs', [
            'team_id' => $team->id,
            'action' => 'tenant.deleted',
        ]);
    }

    public function test_personal_team_cannot_be_deleted(): void
    {
        $admin = $this->superAdmin();
        $personal = Team::factory()->create(['is_personal' => true]);

        $this->actingAs($admin)
            ->delete(route('admin.tenants.destroy', $personal))
            ->assertSessionHasErrors('tenant');

        $this->assertDatabaseHas('teams', ['id' => $personal->id, 'deleted_at' => null]);
    }

    public function test_lifecycle_routes_blocked_for_regular_users(): void
    {
        $user = User::factory()->create();
        $team = Team::factory()->create(['is_personal' => false]);

        $this->actingAs($user)
            ->put(route('admin.tenants.update', $team), ['name' => 'X'])
            ->assertForbidden();
    }
}
