<?php

namespace Tests\Feature\Domains\Incidents;

use App\Domains\Incidents\Actions\LinkEventToIncident;
use App\Domains\Incidents\Enums\EventRelationType;
use App\Domains\Incidents\Models\Incident;
use App\Domains\Incidents\Models\IncidentEventLink;
use App\Domains\Normalization\Models\NormalizedEvent;
use App\Models\User;
use Database\Seeders\IncidentsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class LinkEventToIncidentTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(IncidentsSeeder::class);
    }

    public function test_links_event_creating_only_one_row_for_duplicate_call(): void
    {
        $user = User::factory()->create();
        $team = $user->currentTeam;

        $incident = Incident::factory()->create(['team_id' => $team->id]);
        $event = NormalizedEvent::factory()->create(['team_id' => $team->id]);

        app(LinkEventToIncident::class)->execute($incident, $event, EventRelationType::SupportingEvent);
        app(LinkEventToIncident::class)->execute($incident, $event, EventRelationType::SupportingEvent);

        $this->assertSame(1, IncidentEventLink::query()
            ->where('incident_id', $incident->id)
            ->where('normalized_event_id', $event->id)
            ->count());
    }
}
