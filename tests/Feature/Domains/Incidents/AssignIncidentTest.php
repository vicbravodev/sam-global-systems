<?php

namespace Tests\Feature\Domains\Incidents;

use App\Domains\Incidents\Actions\AssignIncident;
use App\Domains\Incidents\Enums\AssigneeType;
use App\Domains\Incidents\Enums\IncidentCreatorType;
use App\Domains\Incidents\Enums\TimelineEntryType;
use App\Domains\Incidents\Events\IncidentAssigned;
use App\Domains\Incidents\Models\Incident;
use App\Domains\Incidents\Models\IncidentAssignment;
use App\Domains\Incidents\Models\IncidentTimeline;
use App\Models\User;
use Database\Seeders\IncidentsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use Tests\TestCase;

class AssignIncidentTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(IncidentsSeeder::class);
    }

    public function test_creates_assignment_and_unassigns_previous(): void
    {
        Event::fake([IncidentAssigned::class]);

        $user = User::factory()->create();
        $team = $user->currentTeam;
        $incident = Incident::factory()->create(['team_id' => $team->id]);

        $first = app(AssignIncident::class)->execute(
            incident: $incident,
            assigneeType: AssigneeType::User,
            assigneeId: 100,
            assignedByType: IncidentCreatorType::User,
            assignedById: $user->id,
        );

        $second = app(AssignIncident::class)->execute(
            incident: $incident,
            assigneeType: AssigneeType::User,
            assigneeId: 101,
            assignedByType: IncidentCreatorType::User,
            assignedById: $user->id,
        );

        $this->assertNotNull($first->fresh()->unassigned_at);
        $this->assertNull($second->fresh()->unassigned_at);

        $this->assertSame(2, IncidentAssignment::query()->where('incident_id', $incident->id)->count());

        $this->assertSame(2, IncidentTimeline::query()
            ->where('incident_id', $incident->id)
            ->where('entry_type', TimelineEntryType::Assigned->value)
            ->count());

        Event::assertDispatchedTimes(IncidentAssigned::class, 2);
    }
}
