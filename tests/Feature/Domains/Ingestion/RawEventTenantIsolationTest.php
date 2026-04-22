<?php

namespace Tests\Feature\Domains\Ingestion;

use App\Domains\Ingestion\Actions\StoreRawEvent;
use App\Domains\Ingestion\Events\RawEventReceived;
use App\Domains\Ingestion\Models\RawEvent;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use Tests\TestCase;

class RawEventTenantIsolationTest extends TestCase
{
    use RefreshDatabase;

    public function test_raw_event_scoped_to_team(): void
    {
        Event::fake([RawEventReceived::class]);

        $userA = User::factory()->create();
        $teamA = $userA->currentTeam;

        $userB = User::factory()->create();
        $teamB = $userB->currentTeam;

        $action = app(StoreRawEvent::class);

        $action->execute(
            payload: ['eventType' => 'TeamA-Event', 'eventId' => 'team-a-001'],
            sourceType: 'webhook',
            teamId: $teamA->id,
            providerId: null,
            externalEventId: 'team-a-001',
        );

        $action->execute(
            payload: ['eventType' => 'TeamA-Event', 'eventId' => 'team-a-002'],
            sourceType: 'webhook',
            teamId: $teamA->id,
            providerId: null,
            externalEventId: 'team-a-002',
        );

        $action->execute(
            payload: ['eventType' => 'TeamB-Event', 'eventId' => 'team-b-001'],
            sourceType: 'webhook',
            teamId: $teamB->id,
            providerId: null,
            externalEventId: 'team-b-001',
        );

        $this->actingAs($userA);
        $userA->switchTeam($teamA);

        $teamAEvents = RawEvent::all();

        $this->assertCount(
            2,
            $teamAEvents,
            'BelongsToTenant global scope should only return events for the authenticated team (Team A has 2)',
        );

        foreach ($teamAEvents as $event) {
            $this->assertEquals(
                $teamA->id,
                $event->team_id,
                'Every raw event returned should belong to Team A when Team A is the current team',
            );
        }

        $this->actingAs($userB);
        $userB->switchTeam($teamB);

        $teamBEvents = RawEvent::all();

        $this->assertCount(
            1,
            $teamBEvents,
            'BelongsToTenant global scope should only return events for Team B (Team B has 1)',
        );

        $this->assertEquals(
            $teamB->id,
            $teamBEvents->first()->team_id,
            'The single raw event returned should belong to Team B',
        );

        $allEvents = RawEvent::withoutGlobalScopes()->count();

        $this->assertEquals(
            3,
            $allEvents,
            'Without global scopes, all 3 raw events across both teams should be visible',
        );
    }

    public function test_admin_api_returns_only_current_team_events(): void
    {
        Event::fake([RawEventReceived::class]);

        $userA = User::factory()->create();
        $teamA = $userA->currentTeam;

        $userB = User::factory()->create();
        $teamB = $userB->currentTeam;

        $action = app(StoreRawEvent::class);

        $action->execute(
            payload: ['eventType' => 'Test', 'eventId' => 'api-a-001'],
            sourceType: 'webhook',
            teamId: $teamA->id,
            providerId: null,
            externalEventId: 'api-a-001',
        );

        $action->execute(
            payload: ['eventType' => 'Test', 'eventId' => 'api-b-001'],
            sourceType: 'webhook',
            teamId: $teamB->id,
            providerId: null,
            externalEventId: 'api-b-001',
        );

        $this->actingAs($userA);
        $userA->switchTeam($teamA);

        $response = $this->getJson("/api/{$teamA->slug}/events/raw");

        $response->assertOk();

        $data = $response->json('data');

        $this->assertCount(
            1,
            $data,
            'Admin API should only return raw events belonging to the current team',
        );

        $this->assertEquals(
            $teamA->id,
            $data[0]['team_id'],
            'The returned raw event should belong to Team A',
        );
    }
}
