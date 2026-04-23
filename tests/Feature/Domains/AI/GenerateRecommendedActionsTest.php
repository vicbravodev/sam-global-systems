<?php

namespace Tests\Feature\Domains\AI;

use App\Domains\AI\Actions\GenerateRecommendedActions;
use App\Domains\AI\Enums\EvaluationPriority;
use App\Domains\AI\Enums\EventClassification;
use App\Domains\AI\Enums\RecommendedActionType;
use App\Domains\AI\Models\AIEventEvaluation;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class GenerateRecommendedActionsTest extends TestCase
{
    use RefreshDatabase;

    private int $teamId;

    protected function setUp(): void
    {
        parent::setUp();

        $user = User::factory()->create();
        $this->teamId = $user->currentTeam->id;
    }

    public function test_urgent_real_event_triggers_emergency_protocol_first(): void
    {
        $evaluation = AIEventEvaluation::factory()->create([
            'team_id' => $this->teamId,
            'classification' => EventClassification::RealEvent,
            'priority_level' => EvaluationPriority::Urgent,
            'risk_score' => 0.95,
        ]);

        $actions = app(GenerateRecommendedActions::class)->execute($evaluation);

        $this->assertCount(3, $actions);
        $this->assertSame(RecommendedActionType::TriggerEmergencyProtocol, $actions->first()->action_type);
        $this->assertTrue((bool) $actions->first()->requires_confirmation);
    }

    public function test_false_positive_produces_ignore_action(): void
    {
        $evaluation = AIEventEvaluation::factory()->create([
            'team_id' => $this->teamId,
            'classification' => EventClassification::FalsePositive,
            'priority_level' => EvaluationPriority::Low,
            'risk_score' => 0.1,
        ]);

        $actions = app(GenerateRecommendedActions::class)->execute($evaluation);

        $this->assertCount(1, $actions);
        $this->assertSame(RecommendedActionType::IgnoreEvent, $actions->first()->action_type);
    }

    public function test_pending_evidence_waits_for_media(): void
    {
        $evaluation = AIEventEvaluation::factory()->create([
            'team_id' => $this->teamId,
            'classification' => EventClassification::PendingEvidence,
            'priority_level' => EvaluationPriority::Normal,
            'risk_score' => 0.5,
        ]);

        $actions = app(GenerateRecommendedActions::class)->execute($evaluation);

        $this->assertSame(RecommendedActionType::WaitForMedia, $actions->first()->action_type);
        $this->assertSame(1, $actions->first()->priority);
    }

    public function test_regular_real_event_escalates_without_emergency_protocol(): void
    {
        $evaluation = AIEventEvaluation::factory()->create([
            'team_id' => $this->teamId,
            'classification' => EventClassification::RealEvent,
            'priority_level' => EvaluationPriority::High,
            'risk_score' => 0.7,
        ]);

        $actions = app(GenerateRecommendedActions::class)->execute($evaluation);

        $this->assertCount(2, $actions);
        $this->assertSame(RecommendedActionType::EscalateToOperator, $actions->first()->action_type);
    }
}
