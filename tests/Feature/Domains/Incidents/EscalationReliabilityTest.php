<?php

namespace Tests\Feature\Domains\Incidents;

use App\Domains\Incidents\Actions\ArmIncidentEscalation;
use App\Domains\Incidents\Actions\ClaimIncident;
use App\Domains\Incidents\Actions\CreateIncidentFromEvent;
use App\Domains\Incidents\Actions\CreateManualIncident;
use App\Domains\Incidents\Actions\ReclassifyIncident;
use App\Domains\Incidents\Actions\ReleaseIncident;
use App\Domains\Incidents\Enums\IncidentStatusCode;
use App\Domains\Incidents\Enums\TimelineEntryType;
use App\Domains\Incidents\Jobs\CheckIncidentAcknowledgementJob;
use App\Domains\Incidents\Jobs\SweepOverdueEscalationsJob;
use App\Domains\Incidents\Models\Incident;
use App\Domains\Incidents\Models\IncidentPriority;
use App\Domains\Incidents\Models\IncidentStatus;
use App\Domains\Incidents\Models\IncidentType;
use App\Domains\Incidents\Support\EscalationExhaustedNotification;
use App\Domains\Normalization\Models\NormalizedEvent;
use App\Domains\Notifications\Models\Notification;
use App\Domains\TenantConfig\Models\TenantEscalationConfig;
use App\Models\Team;
use App\Models\User;
use Database\Seeders\AccessSeeder;
use Database\Seeders\IncidentsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Notification as LaravelNotification;
use Illuminate\Support\Facades\Queue;
use Tests\Concerns\AssertsSystemLog;
use Tests\Concerns\AssertsTenantIsolation;
use Tests\TestCase;

/**
 * La escalera de SLA con estado persistido: no se pierde (barrido), no se
 * duplica (generación + paso bajo bloqueo), se re-arma al soltar o al subir
 * la prioridad, y al agotarse deja constancia y avisa a SAM.
 */
class EscalationReliabilityTest extends TestCase
{
    use AssertsSystemLog, AssertsTenantIsolation, RefreshDatabase;

    private User $user;

    private Team $team;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(AccessSeeder::class);
        $this->seed(IncidentsSeeder::class);

        $this->user = User::factory()->create();
        $this->team = $this->user->currentTeam;

        Carbon::setTestNow(Carbon::parse('2026-10-01 12:00:00'));
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    private function makeOpenIncident(array $attributes = [], ?Team $team = null): Incident
    {
        $open = IncidentStatus::query()->where('code', IncidentStatusCode::Open->value)->firstOrFail();

        return Incident::factory()->create(array_merge([
            'team_id' => ($team ?? $this->team)->id,
            'incident_status_id' => $open->id,
            'sla_due_at' => now()->subMinute(),
        ], $attributes));
    }

    /**
     * @param  array<int, array<string, mixed>>  $steps
     */
    private function makeEscalationConfig(array $steps, ?Team $team = null): TenantEscalationConfig
    {
        return TenantEscalationConfig::factory()->create([
            'team_id' => ($team ?? $this->team)->id,
            'is_active' => true,
            'steps_json' => $steps,
        ]);
    }

    private function runJob(Incident $incident, int $level = 0, int $attempt = 1, int $epoch = 0): void
    {
        app()->call([new CheckIncidentAcknowledgementJob($incident->id, $level, $attempt, $epoch), 'handle']);
    }

    private function sweep(): void
    {
        app()->call([new SweepOverdueEscalationsJob, 'handle']);
    }

    private function slaNoticesFor(Incident $incident): int
    {
        return Notification::withoutGlobalScopes()
            ->where('team_id', $incident->team_id)
            ->where('notification_type', 'incident.sla_breached')
            ->where('source_reference_id', (string) $incident->id)
            ->count();
    }

