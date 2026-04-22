<?php

namespace Tests\Feature\Domains\Normalization;

use App\Domains\Ingestion\Models\RawEvent;
use App\Domains\Normalization\Models\EventCategory;
use App\Domains\Normalization\Models\EventSeverity;
use App\Domains\Normalization\Models\EventType;
use App\Domains\Normalization\Models\NormalizedEvent;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class NormalizedEventScopeTest extends TestCase
{
    use RefreshDatabase;

    public function test_normalized_events_scoped_to_team(): void
    {
        $userA = User::factory()->create();
        $teamA = $userA->currentTeam;

        $userB = User::factory()->create();
        $teamB = $userB->currentTeam;

        $category = EventCategory::factory()->safety()->create();
        $severity = EventSeverity::factory()->medium()->create();
        $eventType = EventType::factory()->create([
            'category_id' => $category->id,
            'default_severity_id' => $severity->id,
        ]);

        NormalizedEvent::withoutGlobalScopes()->create([
            'raw_event_id' => RawEvent::factory()->create(['team_id' => $teamA->id])->id,
            'team_id' => $teamA->id,
            'event_type_id' => $eventType->id,
            'event_category_id' => $category->id,
            'event_severity_id' => $severity->id,
            'occurred_at' => now(),
            'processed_at' => now(),
            'payload_normalized_json' => ['event_type_code' => 'test'],
            'status' => 'normalized',
        ]);

        NormalizedEvent::withoutGlobalScopes()->create([
            'raw_event_id' => RawEvent::factory()->create(['team_id' => $teamB->id])->id,
            'team_id' => $teamB->id,
            'event_type_id' => $eventType->id,
            'event_category_id' => $category->id,
            'event_severity_id' => $severity->id,
            'occurred_at' => now(),
            'processed_at' => now(),
            'payload_normalized_json' => ['event_type_code' => 'test'],
            'status' => 'normalized',
        ]);

        $this->actingAs($userA);
        $userA->switchTeam($teamA);

        $teamAEvents = NormalizedEvent::all();

        $this->assertCount(
            1,
            $teamAEvents,
            'BelongsToTenant scope should return only NormalizedEvents belonging to the current team',
        );

        $this->assertEquals(
            $teamA->id,
            $teamAEvents->first()->team_id,
            'The returned NormalizedEvent should belong to team A, not team B',
        );
    }
}
