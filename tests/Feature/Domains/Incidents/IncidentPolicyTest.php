<?php

namespace Tests\Feature\Domains\Incidents;

use App\Domains\Access\Enums\RoleScope;
use App\Domains\Access\Models\Permission;
use App\Domains\Access\Models\Role;
use App\Domains\Incidents\Models\Incident;
use App\Domains\Incidents\Models\IncidentType;
use App\Enums\TeamRole;
use App\Models\Team;
use App\Models\User;
use Database\Seeders\AccessSeeder;
use Database\Seeders\IncidentsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class IncidentPolicyTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(AccessSeeder::class);
        $this->seed(IncidentsSeeder::class);
    }

    public function test_member_without_incidents_view_cannot_list_incidents(): void
    {
        [$user, $team] = $this->createUserWithRole('custom_no_access', []);

        $response = $this->actingAs($user)->getJson("/api/{$team->slug}/incidents");

        $response->assertForbidden();
    }

    public function test_member_with_incidents_view_can_show_incident(): void
    {
        [$user, $team] = $this->createUserWithRole('custom_view_only', ['incidents.view']);
        $incident = Incident::factory()->create(['team_id' => $team->id]);

        $response = $this->actingAs($user)->getJson("/api/{$team->slug}/incidents/{$incident->id}");

        $response->assertOk();
    }

    public function test_view_only_member_cannot_create_manual_incident(): void
    {
        [$user, $team] = $this->createUserWithRole('custom_view_only_2', ['incidents.view']);
        $type = IncidentType::query()->where('code', 'panic_emergency')->first();

        $response = $this->actingAs($user)->postJson("/api/{$team->slug}/incidents", [
            'incident_type_id' => $type->id,
            'title' => 'No permission',
            'summary' => 'should fail',
        ]);

        $response->assertForbidden();
    }

    public function test_member_with_incidents_manage_can_create_manual_incident(): void
    {
        [$user, $team] = $this->createUserWithRole('custom_mgr', ['incidents.view', 'incidents.manage']);
        $type = IncidentType::query()->where('code', 'panic_emergency')->first();

        $response = $this->actingAs($user)->postJson("/api/{$team->slug}/incidents", [
            'incident_type_id' => $type->id,
            'title' => 'Allowed',
            'summary' => 'should pass',
        ]);

        $response->assertStatus(201);
    }

    public function test_member_with_incidents_resolve_can_resolve(): void
    {
        [$user, $team] = $this->createUserWithRole('custom_resolver', ['incidents.view', 'incidents.resolve']);
        $incident = Incident::factory()->create(['team_id' => $team->id]);

        $response = $this->actingAs($user)->postJson("/api/{$team->slug}/incidents/{$incident->id}/resolve", [
            'resolution_code' => 'handled_successfully',
            'summary' => 'done',
        ]);

        $response->assertStatus(201);
    }

    public function test_view_only_member_cannot_resolve(): void
    {
        [$user, $team] = $this->createUserWithRole('custom_viewer_resolve', ['incidents.view']);
        $incident = Incident::factory()->create(['team_id' => $team->id]);

        $response = $this->actingAs($user)->postJson("/api/{$team->slug}/incidents/{$incident->id}/resolve", [
            'resolution_code' => 'handled_successfully',
            'summary' => 'should fail',
        ]);

        $response->assertForbidden();
    }

    public function test_cross_tenant_incident_returns_404(): void
    {
        [$user, $team] = $this->createUserWithRole('custom_cross', ['incidents.view', 'incidents.manage']);

        $foreignOwner = User::factory()->create();
        $foreignIncident = Incident::factory()->create(['team_id' => $foreignOwner->currentTeam->id]);

        $response = $this->actingAs($user)->getJson("/api/{$team->slug}/incidents/{$foreignIncident->id}");

        $response->assertNotFound();
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
