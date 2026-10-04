<?php

namespace Tests\Feature\Domains\Incidents;

use App\Domains\Incidents\Actions\AssignIncident;
use App\Domains\Incidents\Enums\AssigneeType;
use App\Domains\Incidents\Enums\IncidentCreatorType;
use App\Domains\Incidents\Events\IncidentAssigned;
use App\Domains\Incidents\Models\Incident;
use App\Domains\Incidents\Models\IncidentAssignment;
use App\Domains\Incidents\Models\IncidentPriority;
use App\Domains\Incidents\Models\IncidentStatus;
use App\Domains\Notifications\Enums\NotificationPriority;
use App\Domains\Notifications\Jobs\SendNotificationJob;
use App\Domains\Notifications\Listeners\NotifyOnIncidentAssigned;
use App\Domains\Notifications\Models\Notification;
use App\Models\Team;
use App\Models\User;
use Database\Seeders\AccessSeeder;
use Database\Seeders\IncidentsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Event;
use Tests\Concerns\AssertsSystemLog;
use Tests\Concerns\AssertsTenantIsolation;
use Tests\TestCase;

class NotifyOnIncidentAssignedTest extends TestCase
{
    use AssertsSystemLog, AssertsTenantIsolation, RefreshDatabase;

    private Team $team;

    private User $owner;

    private User $assignee;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(AccessSeeder::class);
        $this->seed(IncidentsSeeder::class);
        // Sólo la entrega: el listener en cola sí debe correr (CallQueuedListener).
        Bus::fake([SendNotificationJob::class]);

        $this->owner = User::factory()->create();
        $this->team = $this->owner->currentTeam;
        $this->assignee = User::factory()->create();
        $this->team->members()->attach($this->assignee, ['role' => 'member']);
    }

    private function incident(string $priority = 'high'): Incident
    {
        $p = IncidentPriority::query()->where('code', $priority)->firstOrFail();

        return Incident::factory()->open()->create(['team_id' => $this->team->id, 'incident_priority_id' => $p->id]);
    }

    private function assign(Incident $incident, User $to, ?User $by, ?string $role = null): void
    {
        app(AssignIncident::class)->execute(
            incident: $incident,
            assigneeType: AssigneeType::User,
            assigneeId: $to->id,
            role: $role,
            assignedByType: $by !== null ? IncidentCreatorType::User : IncidentCreatorType::System,
            assignedById: $by?->id,
        );
    }

    /**
     * @return Collection<int, Notification>
     */
    private function assignedNotices(): Collection
    {
        return Notification::withoutGlobalScopes()->where('notification_type', 'incident.assigned')->get();
    }

    public function test_a_manual_assignment_notifies_the_assignee_on_web_and_device(): void
    {
        $incident = $this->incident('high');

        $this->assertNoTenantLeak($this->team, fn () => $this->assign($incident, $this->assignee, $this->owner));

        $notice = $this->assignedNotices()->sole();
        $this->assertSame($this->team->id, $notice->team_id);
        $this->assertSame(NotificationPriority::High, $notice->priority);
        $this->assertSame(['web', 'push'], $notice->payload_json['force_channels']);
        $this->assertSame([(string) $this->assignee->id], array_column($notice->payload_json['recipients'], 'recipient_reference_id'));
        $this->assertSystemLogged('incidents.assignment.notified', fn (array $c) => $c['outcome'] === 'ok');
        $this->assertNoSensitiveDataLogged();
    }

    public function test_a_critical_incident_assignment_is_critical(): void
    {
        $this->assign($this->incident('critical'), $this->assignee, $this->owner);

        $this->assertSame(NotificationPriority::Critical, $this->assignedNotices()->sole()->priority);
    }

    public function test_assigning_to_yourself_does_not_notify(): void
    {
        $this->assign($this->incident(), $this->owner, $this->owner);

        $this->assertCount(0, $this->assignedNotices());
        $this->assertSystemLogged('incidents.assignment.notified', fn (array $c) => ($c['reason'] ?? null) === 'self_assigned');
        $this->assertNoSensitiveDataLogged();
    }

    public function test_on_call_assignment_is_not_duplicated(): void
    {
        $this->assign($this->incident('critical'), $this->assignee, null, 'on_call');

        $this->assertCount(0, $this->assignedNotices());
        $this->assertSystemLogged('incidents.assignment.notified', fn (array $c) => ($c['reason'] ?? null) === 'on_call_already_notified');
    }

    public function test_a_closed_incident_does_not_notify(): void
    {
        $incident = $this->incident();
        $incident->forceFill(['incident_status_id' => IncidentStatus::query()->where('is_terminal', true)->firstOrFail()->id])->save();

        $this->assign($incident, $this->assignee, $this->owner);

        $this->assertCount(0, $this->assignedNotices());
        $this->assertSystemLogged('incidents.assignment.notified', fn (array $c) => ($c['reason'] ?? null) === 'terminal');
    }

    public function test_a_non_user_assignee_does_not_notify(): void
    {
        $incident = $this->incident();

        app(AssignIncident::class)->execute($incident, AssigneeType::Team, $this->team->id);

        $this->assertCount(0, $this->assignedNotices());
        $this->assertSystemLogged('incidents.assignment.notified', fn (array $c) => ($c['reason'] ?? null) === 'not_user');
    }

    public function test_an_assignee_who_left_the_team_does_not_notify(): void
    {
        $incident = $this->incident();
        $this->assign($incident, $this->assignee, $this->owner);
        Notification::withoutGlobalScopes()->delete();
        $assignment = IncidentAssignment::query()->where('assigned_to_id', $this->assignee->id)->sole();
        $this->team->members()->detach($this->assignee);

        app(NotifyOnIncidentAssigned::class)->handle(new IncidentAssigned($incident->fresh(), $assignment->fresh()));

        $this->assertCount(0, $this->assignedNotices());
        $this->assertSystemLogged('incidents.assignment.notified', fn (array $c) => ($c['reason'] ?? null) === 'user_not_found');
    }

    public function test_a_superseded_assignment_does_not_notify(): void
    {
        $incident = $this->incident();
        $other = User::factory()->create();
        $this->team->members()->attach($other, ['role' => 'member']);

        Event::fake([IncidentAssigned::class]);
        $this->assign($incident, $this->assignee, $this->owner);
        $this->assign($incident, $other, $this->owner);
        $first = IncidentAssignment::query()->where('assigned_to_id', $this->assignee->id)->sole();

        app(NotifyOnIncidentAssigned::class)
            ->handle(new IncidentAssigned($incident->fresh(), $first->fresh()));

        $this->assertCount(0, $this->assignedNotices());
        $this->assertSystemLogged('incidents.assignment.notified', fn (array $c) => ($c['reason'] ?? null) === 'stale');
    }
}
