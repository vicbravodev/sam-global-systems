<?php

namespace Tests\Feature\Domains\Incidents;

use App\Domains\Incidents\Actions\ClaimIncident;
use App\Domains\Incidents\Actions\ReleaseIncident;
use App\Domains\Incidents\Jobs\CheckIncidentAcknowledgementJob;
use App\Domains\Incidents\Models\Incident;
use App\Enums\TeamRole;
use App\Models\Team;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

class ClaimIncidentTest extends TestCase
{
    use RefreshDatabase;

    /**
     * Crea un usuario miembro del team dado y lo deja como su team actual
     * (`current_team_id`), que es lo que las acciones de claim/release
     * comparan contra el team dueño del incidente. `User::factory()` crea
     * su propio team personal por defecto, así que no basta con pasar
     * `current_team_id` al `create()` — hay que unir y cambiar de team
     * explícitamente (mismo patrón que el resto de tests de este dominio,
     * p. ej. AssignOnCallOnIncidentCreatedTest).
     */
    private function memberOf(Team $team): User
    {
        $user = User::factory()->create();
        $team->members()->attach($user, ['role' => TeamRole::Member->value]);
        $user->switchTeam($team);

        return $user;
    }

    public function test_first_claimer_wins_and_second_is_rejected(): void
    {
        $team = Team::factory()->create();
        $incident = Incident::factory()->create(['team_id' => $team->id]);
        $ana = $this->memberOf($team);
        $beto = $this->memberOf($team);

        $claim = app(ClaimIncident::class);

        $this->assertTrue($claim->execute($incident, $ana));
        $this->assertFalse($claim->execute($incident->fresh(), $beto));

        $this->assertSame($ana->id, $incident->fresh()->claimed_by_user_id);
        $this->assertNotNull($incident->fresh()->claimed_at);
    }

    public function test_reclaiming_by_the_same_user_is_allowed(): void
    {
        $team = Team::factory()->create();
        $incident = Incident::factory()->create(['team_id' => $team->id]);
        $ana = $this->memberOf($team);

        $claim = app(ClaimIncident::class);

        $this->assertTrue($claim->execute($incident, $ana));
        $this->assertTrue($claim->execute($incident->fresh(), $ana));
    }

    public function test_only_the_owner_can_release(): void
    {
        $team = Team::factory()->create();
        $incident = Incident::factory()->create(['team_id' => $team->id]);
        $ana = $this->memberOf($team);
        $beto = $this->memberOf($team);

        app(ClaimIncident::class)->execute($incident, $ana);
        $release = app(ReleaseIncident::class);

        $this->assertFalse($release->execute($incident->fresh(), $beto));
        $this->assertTrue($release->execute($incident->fresh(), $ana));
        $this->assertNull($incident->fresh()->claimed_by_user_id);
    }

    public function test_user_from_another_team_cannot_claim(): void
    {
        $teamA = Team::factory()->create();
        $teamB = Team::factory()->create();
        $incident = Incident::factory()->create(['team_id' => $teamA->id]);
        $intruder = $this->memberOf($teamB);

        $claim = app(ClaimIncident::class);

        $this->assertFalse($claim->execute($incident, $intruder));
        $this->assertNull($incident->fresh()->claimed_by_user_id);
    }

    public function test_user_from_another_team_cannot_release(): void
    {
        $teamA = Team::factory()->create();
        $teamB = Team::factory()->create();
        $incident = Incident::factory()->create(['team_id' => $teamA->id]);
        $ana = $this->memberOf($teamA);
        $intruder = $this->memberOf($teamB);

        app(ClaimIncident::class)->execute($incident, $ana);
        $release = app(ReleaseIncident::class);

        $this->assertFalse($release->execute($incident->fresh(), $intruder));
        $this->assertSame($ana->id, $incident->fresh()->claimed_by_user_id);
    }

    public function test_release_rearms_the_sla_watchdog(): void
    {
        Queue::fake();

        $team = Team::factory()->create();
        $incident = Incident::factory()->create([
            'team_id' => $team->id,
            'sla_due_at' => now()->subMinutes(5),
            'acknowledged_at' => null,
        ]);
        $ana = $this->memberOf($team);

        app(ClaimIncident::class)->execute($incident, $ana);
        $released = app(ReleaseIncident::class)->execute($incident->fresh(), $ana);

        $this->assertTrue($released);

        Queue::assertPushed(
            CheckIncidentAcknowledgementJob::class,
            fn (CheckIncidentAcknowledgementJob $job): bool => $job->incidentId === $incident->id
                && $job->level === 0
                && $job->attempt === 1,
        );
    }

    public function test_release_of_an_already_acknowledged_incident_does_not_rearm_the_watchdog(): void
    {
        Queue::fake();

        $team = Team::factory()->create();
        $incident = Incident::factory()->create([
            'team_id' => $team->id,
            'sla_due_at' => now()->subMinutes(5),
            'acknowledged_at' => now(),
        ]);
        $ana = $this->memberOf($team);

        app(ClaimIncident::class)->execute($incident, $ana);
        $released = app(ReleaseIncident::class)->execute($incident->fresh(), $ana);

        $this->assertTrue($released);

        Queue::assertNotPushed(CheckIncidentAcknowledgementJob::class);
    }
}