    private function criticalPriority(): IncidentPriority
    {
        return IncidentPriority::query()->updateOrCreate(
            ['code' => 'critical'],
            ['name' => 'Critical', 'level' => 4, 'sla_seconds' => 300, 'color' => '#ef4444'],
        );
    }

    public function test_creating_an_incident_persists_the_escalation_state(): void
    {
        Queue::fake();
        $this->criticalPriority();

        $event = NormalizedEvent::factory()->create(['team_id' => $this->team->id, 'occurred_at' => now()]);

        $incident = app(CreateIncidentFromEvent::class)->execute($event, ['priority_code' => 'critical']);
        $fresh = $incident->fresh();

        $this->assertSame(1, $fresh->escalation_epoch);
        $this->assertSame(0, $fresh->escalation_level);
        $this->assertSame(1, $fresh->escalation_attempt);
        $this->assertTrue($fresh->next_escalation_at->equalTo($fresh->sla_due_at));

        Queue::assertPushed(CheckIncidentAcknowledgementJob::class, fn (CheckIncidentAcknowledgementJob $job) => $job->incidentId === $incident->id
            && $job->epoch === 1 && $job->level === 0 && $job->attempt === 1);

        $this->assertSystemLogged('incidents.escalation.armed', fn (array $c) => $c['input'] === ['incident_id' => $incident->id, 'arm_reason' => 'incident_created']
            && $c['result']['epoch'] === 1
            && $c['result']['next_escalation_at'] === $fresh->sla_due_at->toIso8601String());
        $this->assertNoSensitiveDataLogged();
    }

    public function test_a_duplicate_job_for_the_same_step_notifies_only_once(): void
    {
        Queue::fake();
        $this->makeEscalationConfig([
            ['delay_minutes' => 0, 'contacts' => ['oncall@example.com']],
            ['delay_minutes' => 15, 'contacts' => ['boss@example.com']],
        ]);
        $incident = $this->makeOpenIncident(['escalation_epoch' => 1, 'next_escalation_at' => now()->subMinute()]);

        // El job diferido y el re-despacho del barrido llegan ambos.
        $this->runJob($incident, epoch: 1);
        $this->runJob($incident, epoch: 1);

        $this->assertSame(1, $this->slaNoticesFor($incident));
        $this->assertSame(1, $incident->timeline()->where('entry_type', TimelineEntryType::SlaBreached->value)->count());

        $fresh = $incident->fresh();
        $this->assertSame(1, $fresh->escalation_level);
        $this->assertSame(1, $fresh->escalation_attempt);
        $this->assertTrue($fresh->next_escalation_at->equalTo(now()->addMinutes(15)));

        $this->assertSystemLogged('incidents.ack_check.skipped', fn (array $c) => $c['reason'] === 'stale_step'
            && $c['calc'] === ['current_level' => 1, 'current_attempt' => 1, 'exhausted' => false]);
    }

    public function test_a_job_from_an_older_generation_is_discarded(): void
    {
        Queue::fake();
        $incident = $this->makeOpenIncident(['escalation_epoch' => 2]);

        $this->runJob($incident, epoch: 1);

        $this->assertSame(0, $this->slaNoticesFor($incident));
        $this->assertSystemLogged('incidents.ack_check.skipped', fn (array $c) => $c['reason'] === 'stale_epoch'
            && $c['calc'] === ['current_epoch' => 2]);
    }

