<?php

namespace Tests\Feature\Domains\Incidents;

use App\Domains\Incidents\Actions\CreateIncidentFromEvent;
use App\Domains\Incidents\Events\IncidentCreated;
use App\Domains\Incidents\Models\Incident;
use App\Domains\Incidents\Models\IncidentAssignment;
use App\Domains\Incidents\Models\IncidentEventLink;
use App\Domains\Incidents\Models\IncidentPriority;
use App\Domains\Normalization\Models\EventCategory;
use App\Domains\Normalization\Models\EventType;
use App\Domains\Normalization\Models\NormalizedEvent;
use App\Domains\Notifications\Actions\SendNotification;
use App\Domains\Notifications\Enums\NotificationPriority;
use App\Domains\Notifications\Enums\NotificationSourceType;
use App\Domains\Notifications\Enums\NotificationTriggeredByType;
use App\Domains\Notifications\Models\Notification;
use App\Domains\TenantConfig\Models\TenantScheduleProfile;
use App\Enums\TeamRole;
use App\Models\Team;
use App\Models\User;
use Database\Seeders\IncidentsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Event;
use RuntimeException;
use Tests\Concerns\AssertsSystemLog;
use Tests\TestCase;

class AssignOnCallOnIncidentCreatedTest extends TestCase
{
    use AssertsSystemLog, RefreshDatabase;

    private Team $team;

    private User $operator;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(IncidentsSeeder::class);
        Bus::fake();

        $owner = User::factory()->create();
        $this->team = $owner->currentTeam;

