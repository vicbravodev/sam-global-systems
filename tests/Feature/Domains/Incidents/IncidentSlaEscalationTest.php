<?php

namespace Tests\Feature\Domains\Incidents;

use App\Domains\Incidents\Actions\AcknowledgeIncident;
use App\Domains\Incidents\Actions\CreateIncidentFromEvent;
use App\Domains\Incidents\Enums\IncidentStatusCode;
use App\Domains\Incidents\Enums\TimelineEntryType;
use App\Domains\Incidents\Jobs\CheckIncidentAcknowledgementJob;
use App\Domains\Incidents\Models\Incident;
use App\Domains\Incidents\Models\IncidentPriority;
use App\Domains\Incidents\Models\IncidentStatus;
use App\Domains\Normalization\Models\NormalizedEvent;
use App\Domains\Notifications\Models\Notification;
use App\Domains\TenantConfig\Models\TenantEscalationConfig;
use App\Enums\TeamRole;
use App\Models\Team;
use App\Models\User;
use Database\Seeders\AccessSeeder;
use Database\Seeders\IncidentsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Queue;
use Tests\Concerns\AssertsSystemLog;
use Tests\TestCase;

class IncidentSlaEscalationTest extends TestCase
{
    use AssertsSystemLog, RefreshDatabase;

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

    /**
     * @param  array<string, mixed>  $attributes
     */
    private function makeOpenIncident(array $attributes = []): Incident
    {
        $open = IncidentStatus::query()->where('code', IncidentStatusCode::Open->value)->firstOrFail();

        return Incident::factory()->create(array_merge([
            'team_id' => $this->team->id,
            'incident_status_id' => $open->id,
            'sla_due_at' => now()->subMinute(),
        ], $attributes));
    }

    /**
     * @param  array<int, array<string, mixed>>  $steps
     */
    private function makeEscalationConfig(array $steps): TenantEscalationConfig
    {
        return TenantEscalationConfig::factory()->create([
            'team_id' => $this->team->id,
            'is_active' => true,
            'steps_json' => $steps,
        ]);
    }

    private function runWatchdog(Incident $incident, int $level = 0, int $attempt = 1): void
    {
        app()->call([new CheckIncidentAcknowledgementJob($incident->id, $level, $attempt), 'handle']);
    }

    public function test_acknowledged_incident_never_escalates(): void
    {
        Queue::fake();

        $incident = $this->makeOpenIncident(['acknowledged_at' => now(), 'acknowledged_by' => $this->user->id]);

        $this->runWatchdog($incident);

        $this->assertSame(IncidentStatusCode::Open->value, $incident->fresh()->status->code);
        $this->assertDatabaseMissing('incident_timelines', [
            'incident_id' => $incident->id,
            'entry_type' => TimelineEntryType::SlaBreached->value,
        ]);
        Queue::assertNotPushed(CheckIncidentAcknowledgementJob::class);

        $this->assertSystemLogged('incidents.ack_check.skipped', fn (array $c) => $c['reason'] === 'acknowledged'
            && $c['input'] === ['incident_id' => $incident->id, 'level' => 0, 'attempt' => 1]);
        $this->assertSystemNotLogged('incidents.ack_check.breached');
        $this->assertNoSensitiveDataLogged();
    }

    public function test_terminal_incident_stops_the_chain(): void
    {
        Queue::fake();

        $resolved = IncidentStatus::query()->where('is_terminal', true)->firstOrFail();
        $incident = $this->makeOpenIncident(['incident_status_id' => $resolved->id]);

        $this->runWatchdog($incident);

        Queue::assertNotPushed(CheckIncidentAcknowledgementJob::class);
        $this->assertDatabaseMissing('incident_timelines', [
            'incident_id' => $incident->id,
            'entry_type' => TimelineEntryType::SlaBreached->value,
        ]);

        $this->assertSystemLogged('incidents.ack_check.skipped', fn (array $c) => $c['reason'] === 'terminal'
            && $c['input']['incident_id'] === $incident->id);
    }

