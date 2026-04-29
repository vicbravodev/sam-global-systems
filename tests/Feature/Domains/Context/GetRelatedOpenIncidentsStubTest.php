<?php

namespace Tests\Feature\Domains\Context;

use App\Domains\Assets\Models\Asset;
use App\Domains\Assets\Models\AssetType;
use App\Domains\Context\Actions\GetRelatedOpenIncidents;
use App\Domains\Incidents\Models\Incident;
use App\Domains\Incidents\Models\IncidentPriority;
use App\Domains\Incidents\Models\IncidentStatus;
use App\Domains\Incidents\Models\IncidentType;
use App\Domains\Normalization\Models\NormalizedEvent;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Closes SPEC-11-DEFERRED. The action now queries real incident records, but if no
 * incidents exist for the tenant the result must remain an empty collection so that
 * Context callers can compose the snapshot without branching.
 */
class GetRelatedOpenIncidentsStubTest extends TestCase
{
    use RefreshDatabase;

    public function test_action_returns_empty_collection_when_no_incidents_exist(): void
    {
        $user = User::factory()->create();
        $event = NormalizedEvent::factory()->create(['team_id' => $user->currentTeam->id]);

        $result = app(GetRelatedOpenIncidents::class)->execute($event);

        $this->assertTrue($result->isEmpty());
    }

    public function test_action_returns_open_incidents_for_same_asset(): void
    {
        $user = User::factory()->create();
        $team = $user->currentTeam;

        $assetType = AssetType::factory()->vehicle()->create();
        $asset = Asset::withoutGlobalScopes()->create([
            'team_id' => $team->id,
            'asset_type_id' => $assetType->id,
            'name' => 'Truck 1',
            'status' => 'active',
            'first_seen_at' => now(),
            'last_seen_at' => now(),
        ]);

        $type = IncidentType::factory()->create();
        $openStatus = IncidentStatus::factory()->create();
        $resolvedStatus = IncidentStatus::factory()->resolved()->create();
        $priority = IncidentPriority::factory()->create();

        $event = NormalizedEvent::factory()->create([
            'team_id' => $team->id,
            'asset_id' => $asset->id,
            'occurred_at' => now(),
        ]);

        $openIncident = Incident::withoutGlobalScopes()->create([
            'team_id' => $team->id,
            'incident_type_id' => $type->id,
            'incident_status_id' => $openStatus->id,
            'incident_priority_id' => $priority->id,
            'source_type' => 'normalized_event',
            'asset_id' => $asset->id,
            'title' => 'Open incident',
            'summary' => 'still open',
            'opened_at' => now()->subMinutes(30),
            'created_by_type' => 'system',
        ]);

        Incident::withoutGlobalScopes()->create([
            'team_id' => $team->id,
            'incident_type_id' => $type->id,
            'incident_status_id' => $resolvedStatus->id,
            'incident_priority_id' => $priority->id,
            'source_type' => 'normalized_event',
            'asset_id' => $asset->id,
            'title' => 'Closed incident',
            'summary' => 'already done',
            'opened_at' => now()->subMinutes(45),
            'resolved_at' => now()->subMinutes(40),
            'created_by_type' => 'system',
        ]);

        $result = app(GetRelatedOpenIncidents::class)->execute($event);

        $this->assertCount(1, $result);
        $this->assertSame($openIncident->id, $result->first()['incident_id']);
    }
}
