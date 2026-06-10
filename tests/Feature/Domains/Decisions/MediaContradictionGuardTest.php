<?php

namespace Tests\Feature\Domains\Decisions;

use App\Domains\AI\Enums\EventClassification;
use App\Domains\AI\Models\AIEventEvaluation;
use App\Domains\AI\Models\AIMediaAssessment;
use App\Domains\Context\Models\EventMediaContext;
use App\Domains\Decisions\Actions\EvaluateDecisionRules;
use App\Domains\Decisions\Enums\DecisionOutcomeCode;
use App\Domains\Decisions\Events\DecisionMade;
use App\Domains\Decisions\Models\Decision;
use App\Domains\Normalization\Models\NormalizedEvent;
use App\Models\Team;
use App\Models\User;
use Database\Seeders\DecisionOutcomeSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use Tests\TestCase;

/**
 * Roadmap B8: footage contradicting an event the engine already acted on must
 * surface as REQUIRE_HUMAN_REVIEW instead of silently downgrading to IGNORE /
 * LOG_ONLY — the open incident is never auto-closed by the machine.
 */
class MediaContradictionGuardTest extends TestCase
{
    use RefreshDatabase;

    private Team $team;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(DecisionOutcomeSeeder::class);
        Event::fake([DecisionMade::class]);

        $this->team = User::factory()->create()->currentTeam;
    }

    /**
     * A first evaluation/decision pair that already acted on the event, plus a
     * fresh second evaluation whose classification would downgrade to IGNORE.
     *
     * @return array{0: NormalizedEvent, 1: AIEventEvaluation, 2: AIEventEvaluation}
     */
    private function makeReevaluatedEvent(bool $withPriorActionableDecision = true): array
    {
        $event = NormalizedEvent::factory()->create(['team_id' => $this->team->id]);

        $firstEvaluation = AIEventEvaluation::factory()->create([
            'team_id' => $this->team->id,
            'normalized_event_id' => $event->id,
            'evaluation_version' => 1,
        ]);

        if ($withPriorActionableDecision) {
            Decision::factory()->create([
                'team_id' => $this->team->id,
                'normalized_event_id' => $event->id,
                'ai_evaluation_id' => $firstEvaluation->id,
                'decision_code' => DecisionOutcomeCode::Incident->value,
            ]);
        }

        $secondEvaluation = AIEventEvaluation::factory()->create([
            'team_id' => $this->team->id,
            'normalized_event_id' => $event->id,
            'evaluation_version' => 2,
            'classification' => EventClassification::FalsePositive,
            'confidence_score' => 0.95,
            'risk_score' => 0.1,
        ]);

        return [$event, $firstEvaluation, $secondEvaluation];
    }

    private function attachAssessment(NormalizedEvent $event, AIEventEvaluation $evaluation, bool $contradicts): void
    {
        $media = EventMediaContext::factory()->create([
            'team_id' => $this->team->id,
            'normalized_event_id' => $event->id,
        ]);

        $factory = AIMediaAssessment::factory();

        if ($contradicts) {
            $factory = $factory->contradicts();
        }

        $factory->create([
            'evaluation_id' => $evaluation->id,
            'event_media_context_id' => $media->id,
        ]);
    }

    public function test_contradicting_media_with_prior_actionable_decision_requires_human_review(): void
    {
        [$event, $firstEvaluation, $secondEvaluation] = $this->makeReevaluatedEvent();

        // The assessment lives under evaluation v1 — the guard must still see
        // it when deciding for v2 (cross-version lookup).
        $this->attachAssessment($event, $firstEvaluation, contradicts: true);

        $decision = app(EvaluateDecisionRules::class)->execute($secondEvaluation);

        $this->assertSame(DecisionOutcomeCode::RequireHumanReview->value, $decision->decision_code);
        $this->assertTrue($decision->requires_human_review);
    }

    public function test_without_prior_actionable_decision_the_downgrade_stands(): void
    {
        [$event, , $secondEvaluation] = $this->makeReevaluatedEvent(withPriorActionableDecision: false);

        $this->attachAssessment($event, $secondEvaluation, contradicts: true);

        $decision = app(EvaluateDecisionRules::class)->execute($secondEvaluation);

        $this->assertSame(DecisionOutcomeCode::Ignore->value, $decision->decision_code);
        $this->assertFalse($decision->requires_human_review);
    }

    public function test_confirming_media_does_not_trigger_the_guard(): void
    {
        [$event, , $secondEvaluation] = $this->makeReevaluatedEvent();

        $this->attachAssessment($event, $secondEvaluation, contradicts: false);

        $decision = app(EvaluateDecisionRules::class)->execute($secondEvaluation);

        $this->assertSame(DecisionOutcomeCode::Ignore->value, $decision->decision_code);
    }
}
