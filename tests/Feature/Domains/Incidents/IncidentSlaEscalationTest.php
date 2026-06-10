<?php

namespace Tests\Feature\Domains\Incidents;

use App\Domains\Incidents\Actions\AcknowledgeIncident;
use App\Domains\Incidents\Actions\AppendTimelineEntry;
use App\Domains\Incidents\Actions\CreateIncidentFromEvent;
use App\Domains\Incidents\Actions\EscalateIncident;
use App\Domains\Incidents\Enums\IncidentStatusCode;
use App\Domains\Incidents\Enums\TimelineEntryType;
use App\Domains\Incidents\Jobs\CheckIncidentAcknowledgementJob;
use App\Domains\Incidents\Models\Incident;
use App\Domains\Incidents\Models\IncidentPriority;
use App\Domains\Incidents\Models\IncidentStatus;
use App\Domains\Normalization\Models\NormalizedEvent;
use App\Domains\Notifications\Actions\SendNotification;
use App\Domains\Notifications\Models\Notification;
use App\Domains\TenantConfig\Models\TenantEscalationConfig;
use App\Enums\TeamRole;
use App\Models\Team;
use App\Models\User;
use Database\Seeders\AccessSeeder;
use Database\Seeders\IncidentsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

class IncidentSlaEscalationTest extends TestCase
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

    private function runWatchdog(Incident $incident, int $level = 0): void
    {
        (new CheckIncidentAcknowledgementJob($incident->id, $level))->handle(
            app(EscalateIncident::class),
            app(AppendTimelineEntry::class),
            app(SendNotification::class),
        );
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
    }

    public function test_early_delivery_never_escalates_before_the_sla(): void
    {
        Queue::fake();

        $incident = $this->makeOpenIncident(['sla_due_at' => now()->addHour()]);

        $this->runWatchdog($incident);

        $this->assertSame(IncidentStatusCode::Open->value, $incident->fresh()->status->code);
        Queue::assertNotPushed(CheckIncidentAcknowledgementJob::class);
    }

    public function test_unacknowledged_breach_escalates_notifies_and_rearms(): void
    {
        Queue::fake();

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
