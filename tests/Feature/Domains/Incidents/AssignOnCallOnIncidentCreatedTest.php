<?php

namespace Tests\Feature\Domains\Incidents;

use App\Domains\Incidents\Events\IncidentCreated;
use App\Domains\Incidents\Models\Incident;
use App\Domains\Incidents\Models\IncidentAssignment;
use App\Domains\Incidents\Models\IncidentPriority;
use App\Domains\Notifications\Models\Notification;
use App\Domains\TenantConfig\Models\TenantScheduleProfile;
use App\Enums\TeamRole;
use App\Models\Team;
use App\Models\User;
use Database\Seeders\IncidentsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Tests\TestCase;

class AssignOnCallOnIncidentCreatedTest extends TestCase
{
    use RefreshDatabase;

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
    }

    public function test_does_nothing_without_schedule_profile(): void
    {
        $incident = $this->makeIncident();

        IncidentCreated::dispatch($incident);

        $this->assertSame(0, IncidentAssignment::query()->where('incident_id', $incident->id)->count());
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
    }
}
