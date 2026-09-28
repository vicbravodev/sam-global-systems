<?php

namespace Tests\Feature\Domains\Decisions;

use App\Domains\AI\Enums\EvaluationPriority;
use App\Domains\AI\Enums\EventClassification;
use App\Domains\AI\Models\AIEventEvaluation;
use App\Domains\Decisions\Actions\EvaluateDecisionRules;
use App\Domains\Decisions\Events\DecisionMade;
use App\Domains\Normalization\Models\EventSeverity;
use App\Domains\Normalization\Models\NormalizedEvent;
use App\Models\User;
use Database\Seeders\DecisionOutcomeSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use Tests\TestCase;

/**
 * El motivo de la decisión se muestra a los operadores: debe estar en
 * español y sin jerga interna (nada de "Mapped from AI classification").
 */
class DecisionReasonSpanishTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(DecisionOutcomeSeeder::class);
        Event::fake([DecisionMade::class]);
    }

    public function test_ai_mapped_decision_reason_is_in_spanish(): void
    {
        $teamId = User::factory()->create()->currentTeam->id;
        $event = NormalizedEvent::factory()->create([
            'team_id' => $teamId,
            'event_severity_id' => EventSeverity::factory()->create(['code' => 'medium'])->id,
        ]);

        $evaluation = AIEventEvaluation::factory()->create([
            'team_id' => $teamId,
            'normalized_event_id' => $event->id,
            'classification' => EventClassification::RealEvent,
            'confidence_score' => 0.9,
            'risk_score' => 0.7,
            'priority_level' => EvaluationPriority::High,
        ]);

        $decision = app(EvaluateDecisionRules::class)->execute($evaluation);

        $this->assertSame('Decisión según la clasificación de la IA: evento real.', $decision->decision_reason);
        $this->assertStringNotContainsString('Mapped from', (string) $decision->decision_reason);
    }
}
