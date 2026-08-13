<?php

namespace Tests\Feature\Domains\Incidents;

use App\Domains\Incidents\Actions\RequestIncidentReview;
use App\Domains\Incidents\Enums\IncidentStatusCode;
use App\Domains\Incidents\Enums\TimelineEntryType;
use App\Domains\Incidents\Events\IncidentStatusChanged;
use App\Domains\Incidents\Models\Incident;
use App\Domains\Incidents\Models\IncidentTimeline;
use App\Domains\Incidents\Support\IncidentUpdatedBroadcast;
use App\Models\Team;
use App\Models\User;
use Database\Seeders\IncidentStatusSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use RuntimeException;
use Tests\TestCase;

class RequestIncidentReviewTest extends TestCase
{
    use RefreshDatabase;

    private Team $team;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(IncidentStatusSeeder::class);
        Event::fake([IncidentStatusChanged::class, IncidentUpdatedBroadcast::class]);

        $this->team = User::factory()->create()->currentTeam;
    }

    public function test_moves_open_incident_to_in_review_with_timeline_and_events(): void
    {
        $incident = Incident::factory()->open()->create(['team_id' => $this->team->id]);

        $fresh = app(RequestIncidentReview::class)->execute($incident, reason: 'Footage contradicts the event.');

        $this->assertSame(IncidentStatusCode::InReview->value, $fresh->status?->code);

        $entry = IncidentTimeline::query()
            ->where('incident_id', $incident->id)
            ->where('entry_type', TimelineEntryType::StatusChanged->value)
            ->first();

        $this->assertNotNull($entry);
        $this->assertSame('Revisión humana solicitada', $entry->title);

        Event::assertDispatched(
            IncidentStatusChanged::class,
            fn (IncidentStatusChanged $event) => $event->newStatus === IncidentStatusCode::InReview->value
        );
        Event::assertDispatched(IncidentUpdatedBroadcast::class);
    }

    public function test_refuses_terminal_incident(): void
    {
        $incident = Incident::factory()->closed()->create(['team_id' => $this->team->id]);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('terminal');

        app(RequestIncidentReview::class)->execute($incident);
    }
}
