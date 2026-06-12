<?php

namespace Tests\Feature\Domains\Incidents;

use App\Domains\Assets\Models\Asset;
use App\Domains\Incidents\Enums\AssigneeType;
use App\Domains\Incidents\Models\Incident;
use App\Domains\Incidents\Models\IncidentAssignment;
use App\Domains\Incidents\Models\IncidentStatus;
use App\Models\Team;
use App\Models\User;
use Database\Seeders\AccessSeeder;
use Database\Seeders\IncidentsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

/**
 * C1-b: el mismo incidente debe exponer EXACTAMENTE la misma cadena de estado
 * en las 4 superficies — bandeja, detalle, paleta de búsqueda y detalle de
 * activo — todas derivadas de IncidentStatusPresenter.
 */
class IncidentStatusConsistencyTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    private Team $team;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(AccessSeeder::class);
        $this->seed(IncidentsSeeder::class);

        $this->user = User::factory()->create();
        $this->team = $this->user->currentTeam;
    }

    public function test_escalated_incident_exposes_the_same_status_string_on_all_four_surfaces(): void
    {
        $asset = Asset::factory()->create(['team_id' => $this->team->id]);

        $escalated = IncidentStatus::query()->where('code', 'escalated')->firstOrFail();

        $incident = Incident::factory()->create([
            'team_id' => $this->team->id,
            'incident_status_id' => $escalated->id,
            'asset_id' => $asset->id,
            'title' => 'Colisión en ruta norte',
        ]);

        // 1) Bandeja: clave UI honesta + etiqueta renderizada.
        $inbox = $this->actingAs($this->user)->get(
            route('incidents.index', ['current_team' => $this->team->slug]),
        );
        $inbox->assertInertia(
            fn (Assert $page) => $page
                ->where('incidents.0.status', 'escalated')
                ->where('incidents.0.statusLabel', 'Escalado'),
        );

        // 2) Detalle del incidente (mismo presenter que la bandeja).
        $detail = $this->actingAs($this->user)->getJson(
            route('incidents.show', [
                'current_team' => $this->team->slug,
                'incident' => $incident->id,
            ]),
        );
        $detail->assertOk();
        $detail->assertJsonPath('status', 'escalated');
        $detail->assertJsonPath('statusLabel', 'Escalado');

        // 3) Paleta de búsqueda.
        $palette = $this->actingAs($this->user)->getJson(
            route('palette.search', [
                'current_team' => $this->team->slug,
                'q' => 'Colisión',
            ]),
        );
        $palette->assertOk();
        $palette->assertJsonPath('incidents.0.statusLabel', 'Escalado');

        // 4) Detalle del activo vinculado.
        $assetDetail = $this->actingAs($this->user)->get(
            route('assets.show', [
                'current_team' => $this->team->slug,
                'asset' => $asset->id,
            ]),
        );
        $assetDetail->assertInertia(
            fn (Assert $page) => $page
                ->where('incidents.0.id', $incident->id)
                ->where('incidents.0.status.code', 'escalated')
                ->where('incidents.0.status.name', 'Escalado'),
        );
    }

    public function test_assigned_open_incident_is_consistent_across_surfaces(): void
    {
        $asset = Asset::factory()->create(['team_id' => $this->team->id]);

        $open = IncidentStatus::query()->where('code', 'open')->firstOrFail();

        $incident = Incident::factory()->create([
            'team_id' => $this->team->id,
            'incident_status_id' => $open->id,
            'asset_id' => $asset->id,
            'title' => 'Exceso de velocidad sostenido',
        ]);

        IncidentAssignment::factory()->create([
            'incident_id' => $incident->id,
            'assigned_to_type' => AssigneeType::User,
            'assigned_to_id' => $this->user->id,
            'assigned_at' => now(),
            'unassigned_at' => null,
        ]);

        $inbox = $this->actingAs($this->user)->get(
            route('incidents.index', ['current_team' => $this->team->slug]),
        );
        $inbox->assertInertia(
            fn (Assert $page) => $page
                ->where('incidents.0.status', 'assigned')
                ->where('incidents.0.statusLabel', 'Asignado'),
        );

        $palette = $this->actingAs($this->user)->getJson(
            route('palette.search', [
                'current_team' => $this->team->slug,
                'q' => 'Exceso',
            ]),
        );
        $palette->assertOk();
        $palette->assertJsonPath('incidents.0.statusLabel', 'Asignado');

        $assetDetail = $this->actingAs($this->user)->get(
            route('assets.show', [
                'current_team' => $this->team->slug,
                'asset' => $asset->id,
            ]),
        );
        $assetDetail->assertInertia(
            fn (Assert $page) => $page
                ->where('incidents.0.status.name', 'Asignado'),
        );
    }
}