    public function test_early_delivery_never_escalates_before_the_sla(): void
    {
        Queue::fake();

        // Reloj con fracción de segundo: los términos registrados (ISO, al
        // segundo) deben rehacer exactamente seconds_until_due.
        Carbon::setTestNow(Carbon::parse('2026-09-29 10:00:00.700000'));
        $incident = $this->makeOpenIncident(['sla_due_at' => now()->addHour()]);

        $this->runWatchdog($incident);

        $this->assertSame(IncidentStatusCode::Open->value, $incident->fresh()->status->code);
        $this->assertDatabaseMissing('incident_timelines', [
            'incident_id' => $incident->id,
            'entry_type' => TimelineEntryType::SlaBreached->value,
        ]);

        // Un aviso temprano nunca escala ni se re-encola (en una cola síncrona
        // sería un bucle); el paso sigue persistido y el barrido lo rescata.
        Queue::assertNotPushed(CheckIncidentAcknowledgementJob::class);

        $c = $this->assertSystemLogged('incidents.ack_check.skipped', fn (array $c) => $c['reason'] === 'not_due_yet');
        $this->assertSame($c['calc']['sla_due_at'], $c['calc']['due_at']);
        $this->assertGreaterThan(0, $c['calc']['seconds_until_due']);
        $this->assertSame(3600, $c['calc']['seconds_until_due']);
        $this->assertSame($incident->fresh()->sla_due_at->toIso8601String(), $c['calc']['sla_due_at']);
        $this->assertSame(now()->toIso8601String(), $c['calc']['now_at']);
        $this->assertSame(
            Carbon::parse($c['calc']['now_at'])->diffInSeconds(Carbon::parse($c['calc']['sla_due_at']), false),
            (float) $c['calc']['seconds_until_due'],
        );
    }

    public function test_unacknowledged_breach_escalates_notifies_and_rearms(): void
    {
        Queue::fake();
        $this->freezeSecond();

        $this->makeEscalationConfig([
            ['delay_minutes' => 0, 'contacts' => ['oncall@example.com']],
            ['delay_minutes' => 15, 'contacts' => ['boss@example.com']],
        ]);

        $incident = $this->makeOpenIncident();

        $this->runWatchdog($incident);

        $fresh = $incident->fresh();
        $this->assertSame(IncidentStatusCode::Escalated->value, $fresh->status->code);

        $this->assertDatabaseHas('incident_timelines', [
            'incident_id' => $incident->id,
            'entry_type' => TimelineEntryType::SlaBreached->value,
        ]);

        $notification = Notification::withoutGlobalScopes()
            ->where('event_key', "incident_sla_breached:{$incident->id}:0")
            ->sole();
        $this->assertSame(
            'oncall@example.com',
            $notification->payload_json['recipients'][0]['address'],
        );

        Queue::assertPushed(
            CheckIncidentAcknowledgementJob::class,
            fn (CheckIncidentAcknowledgementJob $job) => $job->incidentId === $incident->id && $job->level === 1,
        );

        $input = ['incident_id' => $incident->id, 'level' => 0, 'attempt' => 1];

        $this->assertSystemLogged('incidents.ack_check.breached', fn (array $c) => $c['input'] === $input
            && $c['calc']['first_attempt_at_level'] === true
            && $c['calc']['status_before'] === 'open'
            && $c['calc']['steps_count'] === 2
            && $c['result']['escalated_now'] === true
            && $c['result']['status_after'] === 'escalated');

        $c = $this->assertSystemLogged('incidents.ack_check.rearmed', fn (array $c) => $c['input'] === $input
            && $c['calc']['mode'] === 'next_level');
        $this->assertSame(0, $c['calc']['current_offset_minutes']);
        $this->assertSame(15, $c['calc']['next_offset_minutes']);
        $this->assertSame(1, $c['calc']['step_attempts']);
        $this->assertSame(1, $c['result']['next_level']);
        $this->assertSame(1, $c['result']['next_attempt']);
        $this->assertSame(
            max(1, $c['calc']['next_offset_minutes'] - $c['calc']['current_offset_minutes']),
            $c['result']['delay_minutes'],
        );
        Queue::assertPushed(
            CheckIncidentAcknowledgementJob::class,
            fn (CheckIncidentAcknowledgementJob $job) => $job->level === $c['result']['next_level']
                && $job->attempt === $c['result']['next_attempt']
                && Carbon::instance($job->delay)->equalTo(now()->addMinutes($c['result']['delay_minutes'])),
        );

        $json = json_encode($this->systemLogEntries());
        $this->assertStringNotContainsString('oncall@example.com', $json);
        $this->assertStringNotContainsString('boss@example.com', $json);
        $this->assertStringNotContainsString((string) json_encode($incident->title), $json);
        $this->assertNoSensitiveDataLogged();
    }

    public function test_step_channels_are_forced_on_the_notification(): void
    {
        Queue::fake();

        $this->makeEscalationConfig([
            ['delay_minutes' => 0, 'contacts' => ['+5215512345678'], 'channels' => ['voice', 'sms', 'not-a-channel']],
        ]);

        $incident = $this->makeOpenIncident();

        $this->runWatchdog($incident);

        $notification = Notification::withoutGlobalScopes()
            ->where('event_key', "incident_sla_breached:{$incident->id}:0")
            ->sole();

        // Invalid channel names are dropped; valid ones pin the delivery.
        $this->assertSame(['voice', 'sms'], $notification->payload_json['force_channels']);
    }

