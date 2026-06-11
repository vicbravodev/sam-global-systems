<?php

namespace Tests\Feature\Domains\Incidents;

use App\Domains\Incidents\Models\Incident;
use App\Models\Team;
use App\Models\User;
use Database\Seeders\AccessSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Búsqueda de la paleta de comandos: incidentes recientes del tenant,
 * filtrables por título, con aislamiento estricto entre teams.
 */
class CommandPaletteSearchTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    private Team $team;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(AccessSeeder::class);

        $this->user = User::factory()->create();
        $this->team = $this->user->currentTeam;
    }

    public function test_returns_recent_incidents_matching_query(): void
    {
        Incident::factory()->create([
            'team_id' => $this->team->id,
            'title' => 'Colisión frontal detectada',
        ]);
        Incident::factory()->create([
            'team_id' => $this->team->id,
            'title' => 'Exceso de velocidad',
        ]);

        $response = $this->actingAs($this->user)->getJson(
            route('palette.search', [
                'current_team' => $this->team->slug,
                'q' => 'Colisión',
            ]),
        );

        $response->assertOk();
        $response->assertJsonCount(1, 'incidents');
        $response->assertJsonPath('incidents.0.title', 'Colisión frontal detectada');
    }

    public function test_never_returns_incidents_from_another_tenant(): void
    {
        Incident::factory()->create([
            'team_id' => Team::factory()->create()->id,
            'title' => 'Incidente ajeno',
        ]);

        $response = $this->actingAs($this->user)->getJson(
            route('palette.search', ['current_team' => $this->team->slug]),
        );

        $response->assertOk();
        $response->assertJsonCount(0, 'incidents');
    }

    public function test_guest_cannot_search(): void
    {
        $response = $this->getJson(
            route('palette.search', ['current_team' => $this->team->slug]),
        );

        $response->assertUnauthorized();
    }
}
