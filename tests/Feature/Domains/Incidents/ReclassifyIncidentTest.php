<?php

namespace Tests\Feature\Domains\Incidents;

use App\Domains\Incidents\Actions\ReclassifyIncident;
use App\Domains\Incidents\Enums\TimelineEntryType;
use App\Domains\Incidents\Models\Incident;
use App\Domains\Incidents\Models\IncidentPriority;
use App\Domains\Incidents\Models\IncidentTimeline;
use App\Domains\Incidents\Models\IncidentType;
use App\Models\User;
use Database\Seeders\IncidentsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ReclassifyIncidentTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(IncidentsSeeder::class);
    }

    public function test_reclassify_preserves_original_type_in_timeline(): void
    {
        $user = User::factory()->create();
        $team = $user->currentTeam;

        $incident = Incident::factory()->create(['team_id' => $team->id]);
        $originalTypeId = $incident->incident_type_id;

        $newType = IncidentType::query()->where('code', 'collision')->first();
        $newPriority = IncidentPriority::query()->where('code', 'high')->first();

        app(ReclassifyIncident::class)->execute($incident, $newType, $newPriority);

        $entry = IncidentTimeline::query()
            ->where('incident_id', $incident->id)
            ->where('entry_type', TimelineEntryType::Reclassified->value)
            ->first();

        $this->assertNotNull($entry);
        $this->assertSame($originalTypeId, $entry->payload_json['previous_type_id']);
        $this->assertSame($newType->id, $entry->payload_json['new_type_id']);
        $this->assertSame($newPriority->id, $entry->payload_json['new_priority_id']);
    }
}
