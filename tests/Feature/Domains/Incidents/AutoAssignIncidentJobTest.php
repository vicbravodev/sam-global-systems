<?php

namespace Tests\Feature\Domains\Incidents;

use App\Domains\Incidents\Actions\AssignIncident;
use App\Domains\Incidents\Enums\AssigneeType;
use App\Domains\Incidents\Enums\IncidentCreatorType;
use App\Domains\Incidents\Enums\TimelineEntryType;
use App\Domains\Incidents\Jobs\AutoAssignIncidentJob;
use App\Domains\Incidents\Models\Incident;
use App\Domains\Incidents\Models\IncidentAssignment;
use App\Domains\Incidents\Models\IncidentTimeline;
use App\Models\User;
use Database\Seeders\IncidentsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AutoAssignIncidentJobTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(IncidentsSeeder::class);
    }

    public function test_assigns_unassigned_incident_to_default_queue(): void
    {
        $user = User::factory()->create();
        $incident = Incident::factory()->create(['team_id' => $user->currentTeam->id]);

        (new AutoAssignIncidentJob($incident->id))->handle(app(AssignIncident::class));

        $assignment = IncidentAssignment::query()
            ->where('incident_id', $incident->id)
            ->whereNull('unassigned_at')
            ->sole();

        $this->assertSame(AssigneeType::Queue, $assignment->assigned_to_type);
        $this->assertSame($incident->team_id, (int) $assignment->assigned_to_id);
    }

    public function test_is_idempotent_when_incident_already_assigned(): void
    {
        $user = User::factory()->create();
        $incident = Incident::factory()->create(['team_id' => $user->currentTeam->id]);

        $job = new AutoAssignIncidentJob($incident->id);
        $job->handle(app(AssignIncident::class));

        // Deduped events and B8 re-decisions re-dispatch the job: it must not
        // pile up identical assignments nor spam the timeline.
        $job->handle(app(AssignIncident::class));
        $job->handle(app(AssignIncident::class));

        $this->assertSame(1, IncidentAssignment::query()->where('incident_id', $incident->id)->count());

        $this->assertSame(1, IncidentTimeline::query()
            ->where('incident_id', $incident->id)
            ->where('entry_type', TimelineEntryType::Assigned->value)
            ->count());
    }

    public function test_does_not_steal_an_assignment_an_operator_already_took(): void
    {
        $user = User::factory()->create();
        $incident = Incident::factory()->create(['team_id' => $user->currentTeam->id]);

        app(AssignIncident::class)->execute(
            incident: $incident,
            assigneeType: AssigneeType::User,
            assigneeId: $user->id,
            assignedByType: IncidentCreatorType::User,
            assignedById: $user->id,
        );

        (new AutoAssignIncidentJob($incident->id))->handle(app(AssignIncident::class));

        $active = IncidentAssignment::query()
            ->where('incident_id', $incident->id)
            ->whereNull('unassigned_at')
            ->sole();

        $this->assertSame(AssigneeType::User, $active->assigned_to_type);
        $this->assertSame($user->id, (int) $active->assigned_to_id);
    }
}
