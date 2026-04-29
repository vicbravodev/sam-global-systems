<?php

namespace Tests\Feature\Domains\Context;

use App\Domains\Context\Models\EventContextSnapshot;
use App\Domains\Context\Models\EventMediaContext;
use App\Domains\Context\Models\EventMediaRequest;
use App\Domains\Context\Models\EventRelatedIncidentLink;
use App\Domains\Context\Models\Geofence;
use App\Domains\Normalization\Models\NormalizedEvent;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ContextTenantIsolationTest extends TestCase
{
    use RefreshDatabase;

    public function test_event_context_snapshot_scoped_to_current_team(): void
    {
        $userA = User::factory()->create();
        $userB = User::factory()->create();

        $eventA = NormalizedEvent::factory()->create(['team_id' => $userA->currentTeam->id]);
        $eventB = NormalizedEvent::factory()->create(['team_id' => $userB->currentTeam->id]);

        EventContextSnapshot::factory()->create([
            'normalized_event_id' => $eventA->id,
            'team_id' => $userA->currentTeam->id,
        ]);
        EventContextSnapshot::factory()->create([
            'normalized_event_id' => $eventB->id,
            'team_id' => $userB->currentTeam->id,
        ]);

        $this->actingAs($userA);
        $this->assertSame(1, EventContextSnapshot::query()->count());
        $this->assertSame(2, EventContextSnapshot::withoutGlobalScopes()->count());
    }

    public function test_geofence_scoped_to_current_team(): void
    {
        $userA = User::factory()->create();
        $userB = User::factory()->create();

        Geofence::factory()->create(['team_id' => $userA->currentTeam->id]);
        Geofence::factory()->create(['team_id' => $userB->currentTeam->id]);

        $this->actingAs($userA);
        $this->assertSame(1, Geofence::query()->count());
        $this->assertSame(2, Geofence::withoutGlobalScopes()->count());
    }

    public function test_event_media_context_scoped_to_current_team(): void
    {
        $userA = User::factory()->create();
        $userB = User::factory()->create();

        $eventA = NormalizedEvent::factory()->create(['team_id' => $userA->currentTeam->id]);
        $eventB = NormalizedEvent::factory()->create(['team_id' => $userB->currentTeam->id]);

        EventMediaContext::factory()->create([
            'team_id' => $userA->currentTeam->id,
            'normalized_event_id' => $eventA->id,
        ]);
        EventMediaContext::factory()->create([
            'team_id' => $userB->currentTeam->id,
            'normalized_event_id' => $eventB->id,
        ]);

        $this->actingAs($userA);
        $this->assertSame(1, EventMediaContext::query()->count());
        $this->assertSame(2, EventMediaContext::withoutGlobalScopes()->count());
    }

    public function test_event_media_request_scoped_to_current_team(): void
    {
        $userA = User::factory()->create();
        $userB = User::factory()->create();

        $eventA = NormalizedEvent::factory()->create(['team_id' => $userA->currentTeam->id]);
        $eventB = NormalizedEvent::factory()->create(['team_id' => $userB->currentTeam->id]);

        EventMediaRequest::factory()->create([
            'team_id' => $userA->currentTeam->id,
            'normalized_event_id' => $eventA->id,
        ]);
        EventMediaRequest::factory()->create([
            'team_id' => $userB->currentTeam->id,
            'normalized_event_id' => $eventB->id,
        ]);

        $this->actingAs($userA);
        $this->assertSame(1, EventMediaRequest::query()->count());
        $this->assertSame(2, EventMediaRequest::withoutGlobalScopes()->count());
    }

    public function test_event_related_incident_link_scoped_to_current_team(): void
    {
        $userA = User::factory()->create();
        $userB = User::factory()->create();

        $eventA = NormalizedEvent::factory()->create(['team_id' => $userA->currentTeam->id]);
        $eventB = NormalizedEvent::factory()->create(['team_id' => $userB->currentTeam->id]);

        EventRelatedIncidentLink::factory()->create([
            'team_id' => $userA->currentTeam->id,
            'normalized_event_id' => $eventA->id,
        ]);
        EventRelatedIncidentLink::factory()->create([
            'team_id' => $userB->currentTeam->id,
            'normalized_event_id' => $eventB->id,
        ]);

        $this->actingAs($userA);
        $this->assertSame(1, EventRelatedIncidentLink::query()->count());
        $this->assertSame(2, EventRelatedIncidentLink::withoutGlobalScopes()->count());
    }
}
