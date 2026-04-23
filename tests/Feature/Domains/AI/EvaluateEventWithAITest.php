<?php

namespace Tests\Feature\Domains\AI;

use App\Contracts\AI\EventEvaluationAgent;
use App\Contracts\NullImplementations\NullEventEvaluationAgent;
use App\Domains\AI\Actions\EvaluateEventWithAI;
use App\Domains\AI\Enums\EvaluationMode;
use App\Domains\AI\Enums\EventClassification;
use App\Domains\AI\Models\AIDecisionSignal;
use App\Domains\AI\Models\AIExplanation;
use App\Domains\AI\Models\AIInferenceLog;
use App\Domains\AI\Models\AIRecommendedAction;
use App\Domains\Context\Actions\BuildEventContext;
use App\Domains\Normalization\Models\NormalizedEvent;
use App\Models\User;
use Database\Seeders\AIMeterSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class EvaluateEventWithAITest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(AIMeterSeeder::class);
    }

    public function test_event_evaluates_with_ai_agent_persisting_full_record(): void
    {
        $user = User::factory()->create();
        $team = $user->currentTeam;

        $event = NormalizedEvent::factory()->create([
            'team_id' => $team->id,
            'payload_normalized_json' => ['severity' => 'high'],
        ]);

        app(BuildEventContext::class)->execute($event);

        $evaluation = app(EvaluateEventWithAI::class)->execute($event->fresh());

        $this->assertSame($event->id, $evaluation->normalized_event_id);
        $this->assertSame(EvaluationMode::AiText, $evaluation->evaluation_mode);
        $this->assertSame(EventClassification::RealEvent, $evaluation->classification);
        $this->assertSame('null-agent:1.0', $evaluation->model_used);

        $this->assertDatabaseHas('ai_explanations', ['evaluation_id' => $evaluation->id]);
        $this->assertTrue(AIDecisionSignal::where('evaluation_id', $evaluation->id)->exists());
        $this->assertTrue(AIInferenceLog::where('evaluation_id', $evaluation->id)->exists());
        $this->assertTrue(AIRecommendedAction::where('evaluation_id', $evaluation->id)->exists());
    }

    public function test_false_positive_short_circuit_via_known_noise_signature(): void
    {
        $user = User::factory()->create();
        $team = $user->currentTeam;

        $event = NormalizedEvent::factory()->create([
            'team_id' => $team->id,
            'payload_normalized_json' => ['severity' => 'low', 'signature' => 'heartbeat'],
        ]);

        $evaluation = app(EvaluateEventWithAI::class)->execute($event);

        $this->assertSame(EvaluationMode::RulesOnly, $evaluation->evaluation_mode);
        $this->assertSame(EventClassification::FalsePositive, $evaluation->classification);
        $this->assertSame('rules_engine:1.0', $evaluation->model_used);
    }

    public function test_fallback_to_rules_only_when_agent_throws(): void
    {
        $user = User::factory()->create();
        $team = $user->currentTeam;

        $agent = app(EventEvaluationAgent::class);
        $this->assertInstanceOf(NullEventEvaluationAgent::class, $agent);
        $agent->shouldFail = true;

        $event = NormalizedEvent::factory()->create([
            'team_id' => $team->id,
            'payload_normalized_json' => ['severity' => 'medium'],
        ]);

        $evaluation = app(EvaluateEventWithAI::class)->execute($event);

        $this->assertSame(EvaluationMode::RulesOnly, $evaluation->evaluation_mode);
        $this->assertSame(EventClassification::Unclear, $evaluation->classification);
        $this->assertSame('rules_engine:1.0', $evaluation->model_used);

        $this->assertDatabaseHas('ai_inference_logs', [
            'evaluation_id' => $evaluation->id,
            'status' => 'error',
        ]);
    }

    public function test_explanation_is_always_created_for_evaluations(): void
    {
        $user = User::factory()->create();
        $team = $user->currentTeam;

        $event = NormalizedEvent::factory()->create([
            'team_id' => $team->id,
            'payload_normalized_json' => ['severity' => 'low'],
        ]);

        $evaluation = app(EvaluateEventWithAI::class)->execute($event);

        $explanation = AIExplanation::where('evaluation_id', $evaluation->id)->first();
        $this->assertNotNull($explanation);
        $this->assertNotEmpty($explanation->summary);
    }
}