        $this->operator = User::factory()->create();
        $this->team->members()->attach($this->operator, ['role' => TeamRole::Member->value]);
    }

    /**
     * @param  array<string, mixed>|null  $shiftRules
     */
    private function makeScheduleProfile(?array $shiftRules): TenantScheduleProfile
    {
        return TenantScheduleProfile::factory()->create([
            'team_id' => $this->team->id,
            'is_active' => true,
            'timezone' => 'UTC',
            'shift_rules_json' => $shiftRules,
        ]);
    }

    private function makeIncident(string $priorityCode = 'critical'): Incident
    {
        $priority = IncidentPriority::query()->firstOrCreate(
            ['code' => $priorityCode],
            ['name' => ucfirst($priorityCode), 'level' => 4, 'sla_seconds' => 300, 'color' => '#ef4444'],
        );

        return Incident::factory()->create([
            'team_id' => $this->team->id,
            'incident_priority_id' => $priority->id,
        ]);
    }

    public function test_assigns_on_call_operator_and_notifies_for_critical_incident(): void
    {
        $this->makeScheduleProfile([
            'on_call' => [['user_id' => $this->operator->id]],
        ]);

        $incident = $this->makeIncident('critical');

        IncidentCreated::dispatch($incident);

        $assignment = IncidentAssignment::query()
            ->where('incident_id', $incident->id)
            ->whereNull('unassigned_at')
            ->sole();

        $this->assertSame($this->operator->id, (int) $assignment->assigned_to_id);
        $this->assertSame('on_call', $assignment->role);

        $notification = Notification::withoutGlobalScopes()
            ->where('event_key', "incident_oncall_assigned:{$incident->id}")
            ->sole();

        $this->assertSame('critical', $notification->priority->value);
        $this->assertSame(
            $this->operator->email,
            $notification->payload_json['recipients'][0]['address'],
            'the directed notification must target only the on-call operator',
        );

        // The team-wide critical alert already reaches the operator out of
        // band: the directed "it's yours" notice is in-app only (no 2nd SMS).
        $this->assertSame(['web', 'push'], $notification->payload_json['force_channels']);
        $this->assertTrue(Notification::withoutGlobalScopes()
            ->where('event_key', "incident_created:{$incident->id}")
            ->exists());

        $c = $this->assertSystemLogged('incidents.assignment.resolved', fn (array $c) => $c['outcome'] === 'ok'
            && $c['input']['incident_id'] === $incident->id
            && $c['input']['stage'] === 'on_call_listener');
        $this->assertSame('shift', $c['calc']['source']);
        $this->assertSame(0, $c['calc']['matched_shift_index']);
        $this->assertSame(1, $c['calc']['shifts_count']);
        $this->assertSame(1, $c['calc']['shifts_matched_count']);
        $this->assertSame(0, $c['calc']['non_member_skipped_count']);
        $this->assertTrue($c['calc']['profile_present']);
        $this->assertFalse($c['calc']['fallback_configured']);
        $this->assertSame('user', $c['result']['assignee_type']);
        $this->assertSame($this->operator->id, $c['result']['assignee_user_id']);
        $this->assertSame($assignment->id, $c['result']['assignment_id']);
        $this->assertSame('on_call', $c['result']['role']);

        $this->assertSystemLogged('incidents.on_call.notified', fn (array $c) => $c['outcome'] === 'ok'
            && $c['input']['incident_id'] === $incident->id
            && $c['input']['assignee_user_id'] === $this->operator->id
            && $c['result']['notification_id'] === $notification->id
            && $c['result']['notification_reused'] === false
            && $c['result']['forced_channel_types'] === ['web', 'push']);

        $json = json_encode($this->systemLogEntries());
        $this->assertStringNotContainsString((string) json_encode($this->operator->email), $json);
        $this->assertStringNotContainsString((string) json_encode($this->operator->name), $json);
        $this->assertStringNotContainsString((string) json_encode($incident->title), $json);
        $this->assertNoSensitiveDataLogged();
    }

    public function test_counts_every_shift_that_matches_the_schedule_and_the_first_member_wins(): void
    {
        $second = User::factory()->create();
        $this->team->members()->attach($second, ['role' => TeamRole::Member->value]);

        $this->makeScheduleProfile([
            'on_call' => [
                ['user_id' => $this->operator->id],
                ['user_id' => $second->id],
                ['user_id' => $second->id, 'start' => '00:00', 'end' => '00:00'],
            ],
        ]);

        $incident = $this->makeIncident();

        IncidentCreated::dispatch($incident);

        $this->assertSame(
            $this->operator->id,
            (int) IncidentAssignment::query()->where('incident_id', $incident->id)->whereNull('unassigned_at')->sole()->assigned_to_id,
        );

        $c = $this->assertSystemLogged('incidents.assignment.resolved', fn (array $c) => $c['outcome'] === 'ok');
        $this->assertSame(3, $c['calc']['shifts_count']);
        $this->assertSame(2, $c['calc']['shifts_matched_count']);
        $this->assertSame(0, $c['calc']['matched_shift_index']);
        $this->assertSame(0, $c['calc']['non_member_skipped_count']);
        $this->assertSame($this->operator->id, $c['result']['assignee_user_id']);
    }

    public function test_a_directed_notification_that_already_existed_is_marked_as_reused(): void
    {
        $this->makeScheduleProfile([
            'on_call' => [['user_id' => $this->operator->id]],
        ]);

        $incident = $this->makeIncident('critical');

        // Ya había un aviso con la misma event_key (reintento): SendNotification
        // devuelve el existente y no crea otro.
        $existing = app(SendNotification::class)->execute(
            teamId: $this->team->id,
            notificationType: 'incident.assigned.on_call',
            sourceType: NotificationSourceType::Incident,
            sourceReferenceId: (string) $incident->id,
            priority: NotificationPriority::Critical,
            triggeredByType: NotificationTriggeredByType::System,
            triggeredById: null,
            eventKey: 'incident_oncall_assigned:'.$incident->id,
        );

        IncidentCreated::dispatch($incident);

        $this->assertSame(1, Notification::withoutGlobalScopes()
            ->where('event_key', "incident_oncall_assigned:{$incident->id}")
            ->count());

        $this->assertSystemLogged('incidents.on_call.notified', fn (array $c) => $c['outcome'] === 'ok'
            && $c['result']['notification_id'] === $existing->id
            && $c['result']['notification_reused'] === true);
    }

    public function test_assigns_without_directed_notification_for_non_critical(): void
    {
        $this->makeScheduleProfile([
            'on_call' => [['user_id' => $this->operator->id]],
        ]);

        $incident = $this->makeIncident('medium');

        IncidentCreated::dispatch($incident);

        $this->assertSame(1, IncidentAssignment::query()->where('incident_id', $incident->id)->count());
        $this->assertSame(
            0,
            Notification::withoutGlobalScopes()
                ->where('event_key', "incident_oncall_assigned:{$incident->id}")
                ->count(),
        );

        $this->assertSystemLogged('incidents.assignment.resolved', fn (array $c) => $c['outcome'] === 'ok'
            && $c['result']['assignee_user_id'] === $this->operator->id);
        $this->assertSystemLogged('incidents.on_call.notified', fn (array $c) => $c['outcome'] === 'skipped'
            && $c['reason'] === 'not_critical'
            && $c['input']['incident_id'] === $incident->id
            && $c['input']['assignee_user_id'] === $this->operator->id);
    }

    public function test_does_nothing_without_schedule_profile(): void
    {
        $incident = $this->makeIncident();

        IncidentCreated::dispatch($incident);

        $this->assertSame(0, IncidentAssignment::query()->where('incident_id', $incident->id)->count());

        $this->assertSystemLogged('incidents.assignment.resolved', fn (array $c) => $c['outcome'] === 'skipped'
            && $c['reason'] === 'no_active_profile'
            && $c['input']['incident_id'] === $incident->id
            && $c['calc']['profile_present'] === false);
        $this->assertSystemNotLogged('incidents.on_call.notified');
    }

    public function test_never_assigns_a_user_outside_the_team(): void
    {
        $outsider = User::factory()->create();

        $this->makeScheduleProfile([
            'on_call' => [['user_id' => $outsider->id]],
        ]);

        $incident = $this->makeIncident();

        IncidentCreated::dispatch($incident);

        $this->assertSame(0, IncidentAssignment::query()->where('incident_id', $incident->id)->count());

        $c = $this->assertSystemLogged('incidents.assignment.resolved', fn (array $c) => $c['outcome'] === 'skipped'
            && $c['reason'] === 'no_eligible_member');
        $this->assertSame(1, $c['calc']['shifts_matched_count']);
        $this->assertSame(1, $c['calc']['non_member_skipped_count']);
        $this->assertNull($c['calc']['matched_shift_index']);

        // El id del usuario ajeno nunca aparece como valor de un *_user_id.
        $json = (string) json_encode($this->systemLogEntries());
        $this->assertDoesNotMatchRegularExpression('/"[a-z_]*user_id":'.$outsider->id.'\\b/', $json);
        $this->assertNoSensitiveDataLogged();
    }

    public function test_respects_shift_windows_and_falls_back(): void
    {
        $fallback = User::factory()->create();
        $this->team->members()->attach($fallback, ['role' => TeamRole::Member->value]);

        // A shift that can never match (zero-length window) forces the fallback.
        $this->makeScheduleProfile([
            'on_call' => [['user_id' => $this->operator->id, 'start' => '00:00', 'end' => '00:00']],
            'fallback_on_call_user_id' => $fallback->id,
        ]);

        $incident = $this->makeIncident();

        IncidentCreated::dispatch($incident);

        $assignment = IncidentAssignment::query()
            ->where('incident_id', $incident->id)
            ->whereNull('unassigned_at')
            ->sole();

        $this->assertSame($fallback->id, (int) $assignment->assigned_to_id);

        $c = $this->assertSystemLogged('incidents.assignment.resolved', fn (array $c) => $c['outcome'] === 'ok');
        $this->assertSame('fallback', $c['calc']['source']);
        $this->assertSame(0, $c['calc']['shifts_matched_count']);
        $this->assertNull($c['calc']['matched_shift_index']);
        $this->assertTrue($c['calc']['fallback_configured']);
        $this->assertTrue($c['calc']['fallback_is_member']);
        $this->assertSame($fallback->id, $c['result']['assignee_user_id']);
    }

    public function test_skips_incidents_that_already_have_an_assignment(): void
    {
        $this->makeScheduleProfile([
            'on_call' => [['user_id' => $this->operator->id]],
        ]);

        $incident = $this->makeIncident();

        IncidentAssignment::factory()->create([
            'incident_id' => $incident->id,
            'assigned_to_id' => $this->operator->id,
            'unassigned_at' => null,
        ]);

        IncidentCreated::dispatch($incident);

        $this->assertSame(
            1,
            IncidentAssignment::query()->where('incident_id', $incident->id)->count(),
            'an existing assignment must not be replaced by the on-call auto-assigner',
        );

        $this->assertSystemLogged('incidents.assignment.resolved', fn (array $c) => $c['outcome'] === 'skipped'
            && $c['reason'] === 'already_assigned'
            && $c['input'] === ['incident_id' => $incident->id, 'stage' => 'on_call_listener']);
    }

    public function test_a_rolled_back_creation_logs_no_assignment(): void
    {
        $this->makeScheduleProfile([
            'on_call' => [['user_id' => $this->operator->id]],
        ]);

        // Falla algo DENTRO de la transacción de la apertura (el vínculo del
        // evento raíz): un listener de IncidentCreated ya no puede revertirla
        // porque corre tras el commit (IncidentCreatedReactionsTest).
        Event::listen('eloquent.created: '.IncidentEventLink::class, fn () => throw new RuntimeException('boom'));

        $event = NormalizedEvent::factory()->create(['team_id' => $this->team->id]);

        try {
            app(CreateIncidentFromEvent::class)->execute($event, ['priority_code' => 'critical']);
            $this->fail('La creación debía lanzar.');
        } catch (RuntimeException $e) {
            $this->assertSame('boom', $e->getMessage());
        }

        $this->assertSame(0, Incident::withoutGlobalScopes()->count());
        $this->assertSame(0, IncidentAssignment::query()->count());
        $this->assertSystemNotLogged('incidents.assignment.resolved');
        $this->assertSystemNotLogged('incidents.on_call.notified');
        $this->assertSystemLogged('incidents.type.resolved');
    }

    public function test_a_malformed_later_shift_never_rolls_back_incident_creation(): void
    {
        $this->makeScheduleProfile([
            'on_call' => [
                ['user_id' => $this->operator->id],
                ['user_id' => $this->operator->id, 'days' => [['mon']]],
            ],
        ]);

        $event = NormalizedEvent::factory()->create(['team_id' => $this->team->id]);

        $incident = app(CreateIncidentFromEvent::class)->execute($event, ['priority_code' => 'critical']);

        $this->assertTrue(Incident::withoutGlobalScopes()->whereKey($incident->id)->exists());
        $this->assertSame(
            $this->operator->id,
            (int) IncidentAssignment::query()->where('incident_id', $incident->id)->whereNull('unassigned_at')->sole()->assigned_to_id,
        );

        $c = $this->assertSystemLogged('incidents.assignment.resolved', fn (array $c) => $c['outcome'] === 'ok'
            && $c['input']['incident_id'] === $incident->id);
        $this->assertSame(2, $c['calc']['shifts_count']);
        $this->assertSame(1, $c['calc']['shifts_matched_count']);
        $this->assertSame(1, $c['calc']['malformed_shifts_count']);
        $this->assertSame(0, $c['calc']['matched_shift_index']);
        $this->assertNoSensitiveDataLogged();
    }

    public function test_a_winning_shift_is_never_counted_as_malformed(): void
    {
        // start/end numéricos no lanzan: shiftMatches() los trata como "sin
        // horario" y el turno gana. La línea no puede contarlo como mal formado
        // ni dejar al ganador fuera de los turnos que casaron.
        $this->makeScheduleProfile([
            'on_call' => [
                ['user_id' => $this->operator->id, 'start' => 800, 'end' => 2000],
            ],
        ]);

        $event = NormalizedEvent::factory()->create(['team_id' => $this->team->id]);

        $incident = app(CreateIncidentFromEvent::class)->execute($event, ['priority_code' => 'critical']);

        $c = $this->assertSystemLogged('incidents.assignment.resolved', fn (array $c) => $c['outcome'] === 'ok'
            && $c['input']['incident_id'] === $incident->id);
        $this->assertSame(0, $c['calc']['matched_shift_index']);
        $this->assertSame(1, $c['calc']['shifts_matched_count']);
        $this->assertSame(0, $c['calc']['malformed_shifts_count']);
        $this->assertNoSensitiveDataLogged();
    }

    public function test_a_malformed_earlier_shift_never_rolls_back_a_panic_incident(): void
    {
        // El turno mal formado va ANTES del ganador: el bucle del ganador lo
        // evaluaba y strtolower() lanzaba un TypeError dentro de la
        // transacción de creación, revirtiendo el incidente de pánico.
        $this->makeScheduleProfile([
            'on_call' => [
                ['user_id' => $this->operator->id, 'days' => [['mon']]],
                ['user_id' => $this->operator->id],
            ],
        ]);

        $category = EventCategory::factory()->create(['code' => 'emergency']);
        $eventType = EventType::factory()->create(['code' => 'panic_button', 'category_id' => $category->id]);

        $event = NormalizedEvent::factory()->create([
            'team_id' => $this->team->id,
            'event_type_id' => $eventType->id,
            'event_category_id' => $category->id,
        ]);

        $incident = app(CreateIncidentFromEvent::class)->execute($event, ['priority_code' => 'critical']);

        $this->assertSame('panic_emergency', $incident->type->code);
        $this->assertTrue(Incident::withoutGlobalScopes()->whereKey($incident->id)->exists());
        $this->assertSame(
            $this->operator->id,
            (int) IncidentAssignment::query()->where('incident_id', $incident->id)->whereNull('unassigned_at')->sole()->assigned_to_id,
        );

        $c = $this->assertSystemLogged('incidents.assignment.resolved', fn (array $c) => $c['outcome'] === 'ok'
            && $c['input']['incident_id'] === $incident->id);
        $this->assertSame('shift', $c['calc']['source']);
        $this->assertSame(2, $c['calc']['shifts_count']);
        $this->assertSame(1, $c['calc']['shifts_matched_count']);
        $this->assertSame(1, $c['calc']['malformed_shifts_count']);
        $this->assertSame(1, $c['calc']['matched_shift_index']);
        $this->assertNoSensitiveDataLogged();
    }
}
