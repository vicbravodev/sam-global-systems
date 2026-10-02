<?php

namespace Tests\Feature\Security;

use App\Domains\Assets\Models\Asset;
use App\Domains\Incidents\Models\Incident;
use App\Enums\TeamRole;
use App\Models\Team;
use App\Models\User;
use Database\Seeders\AccessSeeder;
use Database\Seeders\IncidentsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\Concerns\AssertsTenantIsolation;
use Tests\TestCase;

/**
 * El binding de `{incident}`, `{asset}`… debe resolverse con el tenant del
 * `{current_team}` de la URL, no con el `current_team_id` guardado del
 * usuario: EnsureTeamMembership fija el TenantContext ANTES que
 * SubstituteBindings (prioridad de middleware en bootstrap/app.php).
 */
class TeamUrlRouteBindingTest extends TestCase
{
    use AssertsTenantIsolation, RefreshDatabase;

    private User $user;

    private Team $teamA;

    private Team $teamB;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(AccessSeeder::class);
        $this->seed(IncidentsSeeder::class);

        $this->user = User::factory()->create();
        $this->teamA = $this->user->currentTeam;

        $owner = User::factory()->create();
        $this->teamB = $owner->currentTeam;
    }

    private function joinTeamB(): void
    {
        $this->teamB->members()->attach($this->user, ['role' => TeamRole::Admin->value]);

        // El team guardado sigue siendo A: el deep link apunta a B.
        $this->assertSame($this->teamA->id, $this->user->fresh()->current_team_id);
    }

    public function test_web_deep_link_to_another_of_the_users_teams_resolves_the_incident(): void
    {
        $this->joinTeamB();
        $incident = Incident::factory()->open()->create(['team_id' => $this->teamB->id]);

        $this->actingAs($this->user)
            ->get("/{$this->teamB->slug}/incidents/{$incident->id}")
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('incidents/show')
                ->where('incident.incidentId', $incident->id));

        $this->assertSame($this->teamB->id, $this->user->fresh()->current_team_id);
    }

    public function test_api_deep_link_to_another_of_the_users_teams_resolves_bound_models(): void
    {
        $this->joinTeamB();
        $incident = Incident::factory()->open()->create(['team_id' => $this->teamB->id]);
        $asset = Asset::factory()->create(['team_id' => $this->teamB->id]);

        $this->actingAs($this->user)
            ->getJson("/api/{$this->teamB->slug}/incidents/{$incident->id}")
            ->assertOk()
            ->assertJsonPath('data.id', $incident->id);

        $this->actingAs($this->user)
            ->getJson("/api/{$this->teamB->slug}/assets/{$asset->id}")
            ->assertOk()
            ->assertJsonPath('data.id', $asset->id);
    }

    public function test_super_admin_impersonating_by_url_resolves_the_tenants_incident(): void
    {
        $admin = User::factory()->superAdmin()->create();
        $incident = Incident::factory()->open()->create(['team_id' => $this->teamB->id]);

        $this->actingAs($admin)
            ->getJson("/api/{$this->teamB->slug}/incidents/{$incident->id}")
            ->assertOk()
            ->assertJsonPath('data.id', $incident->id);
    }

    public function test_non_member_cannot_reach_another_teams_incident_by_its_slug(): void
    {
        $incident = Incident::factory()->open()->create(['team_id' => $this->teamB->id]);

        $this->assertNoTenantLeak($this->teamB, function () use ($incident): void {
            $this->actingAs($this->user)
                ->get("/{$this->teamB->slug}/incidents/{$incident->id}")
                ->assertForbidden();

            $this->actingAs($this->user)
                ->getJson("/api/{$this->teamB->slug}/incidents/{$incident->id}")
                ->assertForbidden();
        });
    }

    public function test_own_slug_with_a_foreign_incident_id_is_not_found(): void
    {
        $this->joinTeamB();
        $incident = Incident::factory()->open()->create(['team_id' => $this->teamB->id]);

        // Aun siendo miembro de B, la URL de A sólo resuelve modelos de A.
        $this->actingAs($this->user)
            ->get("/{$this->teamA->slug}/incidents/{$incident->id}")
            ->assertNotFound();

        $this->actingAs($this->user)
            ->getJson("/api/{$this->teamA->slug}/incidents/{$incident->id}")
            ->assertNotFound();
    }
}