    public function test_the_sweep_rescues_a_lost_step_and_ignores_handled_ones(): void
    {
        Queue::fake();

        $lost = $this->makeOpenIncident(['escalation_epoch' => 3, 'escalation_level' => 1, 'escalation_attempt' => 2, 'next_escalation_at' => now()->subMinutes(5)]);
        $notYetGrace = $this->makeOpenIncident(['escalation_epoch' => 1, 'next_escalation_at' => now()->subSeconds(30)]);
        $acked = $this->makeOpenIncident(['escalation_epoch' => 1, 'next_escalation_at' => now()->subMinutes(5), 'acknowledged_at' => now()]);
        $claimed = $this->makeOpenIncident(['escalation_epoch' => 1, 'next_escalation_at' => now()->subMinutes(5), 'claimed_by_user_id' => $this->user->id, 'claimed_at' => now()]);
        $exhausted = $this->makeOpenIncident(['escalation_epoch' => 1, 'next_escalation_at' => now()->subMinutes(5), 'escalation_exhausted_at' => now()]);
        $closed = IncidentStatus::query()->where('is_terminal', true)->firstOrFail();
        $terminal = $this->makeOpenIncident(['escalation_epoch' => 1, 'next_escalation_at' => now()->subMinutes(5), 'incident_status_id' => $closed->id]);

        $this->sweep();

        Queue::assertPushed(CheckIncidentAcknowledgementJob::class, 1);
        Queue::assertPushed(CheckIncidentAcknowledgementJob::class, fn (CheckIncidentAcknowledgementJob $job) => $job->incidentId === $lost->id
            && $job->epoch === 3 && $job->level === 1 && $job->attempt === 2);

        $this->assertSystemLogged('incidents.escalation_sweep.completed', fn (array $c) => $c['result']['redispatched_count'] === 1
            && $c['result']['incident_ids'] === [$lost->id]
            && $c['calc']['grace_seconds'] === SweepOverdueEscalationsJob::GRACE_SECONDS
            && $c['calc']['cutoff_at'] === now()->subSeconds(SweepOverdueEscalationsJob::GRACE_SECONDS)->toIso8601String());
        $this->assertNoSensitiveDataLogged();

        unset($notYetGrace, $acked, $claimed, $exhausted, $terminal);
    }

    public function test_the_rescued_step_runs_inside_its_own_tenant(): void
    {
        $otherTeam = User::factory()->create()->currentTeam;
        $this->makeEscalationConfig([['delay_minutes' => 0, 'contacts' => ['oncall@example.com']]]);
        $this->makeEscalationConfig([['delay_minutes' => 0, 'contacts' => ['foreign@example.com']]], $otherTeam);

        $incident = $this->makeOpenIncident(['escalation_epoch' => 1, 'next_escalation_at' => now()->subMinutes(5)]);

        Queue::fake();
        $this->assertNoTenantLeak($this->team, fn () => $this->runJob($incident, epoch: 1));

        $notice = Notification::withoutGlobalScopes()->where('source_reference_id', (string) $incident->id)->sole();
        $this->assertSame($this->team->id, $notice->team_id);
        $this->assertSame('oncall@example.com', $notice->payload_json['recipients'][0]['address']);
    }

    public function test_the_sweep_redispatches_each_tenant_step_inside_its_own_tenant(): void
    {
        $otherTeam = User::factory()->create()->currentTeam;
        $this->makeEscalationConfig([['delay_minutes' => 0, 'contacts' => ['ours@example.com']]]);
        $this->makeEscalationConfig([['delay_minutes' => 0, 'contacts' => ['theirs@example.com']]], $otherTeam);

        $ours = $this->makeOpenIncident(['escalation_epoch' => 1, 'next_escalation_at' => now()->subMinutes(5)]);
        $theirs = $this->makeOpenIncident(['escalation_epoch' => 1, 'next_escalation_at' => now()->subMinutes(5)], $otherTeam);

        Queue::fake();
        $this->sweep();

        Queue::assertPushed(CheckIncidentAcknowledgementJob::class, 2);

        // Cada paso rescatado corre en su tenant y avisa sólo a sus contactos.
        foreach (Queue::pushed(CheckIncidentAcknowledgementJob::class) as $job) {
            app()->call([$job, 'handle']);
        }

        $ourNotice = Notification::withoutGlobalScopes()->where('source_reference_id', (string) $ours->id)->sole();
        $theirNotice = Notification::withoutGlobalScopes()->where('source_reference_id', (string) $theirs->id)->sole();

        $this->assertSame($this->team->id, $ourNotice->team_id);
        $this->assertSame(['ours@example.com'], array_column($ourNotice->payload_json['recipients'], 'address'));
        $this->assertSame($otherTeam->id, $theirNotice->team_id);
        $this->assertSame(['theirs@example.com'], array_column($theirNotice->payload_json['recipients'], 'address'));
    }

