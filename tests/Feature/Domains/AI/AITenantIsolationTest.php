<?php

namespace Tests\Feature\Domains\AI;

use App\Domains\AI\Models\AIEventEvaluation;
use App\Domains\Normalization\Models\NormalizedEvent;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AITenantIsolationTest extends TestCase
{
    use RefreshDatabase;

    public function test_ai_event_evaluation_scoped_to_current_team(): void
    {
        $userA = User::factory()->create();
        $userB = User::factory()->create();

        $eventA = NormalizedEvent::factory()->create(['team_id' => $userA->currentTeam->id]);
        $eventB = NormalizedEvent::factory()->create(['team_id' => $userB->currentTeam->id]);

        AIEventEvaluation::factory()->create([
            'normalized_event_id' => $eventA->id,
            'team_id' => $userA->currentTeam->id,
        ]);
        AIEventEvaluation::factory()->create([
            'normalized_event_id' => $eventB->id,
            'team_id' => $userB->currentTeam->id,
        ]);

        $this->actingAs($userA);
        $this->assertSame(1, AIEventEvaluation::query()->count());
        $this->assertSame(2, AIEventEvaluation::withoutGlobalScopes()->count());
    }
}
