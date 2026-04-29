<?php

namespace Tests\Feature\Domains\Incidents;

use App\Domains\Incidents\Actions\CloseIncident;
use App\Domains\Incidents\Enums\IncidentCreatorType;
use App\Domains\Incidents\Enums\IncidentStatusCode;
use App\Domains\Incidents\Enums\ResolutionCode;
use App\Domains\Incidents\Enums\TimelineEntryType;
use App\Domains\Incidents\Events\IncidentClosed;
use App\Domains\Incidents\Events\IncidentResolved;
use App\Domains\Incidents\Models\Incident;
use App\Domains\Incidents\Models\IncidentResolution;
use App\Domains\Incidents\Models\IncidentTimeline;
use App\Models\User;
use Database\Seeders\IncidentsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use Tests\TestCase;

class CloseIncidentTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(IncidentsSeeder::class);
    }

    public function test_resolves_incident_and_records_resolution(): void
    {
        Event::fake([IncidentResolved::class, IncidentClosed::class]);

        $user = User::factory()->create();
        $team = $user->currentTeam;
        $incident = Incident::factory()->create(['team_id' => $team->id]);

        $resolution = app(CloseIncident::class)->execute(
            incident: $incident,
            resolutionCode: ResolutionCode::HandledSuccessfully,
            summary: 'Operator confirmed driver is safe.',
            rootCause: 'False alarm — accidental panic press.',
            correctiveAction: 'Driver re-trained on panic button usage.',
            resolvedByType: IncidentCreatorType::User,
            resolvedById: $user->id,
        );

        $this->assertInstanceOf(IncidentResolution::class, $resolution);
        $this->assertSame(ResolutionCode::HandledSuccessfully, $resolution->resolution_code);

        $fresh = $incident->fresh();
        $this->assertSame(IncidentStatusCode::Resolved->value, $fresh->status->code);
        $this->assertNotNull($fresh->resolved_at);

        $this->assertDatabaseHas('incident_timelines', [
            'incident_id' => $incident->id,
            'entry_type' => TimelineEntryType::Resolved->value,
        ]);

        Event::assertDispatched(IncidentResolved::class);
        Event::assertNotDispatched(IncidentClosed::class);
    }

    public function test_close_without_resolution_marks_as_closed(): void
    {
        Event::fake([IncidentClosed::class]);

        $user = User::factory()->create();
        $team = $user->currentTeam;
        $incident = Incident::factory()->create(['team_id' => $team->id]);

        app(CloseIncident::class)->execute(
            incident: $incident,
            resolutionCode: ResolutionCode::UnresolvedClosed,
            summary: 'Closing without further action.',
            resolvedByType: IncidentCreatorType::User,
            resolvedById: $user->id,
        );

        $fresh = $incident->fresh();
        $this->assertSame(IncidentStatusCode::Closed->value, $fresh->status->code);
        $this->assertNotNull($fresh->closed_at);

        $this->assertDatabaseHas('incident_timelines', [
            'incident_id' => $incident->id,
            'entry_type' => TimelineEntryType::Closed->value,
        ]);

        Event::assertDispatched(IncidentClosed::class);
    }

    public function test_false_positive_resolution_sets_false_positive_status_and_timestamp(): void
    {
        $user = User::factory()->create();
        $team = $user->currentTeam;
        $incident = Incident::factory()->create(['team_id' => $team->id]);

        app(CloseIncident::class)->execute(
            incident: $incident,
            resolutionCode: ResolutionCode::FalsePositive,
            summary: 'Sensor noise.',
            resolvedByType: IncidentCreatorType::User,
            resolvedById: $user->id,
        );

        $fresh = $incident->fresh();
        $this->assertSame(IncidentStatusCode::FalsePositive->value, $fresh->status->code);
        $this->assertNotNull($fresh->false_positive_at);
    }

    public function test_resolution_records_root_cause_and_corrective_action(): void
    {
        $user = User::factory()->create();
        $team = $user->currentTeam;
        $incident = Incident::factory()->create(['team_id' => $team->id]);

        $resolution = app(CloseIncident::class)->execute(
            incident: $incident,
            resolutionCode: ResolutionCode::HandledSuccessfully,
            summary: 'Resolved via dispatch',
            rootCause: 'driver fatigue',
            correctiveAction: 'forced 30-min stop',
            preventiveAction: 'shift schedule review',
            resolvedByType: IncidentCreatorType::User,
            resolvedById: $user->id,
        );

        $this->assertSame('driver fatigue', $resolution->root_cause);
        $this->assertSame('forced 30-min stop', $resolution->corrective_action);
        $this->assertSame('shift schedule review', $resolution->preventive_action);
    }

    public function test_idempotent_resolution_updates_existing_record(): void
    {
        $user = User::factory()->create();
        $team = $user->currentTeam;
        $incident = Incident::factory()->create(['team_id' => $team->id]);

        app(CloseIncident::class)->execute(
            $incident,
            ResolutionCode::HandledSuccessfully,
            'first',
        );

        app(CloseIncident::class)->execute(
            $incident,
            ResolutionCode::HandledSuccessfully,
            'second',
        );

        $this->assertSame(1, IncidentResolution::query()->where('incident_id', $incident->id)->count());
        $this->assertSame('second', IncidentResolution::query()->where('incident_id', $incident->id)->value('resolution_summary'));
    }

    public function test_timeline_includes_resolution_payload(): void
    {
        $user = User::factory()->create();
        $team = $user->currentTeam;
        $incident = Incident::factory()->create(['team_id' => $team->id]);

        app(CloseIncident::class)->execute(
            incident: $incident,
            resolutionCode: ResolutionCode::OperatorConfirmedSafe,
            summary: 'Driver waved on camera.',
        );

        $entry = IncidentTimeline::query()
            ->where('incident_id', $incident->id)
            ->where('entry_type', TimelineEntryType::Resolved->value)
            ->first();

        $this->assertNotNull($entry);
        $this->assertSame(ResolutionCode::OperatorConfirmedSafe->value, $entry->payload_json['resolution_code']);
    }
}