    public function test_stopping_reasons_clear_the_pending_step(): void
    {
        Queue::fake();
        $incident = $this->makeOpenIncident(['escalation_epoch' => 1, 'next_escalation_at' => now()->subMinute(), 'acknowledged_at' => now()]);

        $this->runJob($incident, epoch: 1);

        $this->assertNull($incident->fresh()->next_escalation_at);
    }

    public function test_release_after_the_chain_fired_notifies_again_with_a_new_generation(): void
    {
        Queue::fake();
        $this->makeEscalationConfig([['delay_minutes' => 0, 'contacts' => ['oncall@example.com']]]);
        $incident = $this->makeOpenIncident(['escalation_epoch' => 1, 'next_escalation_at' => now()->subMinute()]);

        $this->runJob($incident, epoch: 1);
        $this->assertSame(1, $this->slaNoticesFor($incident));

        app(ClaimIncident::class)->execute($incident->fresh(), $this->user);
        app(ReleaseIncident::class)->execute($incident->fresh(), $this->user);

        $fresh = $incident->fresh();
        $this->assertSame(2, $fresh->escalation_epoch);
        $this->assertNull($fresh->escalation_exhausted_at);
        Queue::assertPushed(CheckIncidentAcknowledgementJob::class, fn (CheckIncidentAcknowledgementJob $job) => $job->epoch === 2 && $job->level === 0);

        $this->runJob($incident, epoch: 2);

        $this->assertSame(2, $this->slaNoticesFor($incident), 'el segundo SLA vencido vuelve a avisar');
        $this->assertNotNull(Notification::withoutGlobalScopes()->where('event_key', "incident_sla_breached:{$incident->id}:0:e2")->first());
    }

    public function test_raising_priority_arms_an_incident_that_had_no_sla(): void
    {
        Queue::fake();
        $critical = $this->criticalPriority();
        $low = IncidentPriority::query()->updateOrCreate(['code' => 'low'], ['name' => 'Low', 'level' => 1, 'sla_seconds' => null, 'color' => '#999999']);
        $incident = $this->makeOpenIncident(['incident_priority_id' => $low->id, 'sla_due_at' => null]);
        $type = IncidentType::query()->findOrFail($incident->incident_type_id);

        app(ReclassifyIncident::class)->execute($incident, $type, $critical);

        $fresh = $incident->fresh();
        $this->assertTrue($fresh->sla_due_at->equalTo(now()->addSeconds(300)));
        $this->assertTrue($fresh->next_escalation_at->equalTo($fresh->sla_due_at));
        $this->assertSame(1, $fresh->escalation_epoch);
        Queue::assertPushed(CheckIncidentAcknowledgementJob::class, fn (CheckIncidentAcknowledgementJob $job) => $job->incidentId === $incident->id && $job->epoch === 1);

        $this->assertSystemLogged('incidents.escalation.tightened', fn (array $c) => $c['outcome'] === 'ok'
            && $c['calc']['sla_seconds'] === 300
            && $c['calc']['pending_at'] === null
            && $c['calc']['candidate_due_at'] === now()->addSeconds(300)->toIso8601String());
        $this->assertNoSensitiveDataLogged();
    }