    public function test_step_attempts_retry_the_same_level_before_advancing(): void
    {
        Queue::fake();
        $this->freezeSecond();

        $this->makeEscalationConfig([
            ['delay_minutes' => 0, 'contacts' => ['oncall@example.com'], 'attempts' => 2, 'retry_minutes' => 3],
            ['delay_minutes' => 15, 'contacts' => ['boss@example.com']],
        ]);

        $incident = $this->makeOpenIncident();

        $this->runWatchdog($incident);

        // First attempt re-arms the SAME level instead of moving on.
        Queue::assertPushed(
            CheckIncidentAcknowledgementJob::class,
            fn (CheckIncidentAcknowledgementJob $job) => $job->level === 0 && $job->attempt === 2,
        );
        Queue::assertNotPushed(
            CheckIncidentAcknowledgementJob::class,
            fn (CheckIncidentAcknowledgementJob $job) => $job->level === 1,
        );

        $c = $this->assertSystemLogged('incidents.ack_check.rearmed', fn (array $c) => $c['calc']['mode'] === 'retry_same_level');
        $this->assertSame(['incident_id' => $incident->id, 'level' => 0, 'attempt' => 1], $c['input']);
        $this->assertSame(2, $c['calc']['step_attempts']);
        $this->assertSame(3, $c['calc']['retry_minutes']);
        $this->assertSame(CheckIncidentAcknowledgementJob::DEFAULT_RETRY_MINUTES, $c['calc']['default_retry_minutes']);
        $this->assertSame(0, $c['result']['next_level']);
        $this->assertSame(2, $c['result']['next_attempt']);
        $this->assertSame($c['calc']['retry_minutes'], $c['result']['delay_minutes']);
        Queue::assertPushed(
            CheckIncidentAcknowledgementJob::class,
            fn (CheckIncidentAcknowledgementJob $job) => $job->level === 0
                && $job->attempt === 2
                && Carbon::instance($job->delay)->equalTo(now()->addMinutes($c['result']['delay_minutes'])),
        );

        $this->travel($c['result']['delay_minutes'])->minutes();
        $this->runWatchdog($incident, level: 0, attempt: 2);

        $this->assertSystemLogged('incidents.ack_check.breached', fn (array $c) => $c['input']['attempt'] === 2
            && $c['calc']['first_attempt_at_level'] === false
            && $c['result']['escalated_now'] === false);
        $this->assertSystemLogged('incidents.ack_check.rearmed', fn (array $c) => $c['input']['attempt'] === 2
            && $c['calc']['mode'] === 'next_level'
            && $c['result']['next_level'] === 1
            && $c['result']['delay_minutes'] === 15);

        // The retry notified again with its own idempotency key…
        $this->assertNotNull(Notification::withoutGlobalScopes()
            ->where('event_key', "incident_sla_breached:{$incident->id}:0:a2")
            ->first());

        // …did not duplicate the breach timeline…
        $this->assertSame(1, $incident->timeline()
            ->where('entry_type', TimelineEntryType::SlaBreached->value)
            ->count());

        // …and only then advanced to the next level.
        Queue::assertPushed(
            CheckIncidentAcknowledgementJob::class,
            fn (CheckIncidentAcknowledgementJob $job) => $job->level === 1 && $job->attempt === 1,
        );
    }

    public function test_chain_exhausts_after_the_last_level(): void
    {
        Queue::fake();

        $this->makeEscalationConfig([
            ['delay_minutes' => 0, 'contacts' => ['oncall@example.com']],
            ['delay_minutes' => 15, 'contacts' => ['boss@example.com']],
        ]);

        $incident = $this->makeOpenIncident();

        $this->runWatchdog($incident, level: 1);

        $notification = Notification::withoutGlobalScopes()
            ->where('event_key', "incident_sla_breached:{$incident->id}:1")
            ->sole();
        $this->assertSame('boss@example.com', $notification->payload_json['recipients'][0]['address']);

        Queue::assertNotPushed(CheckIncidentAcknowledgementJob::class);

        $this->assertSystemLogged('incidents.ack_check.chain_exhausted', fn (array $c) => $c['reason'] === 'no_next_level'
            && $c['input'] === ['incident_id' => $incident->id, 'level' => 1, 'attempt' => 1]
            && $c['calc']['steps_count'] === 2
            && $c['calc']['step_attempts'] === 1);
        $this->assertSystemNotLogged('incidents.ack_check.rearmed');
    }

