<?php

namespace Tests\Feature\Domains\Context;

use App\Domains\Assets\Models\Asset;
use App\Domains\Context\Actions\BuildEventContext;
use App\Domains\Context\Enums\IncidentRelationType;
use App\Domains\Context\Events\EventContextBuilt;
use App\Domains\Context\Models\EventRelatedIncidentLink;
use App\Domains\Drivers\Models\Driver;
use App\Domains\Incidents\Models\Incident;
use App\Domains\Incidents\Models\IncidentEventLink;
use App\Domains\Normalization\Models\NormalizedEvent;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use Tests\TestCase;

class GetPriorSimilarIncidentsTest extends TestCase
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

    public function test_links_closed_incident_of_same_asset_within_window(): void
    {
        $asset = Asset::factory()->create(['team_id' => $this->teamId]);

        $incident = Incident::factory()->closed()->create([
            'team_id' => $this->teamId,
            'asset_id' => $asset->id,
            'opened_at' => now()->subDays(2),
            'closed_at' => now()->subDays(2)->addHours(1),
        ]);

        $event = NormalizedEvent::factory()->create([
            'team_id' => $this->teamId,
            'asset_id' => $asset->id,
            'occurred_at' => now(),
        ]);

        $snapshot = app(BuildEventContext::class)->execute($event);

        $link = EventRelatedIncidentLink::withoutGlobalScopes()
            ->where('normalized_event_id', $event->id)
            ->where('incident_id', $incident->id)
            ->first();

        $this->assertNotNull($link);
        $this->assertSame(IncidentRelationType::PriorSimilarIncident, $link->relation_type);
        $this->assertSame($this->teamId, $link->team_id);

        $priorRows = array_values(array_filter(
            $snapshot->incidents_snapshot_json ?? [],
            fn (array $row) => ($row['relation'] ?? null) === IncidentRelationType::PriorSimilarIncident->value,
        ));
        $this->assertCount(1, $priorRows);
        $this->assertSame($incident->id, $priorRows[0]['incident_id']);
        $this->assertNotNull($priorRows[0]['closed_at']);

        $this->assertTrue($snapshot->signals_json['has_prior_similar_incident']);
        $this->assertFalse($snapshot->signals_json['has_open_incident']);
    }

    public function test_links_closed_incident_of_same_driver_within_window(): void
    {
        $driver = Driver::factory()->create(['team_id' => $this->teamId]);

        $incident = Incident::factory()->closed()->create([
            'team_id' => $this->teamId,
            'driver_id' => $driver->id,
            'opened_at' => now()->subDays(5),
            'closed_at' => now()->subDays(5)->addHours(2),
        ]);

        $event = NormalizedEvent::factory()->create([
            'team_id' => $this->teamId,
            'driver_id' => $driver->id,
            'occurred_at' => now(),
        ]);

        app(BuildEventContext::class)->execute($event);

        $link = EventRelatedIncidentLink::withoutGlobalScopes()
            ->where('normalized_event_id', $event->id)
            ->where('incident_id', $incident->id)
            ->first();

        $this->assertNotNull($link);
        $this->assertSame(IncidentRelationType::PriorSimilarIncident, $link->relation_type);
    }

    public function test_ignores_closed_incident_older_than_window(): void
    {
        $asset = Asset::factory()->create(['team_id' => $this->teamId]);

        Incident::factory()->closed()->create([
            'team_id' => $this->teamId,
            'asset_id' => $asset->id,
            'opened_at' => now()->subDays(8),
            'closed_at' => now()->subDays(8)->addHours(1),
        ]);

        $event = NormalizedEvent::factory()->create([
            'team_id' => $this->teamId,
            'asset_id' => $asset->id,
            'occurred_at' => now(),
        ]);

        $snapshot = app(BuildEventContext::class)->execute($event);

        $this->assertSame(
            0,
            EventRelatedIncidentLink::withoutGlobalScopes()
                ->where('normalized_event_id', $event->id)
                ->count(),
        );
        $this->assertFalse($snapshot->signals_json['has_prior_similar_incident']);
    }

    public function test_ignores_closed_incident_of_another_asset(): void
    {
        $asset = Asset::factory()->create(['team_id' => $this->teamId]);
        $otherAsset = Asset::factory()->create(['team_id' => $this->teamId]);

        Incident::factory()->closed()->create([
            'team_id' => $this->teamId,
            'asset_id' => $otherAsset->id,
            'opened_at' => now()->subDays(2),
            'closed_at' => now()->subDays(2)->addHours(1),
        ]);

        $event = NormalizedEvent::factory()->create([
            'team_id' => $this->teamId,
            'asset_id' => $asset->id,
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

    public function test_ignores_closed_incident_of_another_tenant(): void
    {
        $asset = Asset::factory()->create(['team_id' => $this->teamId]);

        $otherUser = User::factory()->create();

        Incident::factory()->closed()->create([
            'team_id' => $otherUser->currentTeam->id,
            'asset_id' => $asset->id,
            'opened_at' => now()->subDays(2),
            'closed_at' => now()->subDays(2)->addHours(1),
        ]);

        $event = NormalizedEvent::factory()->create([
            'team_id' => $this->teamId,
            'asset_id' => $asset->id,
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

    public function test_ignores_non_terminal_incident_outside_open_window(): void
    {
        $asset = Asset::factory()->create(['team_id' => $this->teamId]);

        // Open (non-terminal) incident opened 2 days ago: outside the open-incidents
        // look-back window and not eligible as prior history either (not closed).
        Incident::factory()->open()->create([
            'team_id' => $this->teamId,
            'asset_id' => $asset->id,
            'opened_at' => now()->subDays(2),
        ]);

        $event = NormalizedEvent::factory()->create([
            'team_id' => $this->teamId,
            'asset_id' => $asset->id,
            'occurred_at' => now(),
        ]);

        $snapshot = app(BuildEventContext::class)->execute($event);

        $this->assertSame(
            0,
            EventRelatedIncidentLink::withoutGlobalScopes()
                ->where('normalized_event_id', $event->id)
                ->count(),
        );
        $this->assertFalse($snapshot->signals_json['has_prior_similar_incident']);
    }

    public function test_excludes_incident_already_linked_to_the_event(): void
    {
        $asset = Asset::factory()->create(['team_id' => $this->teamId]);

        $event = NormalizedEvent::factory()->create([
            'team_id' => $this->teamId,
            'asset_id' => $asset->id,
            'occurred_at' => now(),
        ]);

        // The incident this very event produced, later closed: it must not
        // reappear as its own "prior similar" history.
        $ownIncident = Incident::factory()->closed()->create([
            'team_id' => $this->teamId,
            'asset_id' => $asset->id,
            'opened_at' => now()->subHours(1),
            'closed_at' => now()->subMinutes(10),
        ]);

        IncidentEventLink::factory()->create([
            'incident_id' => $ownIncident->id,
            'normalized_event_id' => $event->id,
        ]);

        app(BuildEventContext::class)->execute($event);

        $this->assertSame(
            0,
            EventRelatedIncidentLink::withoutGlobalScopes()
                ->where('normalized_event_id', $event->id)
                ->where('relation_type', IncidentRelationType::PriorSimilarIncident->value)
                ->count(),
        );
    }

    public function test_reenrichment_is_idempotent(): void
    {
        $asset = Asset::factory()->create(['team_id' => $this->teamId]);

        Incident::factory()->closed()->create([
            'team_id' => $this->teamId,
            'asset_id' => $asset->id,
            'opened_at' => now()->subDays(2),
            'closed_at' => now()->subDays(2)->addHours(1),
        ]);

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
}
