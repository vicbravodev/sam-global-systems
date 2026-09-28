<?php

namespace Tests\Feature\Domains\Incidents;

use App\Domains\Access\Models\Role;
use App\Domains\Assets\Models\Asset;
use App\Domains\Drivers\Models\Driver;
use App\Domains\Incidents\Models\Incident;
use App\Models\Membership;
use App\Models\Team;
use App\Models\User;
use Database\Seeders\AccessSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Testing\TestResponse;
use Tests\Concerns\AssertsTenantIsolation;
use Tests\TestCase;

/**
 * Búsqueda de la paleta de comandos: incidentes recientes del tenant,
 * unidades (nombre, código, placa, VIN) y conductores (nombre, código de
 * empleado, teléfono), con aislamiento estricto entre teams y cada grupo
 * sólo si el rol puede abrir ese recurso.
 */
class CommandPaletteSearchTest extends TestCase
{
    use AssertsTenantIsolation;
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

    private function search(User $user, Team $team, string $query): TestResponse
    {
        return $this->actingAs($user)->getJson(
            route('palette.search', ['current_team' => $team->slug, 'q' => $query]),
        );
    }

    private function userWithRole(string $roleCode): User
    {
        $user = User::factory()->create();

        Membership::query()
            ->where('team_id', $user->currentTeam->id)
            ->where('user_id', $user->id)
            ->firstOrFail()
            ->update([
                'role' => 'member',
                'role_id' => Role::query()->where('code', $roleCode)->firstOrFail()->id,
            ]);

        return $user;
    }

    public function test_finds_assets_by_name_plate_and_vin(): void
    {
        $asset = Asset::factory()->create([
            'team_id' => $this->team->id,
            'name' => 'Volkswagen Crafter',
            'code' => 'U-115',
            'metadata_json' => ['license_plate' => 'P15-AEB', 'vin' => '3VWFE21C04M000001'],
        ]);
        Asset::factory()->create(['team_id' => $this->team->id, 'name' => 'Freightliner Cascadia']);

        foreach (['crafter', 'p15-aeb', '04M000001', 'U-115'] as $query) {
            $this->search($this->user, $this->team, $query)
                ->assertOk()
                ->assertJsonCount(1, 'assets')
                ->assertJsonPath('assets.0.id', $asset->id)
                ->assertJsonPath('assets.0.plate', 'P15-AEB');
        }
    }

    public function test_finds_drivers_by_name_and_employee_code(): void
    {
        $driver = Driver::factory()->create([
            'team_id' => $this->team->id,
            'full_name' => 'Ricardo Cavazos López',
            'employee_code' => 'OP-1009',
        ]);

        foreach (['cavazos', 'op-1009'] as $query) {
            $this->search($this->user, $this->team, $query)
                ->assertOk()
                ->assertJsonCount(1, 'drivers')
                ->assertJsonPath('drivers.0.id', $driver->id)
                ->assertJsonPath('drivers.0.employeeCode', 'OP-1009');
        }
    }

    public function test_empty_query_does_not_list_assets_or_drivers(): void
    {
        Asset::factory()->create(['team_id' => $this->team->id]);
        Driver::factory()->create(['team_id' => $this->team->id]);

        $this->search($this->user, $this->team, '')
            ->assertOk()
            ->assertJsonCount(0, 'assets')
            ->assertJsonCount(0, 'drivers');
    }

    public function test_search_never_leaks_units_or_drivers_of_another_tenant(): void
    {
        $other = Team::factory()->create();
        $foreignAsset = Asset::factory()->create([
            'team_id' => $other->id,
            'name' => 'Unidad compartida',
            'metadata_json' => ['license_plate' => 'SAME-001', 'vin' => 'VINSAME001'],
        ]);
        $foreignDriver = Driver::factory()->create([
            'team_id' => $other->id,
            'full_name' => 'Conductor Compartido',
            'employee_code' => 'SAME-001',
        ]);
        $ownAsset = Asset::factory()->create([
            'team_id' => $this->team->id,
            'name' => 'Unidad propia',
            'metadata_json' => ['license_plate' => 'SAME-001'],
        ]);

        $json = $this->assertNoTenantLeak($this->team, fn () => $this->search($this->user, $this->team, 'same-001')
            ->assertOk()
            ->json());

        $this->assertSame([$ownAsset->id], array_column($json['assets'], 'id'));
        $this->assertNotContains($foreignAsset->id, array_column($json['assets'], 'id'));
        $this->assertNotContains($foreignDriver->id, array_column($json['drivers'], 'id'));
        $this->assertSame([], $json['drivers']);
    }

    public function test_groups_are_limited_to_what_the_role_can_open(): void
    {
        // Gestor de facturación: sin incidents.view, assets.view ni drivers.view.
        $billing = $this->userWithRole('billing_manager');
        $team = $billing->currentTeam;
        Incident::factory()->create(['team_id' => $team->id, 'title' => 'Colisión']);
        Asset::factory()->create(['team_id' => $team->id, 'name' => 'Colisión unidad']);
        Driver::factory()->create(['team_id' => $team->id, 'full_name' => 'Colisión conductor']);

        $this->search($billing, $team, 'Colisión')
            ->assertOk()
            ->assertJsonCount(0, 'incidents')
            ->assertJsonCount(0, 'assets')
            ->assertJsonCount(0, 'drivers');

        // Analista: ve incidentes y unidades, pero no conductores.
        $analyst = $this->userWithRole('analyst');
        $team = $analyst->currentTeam;
        Asset::factory()->create(['team_id' => $team->id, 'name' => 'Kenworth T680']);
        Driver::factory()->create(['team_id' => $team->id, 'full_name' => 'Kenworth Pérez']);

        $this->search($analyst, $team, 'kenworth')
            ->assertOk()
            ->assertJsonCount(1, 'assets')
            ->assertJsonCount(0, 'drivers');
    }
}
