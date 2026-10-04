<?php

namespace Tests\Feature\Domains\Drivers\Hos;

use App\Domains\Drivers\Enums\HosSituation;
use App\Domains\Drivers\Models\Driver;
use App\Domains\Drivers\Models\HosEpisode;
use App\Models\Team;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Las infracciones que el PR 1 dejó abiertas en producción ya ocurrieron
 * antes del despliegue: el primer sondeo del PR 2 no debe avisarle al chofer
 * ni levantar un incidente por ellas. RefreshDatabase ya corrió la migración
 * sobre la tabla vacía; aquí se siembran filas y se vuelve a correr `up()`.
 */
class HosOpenViolationBackfillTest extends TestCase
{
    use RefreshDatabase;

    private function migration(): object
    {
        return require database_path('migrations/2026_10_11_100300_mark_open_hos_violations_as_raised.php');
    }

    private function episode(Team $team, HosSituation $situation, array $attributes = []): HosEpisode
    {
        $driver = Driver::factory()->create(['team_id' => $team->id]);

        return HosEpisode::factory()->create([
            'team_id' => $team->id, 'driver_id' => $driver->id, 'situation' => $situation,
            'opened_at' => now()->subHour(), 'ladder_step' => 0, ...$attributes,
        ]);
    }

    public function test_only_open_violations_of_every_tenant_are_marked_as_already_raised(): void
    {
        [$team, $otherTeam] = [Team::factory()->create(), Team::factory()->create()];
        $open = $this->episode($team, HosSituation::Violation);
        $otherTenantOpen = $this->episode($otherTeam, HosSituation::Violation);
        $resolved = $this->episode($team, HosSituation::Violation, ['resolved_at' => now()]);
        $breakDue = $this->episode($team, HosSituation::BreakDue);
        $driveLimit = $this->episode($otherTeam, HosSituation::DriveLimit);

        $this->migration()->up();

        $this->assertSame(1, $open->fresh()->ladder_step);
        $this->assertSame(1, $otherTenantOpen->fresh()->ladder_step);
        $this->assertSame(0, $resolved->fresh()->ladder_step);
        $this->assertSame(0, $breakDue->fresh()->ladder_step);
        $this->assertSame(0, $driveLimit->fresh()->ladder_step);
        $this->assertNull($open->fresh()->next_nudge_at);
        $this->assertNull($open->fresh()->escalated_at);
    }

    public function test_running_it_again_never_moves_an_episode_back(): void
    {
        $team = Team::factory()->create();
        $open = $this->episode($team, HosSituation::Violation);
        // Ya insistiendo con el PR 2 (escalón 2): una segunda corrida no lo toca.
        $insisting = $this->episode($team, HosSituation::Violation, ['ladder_step' => 2, 'escalated_at' => now()]);

        $migration = $this->migration();
        $migration->up();
        $migration->up();
        $migration->down();

        $this->assertSame(1, $open->fresh()->ladder_step);
        $this->assertSame(2, $insisting->fresh()->ladder_step);
        $this->assertSame(2, DB::table('hos_episodes')->count());
    }
}