    public function test_acknowledging_after_first_breach_cancels_the_next_level(): void
    {
        Queue::fake();

        $this->makeEscalationConfig([
            ['delay_minutes' => 0],
            ['delay_minutes' => 15],
        ]);

        $incident = $this->makeOpenIncident();

        $this->runWatchdog($incident);
        $this->assertSame(IncidentStatusCode::Escalated->value, $incident->fresh()->status->code);

        app(AcknowledgeIncident::class)->execute($incident->fresh(), $this->user->id);

        $this->runWatchdog($incident, level: 1);

        $this->assertSame(
            0,
            Notification::withoutGlobalScopes()
                ->where('event_key', "incident_sla_breached:{$incident->id}:1")
                ->count(),
            'a level-1 check after acknowledgement must be a no-op',
        );
    }

    public function test_create_incident_from_event_sets_sla_and_arms_the_watchdog(): void
    {
        Queue::fake();
        // El SLA corre desde max(occurred_at, now()): se congela el reloj para
        // que ambos coincidan en un evento en vivo.
        $this->freezeSecond();

        $critical = IncidentPriority::query()->updateOrCreate(
            ['code' => 'critical'],
            ['name' => 'Critical', 'level' => 4, 'sla_seconds' => 300, 'color' => '#ef4444'],
        );

        $event = NormalizedEvent::factory()->create([
            'team_id' => $this->team->id,
            'occurred_at' => now(),
        ]);

        $incident = app(CreateIncidentFromEvent::class)->execute($event, [
            'priority_code' => 'critical',
        ]);

        $this->assertNotNull($incident->sla_due_at);
        $this->assertSame(
            $incident->opened_at->addSeconds(300)->toIso8601String(),
            $incident->sla_due_at->toIso8601String(),
        );

        Queue::assertPushed(
            CheckIncidentAcknowledgementJob::class,
            fn (CheckIncidentAcknowledgementJob $job) => $job->incidentId === $incident->id && $job->level === 0,
        );

        $c = $this->assertSystemLogged('incidents.sla.calculated', fn (array $c) => $c['input']['incident_id'] === $incident->id
            && $c['calc']['sla_source'] === 'priority_catalog'
            && $c['calc']['sla_seconds'] === 300
            && $c['result']['watchdog_requested'] === true);
        $this->assertSame($incident->sla_due_at->toIso8601String(), $c['result']['sla_due_at']);
        Queue::assertPushed(
            CheckIncidentAcknowledgementJob::class,
            fn (CheckIncidentAcknowledgementJob $job) => $job->incidentId === $incident->id
                && $job->delay instanceof \DateTimeInterface
                && Carbon::instance($job->delay)->toIso8601String() === $c['result']['sla_due_at'],
        );
        $this->assertNoSensitiveDataLogged();
    }

    public function test_acknowledge_endpoint_marks_incident_and_is_idempotent(): void
    {
        Queue::fake();

        $incident = $this->makeOpenIncident(['sla_due_at' => now()->addHour()]);

        $response = $this->actingAs($this->user)->postJson(
            route('incidents.acknowledge', [
                'current_team' => $this->team->slug,
                'incident' => $incident->id,
            ]),
        );

        $response->assertOk();

        $fresh = $incident->fresh();
        $this->assertNotNull($fresh->acknowledged_at);
        $this->assertSame($this->user->id, (int) $fresh->acknowledged_by);
        $this->assertDatabaseHas('incident_timelines', [
            'incident_id' => $incident->id,
            'entry_type' => TimelineEntryType::Acknowledged->value,
        ]);

        $firstAckAt = $fresh->acknowledged_at;

        $other = User::factory()->create();
        $this->team->members()->attach($other, ['role' => TeamRole::Admin->value]);
        // Mirror a member that already navigated into this team: the tenant
        // scope on the route binding reads the persisted current team.
        $other->forceFill(['current_team_id' => $this->team->id])->save();

        $this->actingAs($other->fresh())->postJson(
            route('incidents.acknowledge', [
                'current_team' => $this->team->slug,
                'incident' => $incident->id,
            ]),
        )->assertOk();

        $this->assertSame($this->user->id, (int) $incident->fresh()->acknowledged_by, 'the first acknowledgement wins');
        $this->assertTrue($firstAckAt->equalTo($incident->fresh()->acknowledged_at));
    }

    public function test_acknowledge_endpoint_is_team_scoped(): void
    {
        $foreign = User::factory()->create();
        $foreignIncident = Incident::factory()->create(['team_id' => $foreign->currentTeam->id]);

        $this->actingAs($this->user)->postJson(
            route('incidents.acknowledge', [
                'current_team' => $this->team->slug,
                'incident' => $foreignIncident->id,
            ]),
        )->assertNotFound();
    }
}
