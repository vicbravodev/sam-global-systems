<?php

namespace Tests\Feature\Domains\Incidents;

use App\Domains\Incidents\Actions\StartIncidentCallVerification;
use App\Domains\Incidents\Enums\CallVerificationStatus;
use App\Domains\Incidents\Jobs\CheckIncidentAcknowledgementJob;
use App\Domains\Incidents\Jobs\PlaceVerificationCallJob;
use App\Domains\Incidents\Models\Incident;
use App\Domains\Incidents\Models\IncidentCallVerification;
use App\Domains\Incidents\Support\IncidentSuppression;
use App\Models\Team;
use App\Models\User;
use Database\Seeders\IncidentsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Tests\Concerns\AssertsSystemLog;
use Tests\TestCase;

class HumanControlSuppressionTest extends TestCase
{
    use AssertsSystemLog, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(IncidentsSeeder::class);
    }

    public function test_claimed_incident_is_under_human_control(): void
    {
        $team = Team::factory()->create();
        $user = User::factory()->create(['current_team_id' => $team->id]);
        $incident = Incident::factory()->create([
            'team_id' => $team->id,
            'claimed_by_user_id' => $user->id,
            'claimed_at' => now(),
        ]);

        $this->assertTrue(IncidentSuppression::isUnderHumanControl($incident));
    }

    public function test_acknowledged_incident_is_under_human_control(): void
    {
        $incident = Incident::factory()->create(['acknowledged_at' => now()]);

        $this->assertTrue(IncidentSuppression::isUnderHumanControl($incident));
    }

    public function test_untouched_incident_is_not_under_human_control(): void
    {
        $incident = Incident::factory()->create([
            'claimed_by_user_id' => null,
            'claimed_at' => null,
            'acknowledged_at' => null,
        ]);

        $this->assertFalse(IncidentSuppression::isUnderHumanControl($incident));
    }

    public function test_escalation_watchdog_stops_when_incident_is_claimed(): void
    {
        $team = Team::factory()->create();
        $user = User::factory()->create(['current_team_id' => $team->id]);
        $incident = Incident::factory()->create([
            'team_id' => $team->id,
            'sla_due_at' => now()->subMinutes(5),
            'acknowledged_at' => null,
            'claimed_by_user_id' => $user->id,
            'claimed_at' => now(),
        ]);

        app()->call([new CheckIncidentAcknowledgementJob($incident->id, 1, 1), 'handle']);

        $this->assertDatabaseMissing('incident_timelines', [
            'incident_id' => $incident->id,
            'entry_type' => 'sla_breached',
        ]);

        $this->assertSystemLogged('incidents.ack_check.skipped', fn (array $c) => $c['reason'] === 'human_control'
            && $c['input'] === ['incident_id' => $incident->id, 'level' => 1, 'attempt' => 1]);
        $this->assertSystemNotLogged('incidents.ack_check.breached');
        $this->assertNoSensitiveDataLogged();
    }

    public function test_suppressed_verification_is_terminal_and_does_not_block_future_attempts(): void
    {
        Queue::fake();

        $team = Team::factory()->create();
        $user = User::factory()->create(['current_team_id' => $team->id]);
        $incident = Incident::factory()->create([
            'team_id' => $team->id,
            'claimed_by_user_id' => $user->id,
            'claimed_at' => now(),
        ]);

        $verification = IncidentCallVerification::factory()->create([
            'team_id' => $team->id,
            'incident_id' => $incident->id,
            'attempt' => 1,
            'status' => CallVerificationStatus::Pending,
        ]);

        app()->call([new PlaceVerificationCallJob($verification->id), 'handle']);

        $verification->refresh();

        $this->assertSame(CallVerificationStatus::Failed, $verification->status);
        $this->assertSame(
            PlaceVerificationCallJob::SUPPRESSED_FAILURE_REASON,
            $verification->metadata_json['failure_reason'] ?? null,
        );

        // Release: the incident is no longer under human control.
        $incident->forceFill([
            'claimed_by_user_id' => null,
            'claimed_at' => null,
            'acknowledged_at' => null,
        ])->save();
        $incident->refresh();

        $restarted = app(StartIncidentCallVerification::class)->execute($incident);

        $this->assertNotNull($restarted);
        $this->assertNotSame($verification->id, $restarted->id);
        $this->assertSame(CallVerificationStatus::Pending, $restarted->status);

        Queue::assertPushed(
            PlaceVerificationCallJob::class,
            fn (PlaceVerificationCallJob $job) => $job->verificationId === $restarted->id,
        );
    }
}
