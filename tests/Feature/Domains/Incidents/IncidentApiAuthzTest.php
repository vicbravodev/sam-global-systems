<?php

namespace Tests\Feature\Domains\Incidents;

use App\Domains\Access\Enums\RoleScope;
use App\Domains\Access\Models\Permission;
use App\Domains\Access\Models\Role;
use App\Domains\Incidents\Models\Incident;
use App\Domains\Incidents\Models\IncidentEventLink;
use App\Domains\Normalization\Models\NormalizedEvent;
use App\Enums\TeamRole;
use App\Models\Team;
use App\Models\User;
use Database\Seeders\AccessSeeder;
use Database\Seeders\IncidentsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Endpoint-level authz for the incident API routes that had no feature
 * coverage: PUT update, POST evidence and POST link-event (plus the missing
 * 403 for assign). Happy path + 403 without permission + cross-team 404.
 */
class IncidentApiAuthzTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(AccessSeeder::class);
        $this->seed(IncidentsSeeder::class);
    }

    public function test_update_changes_title_for_member_with_manage(): void
    {
        [$user, $team] = $this->createUserWithRole('authz_mgr_1', ['incidents.view', 'incidents.manage']);
        $incident = Incident::factory()->create(['team_id' => $team->id, 'title' => 'Original']);

        $response = $this->actingAs($user)->putJson(
            "/api/{$team->slug}/incidents/{$incident->id}",
            ['title' => 'Actualizado'],
        );

        $response->assertOk();
        $this->assertSame('Actualizado', $incident->fresh()->title);
    }

    public function test_update_is_forbidden_without_manage(): void
    {
        [$user, $team] = $this->createUserWithRole('authz_view_1', ['incidents.view']);
        $incident = Incident::factory()->create(['team_id' => $team->id, 'title' => 'Original']);

        $response = $this->actingAs($user)->putJson(
            "/api/{$team->slug}/incidents/{$incident->id}",
            ['title' => 'Hackeado'],
        );

        $response->assertForbidden();
        $this->assertSame('Original', $incident->fresh()->title);
    }

    public function test_update_of_foreign_incident_is_404(): void
    {
        [$user, $team] = $this->createUserWithRole('authz_mgr_2', ['incidents.view', 'incidents.manage']);

        $foreignOwner = User::factory()->create();
        $foreign = Incident::factory()->create([
            'team_id' => $foreignOwner->currentTeam->id,
            'title' => 'Ajeno',
        ]);

        $response = $this->actingAs($user)->putJson(
            "/api/{$team->slug}/incidents/{$foreign->id}",
            ['title' => 'Robado'],
        );

        $response->assertNotFound();
        $this->assertSame('Ajeno', $foreign->fresh()->title);
    }

    public function test_evidence_is_attached_for_member_with_manage(): void
    {
        [$user, $team] = $this->createUserWithRole('authz_mgr_3', ['incidents.view', 'incidents.manage']);
        $incident = Incident::factory()->create(['team_id' => $team->id]);

        $response = $this->actingAs($user)->postJson(
            "/api/{$team->slug}/incidents/{$incident->id}/evidence",
            [
                'evidence_type' => 'event_snapshot',
                'source_type' => 'manual_upload',
                'title' => 'Captura del evento',
            ],
        );

        $response->assertCreated();
        $this->assertSame(1, $incident->evidence()->count());
    }

    public function test_evidence_is_forbidden_without_manage(): void
    {
        [$user, $team] = $this->createUserWithRole('authz_view_2', ['incidents.view']);
        $incident = Incident::factory()->create(['team_id' => $team->id]);

        $response = $this->actingAs($user)->postJson(
            "/api/{$team->slug}/incidents/{$incident->id}/evidence",
            [
                'evidence_type' => 'event_snapshot',
                'source_type' => 'manual_upload',
            ],
        );

        $response->assertForbidden();
        $this->assertSame(0, $incident->evidence()->count());
    }

    public function test_evidence_on_foreign_incident_is_404(): void
    {
        [$user, $team] = $this->createUserWithRole('authz_mgr_4', ['incidents.view', 'incidents.manage']);

        $foreignOwner = User::factory()->create();
        $foreign = Incident::factory()->create(['team_id' => $foreignOwner->currentTeam->id]);

        $response = $this->actingAs($user)->postJson(
            "/api/{$team->slug}/incidents/{$foreign->id}/evidence",
            [
                'evidence_type' => 'event_snapshot',
                'source_type' => 'manual_upload',
            ],
        );

        $response->assertNotFound();
        $this->assertSame(0, $foreign->evidence()->count());
    }

    public function test_link_event_links_own_team_event(): void
    {
        [$user, $team] = $this->createUserWithRole('authz_mgr_5', ['incidents.view', 'incidents.manage']);
        $incident = Incident::factory()->create(['team_id' => $team->id]);
        $event = NormalizedEvent::factory()->create(['team_id' => $team->id]);

        $response = $this->actingAs($user)->postJson(
            "/api/{$team->slug}/incidents/{$incident->id}/link-event",
            [
                'normalized_event_id' => $event->id,
                'relation_type' => 'supporting_event',
            ],
        );

        $response->assertCreated();
        $this->assertSame(1, IncidentEventLink::withoutGlobalScopes()
            ->where('incident_id', $incident->id)
            ->where('normalized_event_id', $event->id)
            ->count());
    }

    public function test_link_event_is_forbidden_without_manage(): void
    {
        [$user, $team] = $this->createUserWithRole('authz_view_3', ['incidents.view']);
        $incident = Incident::factory()->create(['team_id' => $team->id]);
        $event = NormalizedEvent::factory()->create(['team_id' => $team->id]);

        $response = $this->actingAs($user)->postJson(
            "/api/{$team->slug}/incidents/{$incident->id}/link-event",
            [
                'normalized_event_id' => $event->id,
                'relation_type' => 'supporting_event',
            ],
        );

        $response->assertForbidden();
        $this->assertSame(0, IncidentEventLink::withoutGlobalScopes()->count());
    }

    public function test_link_event_rejects_foreign_team_event_with_404(): void
    {
        [$user, $team] = $this->createUserWithRole('authz_mgr_6', ['incidents.view', 'incidents.manage']);
        $incident = Incident::factory()->create(['team_id' => $team->id]);

        $foreignOwner = User::factory()->create();
        $foreignEvent = NormalizedEvent::factory()->create([
            'team_id' => $foreignOwner->currentTeam->id,
        ]);

        $response = $this->actingAs($user)->postJson(
            "/api/{$team->slug}/incidents/{$incident->id}/link-event",
            [
                'normalized_event_id' => $foreignEvent->id,
                'relation_type' => 'supporting_event',
            ],
        );

        $response->assertNotFound();
        $this->assertSame(0, IncidentEventLink::withoutGlobalScopes()->count());
    }

    public function test_link_event_on_foreign_incident_is_404(): void
    {
        [$user, $team] = $this->createUserWithRole('authz_mgr_7', ['incidents.view', 'incidents.manage']);
        $event = NormalizedEvent::factory()->create(['team_id' => $team->id]);

        $foreignOwner = User::factory()->create();
        $foreign = Incident::factory()->create(['team_id' => $foreignOwner->currentTeam->id]);

        $response = $this->actingAs($user)->postJson(
            "/api/{$team->slug}/incidents/{$foreign->id}/link-event",
            [
                'normalized_event_id' => $event->id,
                'relation_type' => 'supporting_event',
            ],
        );

        $response->assertNotFound();
        $this->assertSame(0, IncidentEventLink::withoutGlobalScopes()->count());
    }

    public function test_assign_is_forbidden_without_manage(): void
    {
        [$user, $team] = $this->createUserWithRole('authz_view_4', ['incidents.view']);
        $incident = Incident::factory()->create(['team_id' => $team->id]);

        $response = $this->actingAs($user)->postJson(
            "/api/{$team->slug}/incidents/{$incident->id}/assign",
            [
                'assigned_to_type' => 'user',
                'assigned_to_id' => $user->id,
            ],
        );

        $response->assertForbidden();
        $this->assertSame(0, $incident->assignments()->count());
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