    public function test_raising_priority_never_restarts_a_running_chain(): void
    {
        Queue::fake();
        $critical = $this->criticalPriority();
        $incident = $this->makeOpenIncident(['escalation_epoch' => 1, 'escalation_level' => 1, 'next_escalation_at' => now()->addMinutes(10)]);
        $type = IncidentType::query()->findOrFail($incident->incident_type_id);

        app(ReclassifyIncident::class)->execute($incident, $type, $critical);

        $this->assertSame(1, $incident->fresh()->escalation_epoch);
        Queue::assertNotPushed(CheckIncidentAcknowledgementJob::class);
        $this->assertSystemLogged('incidents.escalation.tightened', fn (array $c) => $c['reason'] === 'escalation_in_progress');
    }

    public function test_raising_priority_keeps_an_earlier_pending_step(): void
    {
        Queue::fake();
        $critical = $this->criticalPriority();
        $incident = $this->makeOpenIncident(['escalation_epoch' => 1, 'sla_due_at' => now()->addMinute(), 'next_escalation_at' => now()->addMinute()]);
        $type = IncidentType::query()->findOrFail($incident->incident_type_id);

        app(ReclassifyIncident::class)->execute($incident, $type, $critical);

        $this->assertSame(1, $incident->fresh()->escalation_epoch);
        $this->assertSystemLogged('incidents.escalation.tightened', fn (array $c) => $c['reason'] === 'not_earlier');
    }

    public function test_manual_incidents_get_an_sla_and_an_escalation(): void
    {
        Queue::fake();
        $critical = $this->criticalPriority();
        $type = IncidentType::query()->firstOrFail();

        $incident = app(CreateManualIncident::class)->execute($this->team->id, $this->user, [
            'incident_type_id' => $type->id,
            'incident_priority_id' => $critical->id,
            'title' => 'Robo reportado por teléfono',
            'summary' => 'El cliente llamó',
        ]);

        $fresh = $incident->fresh();
        $this->assertTrue($fresh->sla_due_at->equalTo(now()->addSeconds(300)));
        $this->assertSame(1, $fresh->escalation_epoch);
        Queue::assertPushed(CheckIncidentAcknowledgementJob::class, fn (CheckIncidentAcknowledgementJob $job) => $job->incidentId === $incident->id);
        $this->assertSystemLogged('incidents.escalation.armed', fn (array $c) => $c['input']['arm_reason'] === 'manual_incident_created');
    }

    public function test_accelerate_counts_level_zero_as_fired_and_schedules_the_next(): void
    {
        Queue::fake();
        $this->makeEscalationConfig([
            ['delay_minutes' => 0, 'contacts' => ['oncall@example.com']],
            ['delay_minutes' => 5, 'contacts' => ['boss@example.com']],
        ]);
        $incident = $this->makeOpenIncident(['escalation_epoch' => 1, 'sla_due_at' => now()->addMinutes(5), 'next_escalation_at' => now()->addMinutes(5)]);

        app(ArmIncidentEscalation::class)->accelerate($incident, 'emergency_confirmed');

        $fresh = $incident->fresh();
        $this->assertSame(1, $fresh->escalation_level);
        $this->assertTrue($fresh->next_escalation_at->equalTo(now()->addMinutes(5)));
        Queue::assertPushed(CheckIncidentAcknowledgementJob::class, fn (CheckIncidentAcknowledgementJob $job) => $job->level === 1 && $job->epoch === 1);

        // El job original del nivel 0 llega al vencer el SLA: ya no avisa.
        $this->travel(5)->minutes();
        $this->runJob($incident, epoch: 1);
        $this->assertSame(0, $this->slaNoticesFor($incident));

        $this->assertSystemLogged('incidents.escalation.accelerated', fn (array $c) => $c['outcome'] === 'ok'
            && $c['input'] === ['incident_id' => $incident->id, 'accelerate_reason' => 'emergency_confirmed']
            && $c['result'] === ['next_level' => 1, 'next_attempt' => 1, 'delay_minutes' => 5]);

        // Una segunda aceleración no toca una escalera que ya avanzó.
        app(ArmIncidentEscalation::class)->accelerate($incident->fresh(), 'verification_no_answer');
        $this->assertSystemLogged('incidents.escalation.accelerated', fn (array $c) => ($c['reason'] ?? null) === 'already_running');
    }

