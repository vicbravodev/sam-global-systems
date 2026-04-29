<?php

namespace Tests\Feature\Domains\Decisions;

use App\Domains\AI\Models\AIEventEvaluation;
use App\Domains\Decisions\Models\Decision;
use App\Domains\Decisions\Models\EscalationPolicy;
use App\Domains\Normalization\Models\NormalizedEvent;
use App\Models\User;
use Database\Seeders\DecisionOutcomeSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class DecisionTenantIsolationTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(DecisionOutcomeSeeder::class);
    }

    public function test_decisions_scoped_to_current_team(): void
    {
        $userA = User::factory()->create();
        $userB = User::factory()->create();

        $eventA = NormalizedEvent::factory()->create(['team_id' => $userA->currentTeam->id]);
        $eventB = NormalizedEvent::factory()->create(['team_id' => $userB->currentTeam->id]);
        $evalA = AIEventEvaluation::factory()->create([
            'normalized_event_id' => $eventA->id,
            'team_id' => $userA->currentTeam->id,
        ]);
        $evalB = AIEventEvaluation::factory()->create([
            'normalized_event_id' => $eventB->id,
            'team_id' => $userB->currentTeam->id,
        ]);

        Decision::factory()->create([
            'normalized_event_id' => $eventA->id,
            'team_id' => $userA->currentTeam->id,
            'ai_evaluation_id' => $evalA->id,
        ]);
        Decision::factory()->create([
            'normalized_event_id' => $eventB->id,
            'team_id' => $userB->currentTeam->id,
            'ai_evaluation_id' => $evalB->id,
        ]);

        $this->actingAs($userA);
        $this->assertSame(1, Decision::query()->count());
        $this->assertSame(2, Decision::withoutGlobalScopes()->count());
    }

    public function test_escalation_policies_scoped_to_current_team(): void
    {
        $userA = User::factory()->create();
        $userB = User::factory()->create();

        EscalationPolicy::factory()->create(['team_id' => $userA->currentTeam->id]);
        EscalationPolicy::factory()->create(['team_id' => $userB->currentTeam->id]);

        $this->actingAs($userA);
        $this->assertSame(1, EscalationPolicy::query()->count());
        $this->assertSame(2, EscalationPolicy::withoutGlobalScopes()->count());
    }
}
