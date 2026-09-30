<?php

namespace Tests\Feature\Domains\Incidents;

use App\Domains\Context\Events\EventMediaAvailable;
use App\Domains\Context\Models\EventMediaContext;
use App\Domains\Incidents\Listeners\RefreshIncidentOnEventMediaAvailable;
use App\Domains\Incidents\Models\Incident;
use App\Domains\Incidents\Support\IncidentUpdatedBroadcast;
use App\Domains\Normalization\Models\NormalizedEvent;
use App\Models\Team;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use Tests\Concerns\AssertsSystemLog;
use Tests\Concerns\AssertsTenantIsolation;
use Tests\TestCase;

/**
 * Panic footage lands after the incident opened: the detail and the inbox
 * preview must be told to reload without waiting for the AI.
 */
class RefreshIncidentOnEventMediaAvailableTest extends TestCase
{
    use AssertsSystemLog;
    use AssertsTenantIsolation;
    use RefreshDatabase;

    private Team $team;

    protected function setUp(): void
    {
        parent::setUp();

        Event::fake([IncidentUpdatedBroadcast::class]);

        $this->team = User::factory()->create()->currentTeam;
    }

    private function mediaFor(NormalizedEvent $event): EventMediaContext
    {
        return EventMediaContext::factory()->create([
            'team_id' => $event->team_id,
            'normalized_event_id' => $event->id,
        ]);
    }

    private function handle(EventMediaContext $media, NormalizedEvent $event): void
    {
        app(RefreshIncidentOnEventMediaAvailable::class)->handle(new EventMediaAvailable($media, $event));
    }

    public function test_new_media_broadcasts_an_update_of_the_incident(): void
    {
        $event = NormalizedEvent::factory()->create(['team_id' => $this->team->id]);
        $incident = Incident::factory()->open()->create(['team_id' => $this->team->id, 'related_event_id' => $event->id]);
        $media = $this->mediaFor($event);

        $this->handle($media, $event);

        Event::assertDispatched(IncidentUpdatedBroadcast::class, fn (IncidentUpdatedBroadcast $b) => $b->incidentId === $incident->id
            && $b->teamId === $this->team->id);
        $this->assertSystemLogged('incidents.media.refresh_broadcast', fn (array $c) => $c['input']['incident_id'] === $incident->id
            && $c['input']['event_media_context_id'] === $media->id
            && $c['calc']['window_seconds'] === RefreshIncidentOnEventMediaAvailable::BURST_WINDOW_SECONDS);
        $this->assertNoSensitiveDataLogged();
    }

    public function test_a_sweep_burst_collapses_into_one_broadcast(): void
    {
        $event = NormalizedEvent::factory()->create(['team_id' => $this->team->id]);
        Incident::factory()->open()->create(['team_id' => $this->team->id, 'related_event_id' => $event->id]);

        foreach (range(1, 4) as $_) {
            $this->handle($this->mediaFor($event), $event);
        }

        Event::assertDispatchedTimes(IncidentUpdatedBroadcast::class, 1);
        $this->assertCount(3, $this->systemLogEntries('incidents.media.refresh_skipped'));
        $this->assertSystemLogged('incidents.media.refresh_skipped', fn (array $c) => $c['reason'] === 'burst_coalesced');

        // Once the window passes, later footage refreshes the screens again.
        $this->travel(RefreshIncidentOnEventMediaAvailable::BURST_WINDOW_SECONDS + 1)->seconds();
        $this->handle($this->mediaFor($event), $event);

        Event::assertDispatchedTimes(IncidentUpdatedBroadcast::class, 2);
    }

    public function test_media_of_an_event_without_incident_broadcasts_nothing(): void
    {
        $event = NormalizedEvent::factory()->create(['team_id' => $this->team->id]);

        $this->handle($this->mediaFor($event), $event);

        Event::assertNotDispatched(IncidentUpdatedBroadcast::class);
        $this->assertSystemLogged('incidents.media.refresh_skipped', fn (array $c) => $c['reason'] === 'no_incident');
    }

    public function test_never_touches_another_tenant_incident(): void
    {
        $other = User::factory()->create()->currentTeam;
        $event = NormalizedEvent::factory()->create(['team_id' => $this->team->id]);
        Incident::factory()->open()->create(['team_id' => $this->team->id, 'related_event_id' => $event->id]);
        // Same event id referenced from another tenant's incident (corrupt row):
        // it must never be the one refreshed.
        Incident::factory()->open()->create(['team_id' => $other->id, 'related_event_id' => $event->id]);
        $media = $this->mediaFor($event);

        $this->assertNoTenantLeak($this->team, fn () => $this->handle($media, $event));

        Event::assertDispatched(IncidentUpdatedBroadcast::class, fn (IncidentUpdatedBroadcast $b) => $b->teamId === $this->team->id);
        Event::assertNotDispatched(IncidentUpdatedBroadcast::class, fn (IncidentUpdatedBroadcast $b) => $b->teamId === $other->id);
    }
}
