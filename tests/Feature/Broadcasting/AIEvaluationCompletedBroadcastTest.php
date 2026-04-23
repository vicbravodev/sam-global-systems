<?php

namespace Tests\Feature\Broadcasting;

use App\Domains\AI\Enums\EvaluationPriority;
use App\Domains\AI\Enums\EventClassification;
use App\Domains\AI\Events\AIEvaluationCompletedBroadcast;
use App\Domains\AI\Models\AIEventEvaluation;
use App\Models\User;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AIEvaluationCompletedBroadcastTest extends TestCase
{
    use RefreshDatabase;

    public function test_broadcasts_on_private_accounts_channel_for_evaluation_team(): void
    {
        $user = User::factory()->create();
        $evaluation = AIEventEvaluation::factory()->create([
            'team_id' => $user->currentTeam->id,
            'classification' => EventClassification::RealEvent,
            'priority_level' => EvaluationPriority::High,
            'confidence_score' => 0.82,
            'risk_score' => 0.75,
            'requires_action' => true,
        ]);

        $broadcast = new AIEvaluationCompletedBroadcast($evaluation);

        $channels = $broadcast->broadcastOn();

        $this->assertCount(1, $channels);
        $this->assertInstanceOf(PrivateChannel::class, $channels[0]);
        $this->assertSame("private-accounts.{$user->currentTeam->id}", $channels[0]->name);
    }

    public function test_broadcast_payload_matches_expected_shape(): void
    {
        $user = User::factory()->create();
        $evaluation = AIEventEvaluation::factory()->create([
            'team_id' => $user->currentTeam->id,
            'classification' => EventClassification::RealEvent,
            'priority_level' => EvaluationPriority::Urgent,
            'confidence_score' => 0.90,
            'risk_score' => 0.88,
            'requires_action' => true,
        ]);

        $payload = (new AIEvaluationCompletedBroadcast($evaluation))->broadcastWith();

        $this->assertSame([
            'evaluation_id',
            'normalized_event_id',
            'classification',
            'priority_level',
            'confidence_score',
            'risk_score',
            'requires_action',
        ], array_keys($payload));
        $this->assertSame('real_event', $payload['classification']);
        $this->assertSame('urgent', $payload['priority_level']);
        $this->assertTrue($payload['requires_action']);
    }

    public function test_broadcast_name_is_ai_evaluation_completed(): void
    {
        $user = User::factory()->create();
        $evaluation = AIEventEvaluation::factory()->create(['team_id' => $user->currentTeam->id]);

        $this->assertSame('ai.evaluation.completed', (new AIEvaluationCompletedBroadcast($evaluation))->broadcastAs());
    }
}
