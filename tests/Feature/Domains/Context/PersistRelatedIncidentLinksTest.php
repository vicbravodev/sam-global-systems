<?php

namespace Tests\Feature\Domains\Context;

use App\Domains\Assets\Models\Asset;
use App\Domains\Assets\Models\AssetType;
use App\Domains\Context\Actions\BuildEventContext;
use App\Domains\Context\Enums\IncidentRelationType;
use App\Domains\Context\Events\EventContextBuilt;
use App\Domains\Context\Models\EventRelatedIncidentLink;
use App\Domains\Drivers\Models\Driver;
use App\Domains\Incidents\Models\Incident;
use App\Domains\Incidents\Models\IncidentPriority;
use App\Domains\Incidents\Models\IncidentStatus;
use App\Domains\Incidents\Models\IncidentType;
use App\Domains\Normalization\Models\NormalizedEvent;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use Tests\TestCase;

class PersistRelatedIncidentLinksTest extends TestCase
{
    use RefreshDatabase;

    private int $teamId;

    protected function setUp(): void
    {
        parent::setUp();

        Event::fake([EventContextBuilt::class]);

        $user = User::factory()->create();
        $this->teamId = $user->currentTeam->id;
    }

    public function test_creates_same_asset_open_incident_link(): void
    {
        $assetType = AssetType::factory()->vehicle()->create();
        $asset = Asset::withoutGlobalScopes()->create([
            'team_id' => $this->teamId,
            'asset_type_id' => $assetType->id,
            'name' => 'Truck 1',
            'status' => 'active',
            'first_seen_at' => now(),
            'last_seen_at' => now(),
        ]);

        $incident = $this->createOpenIncident($asset);

        $event = NormalizedEvent::factory()->create([
            'team_id' => $this->teamId,
            'asset_id' => $asset->id,
            'occurred_at' => now(),
        ]);

        app(BuildEventContext::class)->execute($event);

        $link = EventRelatedIncidentLink::withoutGlobalScopes()
            ->where('normalized_event_id', $event->id)
            ->where('incident_id', $incident->id)
            ->first();

        $this->assertNotNull($link);
        $this->assertSame(IncidentRelationType::SameAssetOpenIncident, $link->relation_type);
        $this->assertSame($this->teamId, $link->team_id);
    }

    public function test_creates_same_driver_recent_incident_link(): void
    {
        $driver = Driver::factory()->create(['team_id' => $this->teamId]);

        $incident = $this->createOpenIncident(null, $driver);

        $event = NormalizedEvent::factory()->create([
            'team_id' => $this->teamId,
            'driver_id' => $driver->id,
            'occurred_at' => now(),
        ]);

        app(BuildEventContext::class)->execute($event);

        $link = EventRelatedIncidentLink::withoutGlobalScopes()
            ->where('normalized_event_id', $event->id)
            ->first();

        $this->assertNotNull($link);
        $this->assertSame(IncidentRelationType::SameDriverRecentIncident, $link->relation_type);
    }

    public function test_idempotent_links_dont_duplicate(): void
    {
        $assetType = AssetType::factory()->vehicle()->create();
        $asset = Asset::withoutGlobalScopes()->create([
            'team_id' => $this->teamId,
            'asset_type_id' => $assetType->id,
            'name' => 'Truck 1',
            'status' => 'active',
            'first_seen_at' => now(),
            'last_seen_at' => now(),
        ]);

        $this->createOpenIncident($asset);

        $event = NormalizedEvent::factory()->create([
            'team_id' => $this->teamId,
            'asset_id' => $asset->id,
            'occurred_at' => now(),
        ]);

        app(BuildEventContext::class)->execute($event);
        app(BuildEventContext::class)->execute($event->fresh());

        $this->assertSame(
            1,
            EventRelatedIncidentLink::withoutGlobalScopes()
                ->where('normalized_event_id', $event->id)
                ->count(),
        );
    }

    public function test_no_link_created_when_no_open_incidents(): void
    {
        $event = NormalizedEvent::factory()->create([
            'team_id' => $this->teamId,
            'occurred_at' => now(),
        ]);

        app(BuildEventContext::class)->execute($event);

        $this->assertSame(
            0,
            EventRelatedIncidentLink::withoutGlobalScopes()
                ->where('normalized_event_id', $event->id)
                ->count(),
        );
    }

    private function createOpenIncident(?Asset $asset = null, ?Driver $driver = null): Incident
    {
        $type = IncidentType::factory()->create();
        $status = IncidentStatus::factory()->create();
        $priority = IncidentPriority::factory()->create();

        return Incident::withoutGlobalScopes()->create([
            'team_id' => $this->teamId,
            'incident_type_id' => $type->id,
            'incident_status_id' => $status->id,
            'incident_priority_id' => $priority->id,
            'source_type' => 'normalized_event',
            'asset_id' => $asset?->id,
            'driver_id' => $driver?->id,
            'title' => 'Open',
            'summary' => 'still open',
            'opened_at' => now()->subMinutes(20),
            'created_by_type' => 'system',
        ]);
    }
}