    public function test_exhausting_the_chain_records_it_and_alerts_super_admins_once(): void
    {
        Queue::fake();
        LaravelNotification::fake();
        $superAdmin = User::factory()->create(['global_role' => 'super_admin']);
        $this->makeEscalationConfig([['delay_minutes' => 0, 'contacts' => ['oncall@example.com']]]);
        $incident = $this->makeOpenIncident(['escalation_epoch' => 1, 'next_escalation_at' => now()->subMinute()]);

        $this->runJob($incident, epoch: 1);

        $fresh = $incident->fresh();
        $this->assertNotNull($fresh->escalation_exhausted_at);
        $this->assertNull($fresh->next_escalation_at);
        $this->assertSame(1, $incident->timeline()->where('entry_type', TimelineEntryType::EscalationExhausted->value)->count());

        LaravelNotification::assertSentTo($superAdmin, EscalationExhaustedNotification::class, fn (EscalationExhaustedNotification $n) => $n->details['incident_id'] === $incident->id
            && $n->details['team_id'] === $this->team->id
            && $n->details['levels_count'] === 1);
        LaravelNotification::assertNotSentTo($this->user, EscalationExhaustedNotification::class);

        // Idempotente: otra pasada no duplica ni la línea ni el aviso.
        app(ArmIncidentEscalation::class)->exhaust($fresh, 1);
        $this->assertSame(1, $incident->timeline()->where('entry_type', TimelineEntryType::EscalationExhausted->value)->count());
        LaravelNotification::assertSentToTimes($superAdmin, EscalationExhaustedNotification::class, 1);

        $this->assertSystemLogged('incidents.escalation.exhausted', fn (array $c) => $c['outcome'] === 'ok'
            && $c['result'] === ['super_admins_notified' => 1]);
        $this->assertSystemLogged('incidents.escalation.exhausted', fn (array $c) => ($c['reason'] ?? null) === 'already_exhausted');
        $this->assertNoSensitiveDataLogged();
    }

    public function test_exhaustion_without_super_admins_is_logged_as_degraded(): void
    {
        Queue::fake();
        LaravelNotification::fake();
        $incident = $this->makeOpenIncident(['escalation_epoch' => 1, 'next_escalation_at' => now()->subMinute()]);

        app(ArmIncidentEscalation::class)->exhaust($incident, 0);

        $this->assertSystemLogged('incidents.escalation.exhausted', fn (array $c) => ($c['reason'] ?? null) === 'no_super_admins');
    }

    public function test_a_failed_job_is_logged_and_left_for_the_sweep(): void
    {
        $incident = $this->makeOpenIncident(['escalation_epoch' => 1, 'next_escalation_at' => now()->subMinute()]);

        (new CheckIncidentAcknowledgementJob($incident->id, 0, 1, 1))->failed(new \RuntimeException('boom'));

        $this->assertNotNull($incident->fresh()->next_escalation_at);
        $this->assertSystemLogged('incidents.ack_check.failed', fn (array $c) => $c['reason'] === 'job_failed'
            && $c['input'] === ['incident_id' => $incident->id, 'level' => 0, 'attempt' => 1]);
    }

    public function test_the_most_recent_active_config_wins(): void
    {
        Queue::fake();
        $this->makeEscalationConfig([['delay_minutes' => 0, 'contacts' => ['old@example.com']]]);
        $this->makeEscalationConfig([['delay_minutes' => 0, 'contacts' => ['new@example.com']]]);
        $incident = $this->makeOpenIncident(['escalation_epoch' => 1, 'next_escalation_at' => now()->subMinute()]);

        $this->runJob($incident, epoch: 1);

        $notice = Notification::withoutGlobalScopes()->where('source_reference_id', (string) $incident->id)->sole();
        $this->assertSame('new@example.com', $notice->payload_json['recipients'][0]['address']);
    }
}
