<?php

namespace Tests\Feature\Http\TenantConfig;

use App\Domains\Incidents\Models\IncidentPriority;
use App\Enums\TeamRole;
use App\Models\Team;
use App\Models\User;
use Database\Seeders\AccessSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class IncidentSlaControllerTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        // `config.view` / `config.manage` sólo existen si se siembran los
        // roles/permisos del dominio Access (mismo patrón que
        // TenantConfigPageTest, que cubre la misma familia de pantallas).
        $this->seed(AccessSeeder::class);
    }

    /**
     * `User::factory()` crea su propio team personal por defecto, así que no
     * basta con pasar `current_team_id` al `create()` para dejarlo como
     * miembro del team dado — hay que unir y cambiar de team explícitamente
     * (mismo patrón que tests/Feature/Domains/Incidents/ClaimIncidentTest.php).
     */
    private function memberOf(Team $team): User
    {
        $user = User::factory()->create();
        $team->members()->attach($user, ['role' => TeamRole::Owner->value]);
        $user->switchTeam($team);

        return $user;
    }

    public function test_index_renders_priorities_with_effective_values(): void
    {
        $team = Team::factory()->create();
        $user = $this->memberOf($team);
        IncidentPriority::factory()->create(['code' => 'critical', 'sla_seconds' => 300]);

        $this->actingAs($user)
            ->get(route('tenant-config.slas.index', ['current_team' => $team->slug]))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('settings/tenant-config/slas')
                ->has('priorities', 1));
    }

    public function test_update_persists_override_for_current_team_only(): void
    {
        $team = Team::factory()->create();
        $other = Team::factory()->create();
        $user = $this->memberOf($team);
        $priority = IncidentPriority::factory()->create(['sla_seconds' => 1800]);

        $this->actingAs($user)
            ->put(route('tenant-config.slas.update', ['current_team' => $team->slug]), [
                'slas' => [
                    ['incident_priority_id' => $priority->id, 'sla_seconds' => 240],
                ],
            ])
            ->assertRedirect();

        $this->assertDatabaseHas('tenant_incident_slas', [
            'team_id' => $team->id,
            'incident_priority_id' => $priority->id,
            'sla_seconds' => 240,
        ]);
        $this->assertDatabaseMissing('tenant_incident_slas', ['team_id' => $other->id]);
    }

    public function test_guests_are_rejected(): void
    {
        $team = Team::factory()->create();

        $this->get(route('tenant-config.slas.index', ['current_team' => $team->slug]))
            ->assertRedirect(route('login'));
    }

    public function test_non_members_are_forbidden(): void
    {
        $team = Team::factory()->create();
        $outsider = User::factory()->create();

        $this->actingAs($outsider)
            ->get(route('tenant-config.slas.index', ['current_team' => $team->slug]))
            ->assertForbidden();
    }
}
