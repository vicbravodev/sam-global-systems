<?php

namespace Tests\Feature\Domains\AI;

use App\Domains\AI\Models\AIEventEvaluation;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AITenantIsolationTest extends TestCase
{
    use RefreshDatabase;

    public function test_belongs_to_tenant_scope_hides_foreign_team_evaluations(): void
    {
        $user = User::factory()->create();
        $otherUser = User::factory()->create();

        $mine = AIEventEvaluation::factory()->create(['team_id' => $user->currentTeam->id]);
        $foreign = AIEventEvaluation::factory()->create(['team_id' => $otherUser->currentTeam->id]);

        $this->actingAs($user);

        $evaluations = AIEventEvaluation::all();

        $this->assertCount(1, $evaluations);
        $this->assertSame($mine->id, $evaluations->first()->id);
        $this->assertNull(AIEventEvaluation::find($foreign->id));
    }
}
