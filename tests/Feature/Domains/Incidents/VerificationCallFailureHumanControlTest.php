<?php

namespace Tests\Feature\Domains\Incidents;

use App\Domains\Incidents\Actions\HandleVerificationCallAttemptFailure;
use App\Domains\Incidents\Enums\CallVerificationStatus;
use App\Domains\Incidents\Enums\IncidentStatusCode;
use App\Domains\Incidents\Models\Incident;
use App\Domains\Incidents\Models\IncidentCallVerification;
use App\Domains\Incidents\Models\IncidentStatus;
use App\Models\User;
use Database\Seeders\IncidentsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

/**
 * P1-9: una llamada de verificación sin respuesta no escala (ni reintenta)
 * si mientras sonaba un humano tomó el incidente o acusó recibo.
 */
class VerificationCallFailureHumanControlTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(IncidentsSeeder::class);
    }

    public function test_exhausted_attempts_do_not_escalate_a_claimed_incident(): void
    {
        Queue::fake();

        $user = User::factory()->create();
        $incident = $this->openIncident($user->currentTeam->id, ['claimed_by_user_id' => $user->id, 'claimed_at' => now()]);
        $verification = $this->lastAttempt($incident);

        app(HandleVerificationCallAttemptFailure::class)->execute($verification, 'no-answer');

        $this->assertSame(IncidentStatusCode::Open->value, $incident->fresh()->status->code);
        $this->assertSame(CallVerificationStatus::NoAnswer, $verification->fresh()->status);
    }

    public function test_exhausted_attempts_still_escalate_an_unattended_incident(): void
    {
        Queue::fake();

        $user = User::factory()->create();
        $incident = $this->openIncident($user->currentTeam->id);
        $verification = $this->lastAttempt($incident);

        app(HandleVerificationCallAttemptFailure::class)->execute($verification, 'no-answer');

        $this->assertSame(IncidentStatusCode::Escalated->value, $incident->fresh()->status->code);
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    private function openIncident(int $teamId, array $attributes = []): Incident
    {
        $open = IncidentStatus::query()->where('code', IncidentStatusCode::Open->value)->firstOrFail();

        return Incident::factory()->create(array_merge([
            'team_id' => $teamId,
            'incident_status_id' => $open->id,
        ], $attributes));
    }

    private function lastAttempt(Incident $incident): IncidentCallVerification
    {
        // Intento 3 = el presupuesto por defecto (voice.call_attempts) agotado.
        return IncidentCallVerification::factory()->calling()->create([
            'team_id' => $incident->team_id,
            'incident_id' => $incident->id,
            'attempt' => 3,
        ]);
    }
}
