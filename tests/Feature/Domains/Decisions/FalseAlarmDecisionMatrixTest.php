<?php

namespace Tests\Feature\Domains\Decisions;

use App\Domains\AI\Enums\EvaluationPriority;
use App\Domains\AI\Enums\EventClassification;
use App\Domains\AI\Models\AIEventEvaluation;
use App\Domains\Context\Models\EventContextSnapshot;
use App\Domains\Decisions\Actions\EvaluateDecisionRules;
use App\Domains\Decisions\Enums\DecisionOutcomeCode;
use App\Domains\Decisions\Enums\RuleScope;
use App\Domains\Decisions\Events\DecisionMade;
use App\Domains\Decisions\Models\Decision;
use App\Domains\Decisions\Models\DecisionOutcome;
use App\Domains\Decisions\Models\DecisionRule;
use App\Domains\Decisions\Models\RuleSet;
use App\Domains\Normalization\Models\EventCategory;
use App\Domains\Normalization\Models\EventSeverity;
use App\Domains\Normalization\Models\EventType;
use App\Domains\Normalization\Models\NormalizedEvent;
use App\Models\User;
use Database\Seeders\DecisionOutcomeSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use Tests\TestCase;

/**
 * Roadmap B6-P7 matrix: a clean panic is always urgent; only a panic that the
 * provider resolved AND that comes from a unit parked at its own base may be
 * degraded — and only to human review, only when the tenant opted in.
 */
class FalseAlarmDecisionMatrixTest extends TestCase
{
    use RefreshDatabase;

    private int $teamId;

    private EventType $panicType;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(DecisionOutcomeSeeder::class);
        Event::fake([DecisionMade::class]);

        $this->teamId = User::factory()->create()->currentTeam->id;

        $this->panicType = EventType::query()->create([
            'code' => 'panic_button',
            'name' => 'Panic Button',
            'category_id' => EventCategory::factory()->create()->id,
            'default_severity_id' => EventSeverity::factory()->create()->id,
            'is_active' => true,
        ]);
    }

    /**
     * @return array{0: AIEventEvaluation, 1: ?EventContextSnapshot}
     */
    private function makePanicEvaluation(?array $signals): array
    {
        $event = NormalizedEvent::factory()->create([
            'team_id' => $this->teamId,
            'event_type_id' => $this->panicType->id,
        ]);

        $eval = AIEventEvaluation::factory()->create([
            'normalized_event_id' => $event->id,
            'team_id' => $this->teamId,
            'classification' => EventClassification::RealEvent,
            'confidence_score' => 0.95,
            'risk_score' => 0.9,
            'priority_level' => EvaluationPriority::Urgent,
        ]);

        $context = null;

        if ($signals !== null) {
            $context = EventContextSnapshot::factory()->create([
                'team_id' => $this->teamId,
                'normalized_event_id' => $event->id,
                'signals_json' => $signals,
            ]);
        }

        return [$eval, $context];
    }

    private function makeRuleSet(bool $falseAlarmRuleActive): void
    {
        $ruleSet = RuleSet::factory()->create([
            'team_id' => $this->teamId,
            'code' => 'tenant-default',
            'is_default' => true,
            'is_active' => true,
        ]);

        $review = DecisionOutcome::firstWhere('code', DecisionOutcomeCode::RequireHumanReview->value);
        $incident = DecisionOutcome::firstWhere('code', DecisionOutcomeCode::Incident->value);

        DecisionRule::factory()->create([
            'team_id' => $this->teamId,
            'ruleset_id' => $ruleSet->id,
            'code' => 'panic-false-alarm-review',
            'scope' => RuleScope::EventType,
            'priority' => 110,
            'conditions_json' => [
                'all' => [
                    ['field' => 'event_type_code', 'operator' => 'eq', 'value' => 'panic_button'],
                    ['field' => 'external_resolved', 'operator' => 'eq', 'value' => true],
                    ['field' => 'parked_at_base', 'operator' => 'eq', 'value' => true],
                ],
            ],
            'outcome_override' => $review->id,
            'stop_processing' => true,
            'is_active' => $falseAlarmRuleActive,
        ]);

        DecisionRule::factory()->create([
            'team_id' => $this->teamId,
            'ruleset_id' => $ruleSet->id,
            'code' => 'panic-button-always-incident',
            'scope' => RuleScope::EventType,
            'priority' => 100,
            'conditions_json' => [
                'all' => [
                    ['field' => 'event_type_code', 'operator' => 'eq', 'value' => 'panic_button'],
                ],
            ],
            'outcome_override' => $incident->id,
            'stop_processing' => true,
            'is_active' => true,
        ]);
    }

    private function decide(AIEventEvaluation $eval, ?EventContextSnapshot $context): Decision
    {
        return app(EvaluateDecisionRules::class)->execute($eval, $context);
    }

    public function test_clean_panic_stays_urgent_incident(): void
    {
        $this->makeRuleSet(falseAlarmRuleActive: true);

        [$eval, $context] = $this->makePanicEvaluation([
            'external_resolved' => false,
            'parked_at_base' => false,
        ]);

        $decision = $this->decide($eval, $context);

        $this->assertSame(DecisionOutcomeCode::Incident->value, $decision->decision_code);
        $this->assertFalse($decision->requires_human_review);
    }

    public function test_resolved_and_parked_at_base_degrades_to_human_review(): void
    {
        $this->makeRuleSet(falseAlarmRuleActive: true);

        [$eval, $context] = $this->makePanicEvaluation([
            'external_resolved' => true,
            'parked_at_base' => true,
        ]);

        $decision = $this->decide($eval, $context);

        $this->assertSame(DecisionOutcomeCode::RequireHumanReview->value, $decision->decision_code);
        $this->assertTrue(
            $decision->requires_human_review,
            'the degraded outcome must still demand a human, never auto-dismiss',
        );
    }

    public function test_resolved_on_the_road_stays_urgent_incident(): void
    {
        $this->makeRuleSet(falseAlarmRuleActive: true);

        // Cancelled while driving, away from base: possible coercion.
        [$eval, $context] = $this->makePanicEvaluation([
            'external_resolved' => true,
            'parked_at_base' => false,
        ]);

        $decision = $this->decide($eval, $context);

        $this->assertSame(DecisionOutcomeCode::Incident->value, $decision->decision_code);
    }

    public function test_without_opt_in_the_default_never_degrades(): void
    {
        $this->makeRuleSet(falseAlarmRuleActive: false);

        [$eval, $context] = $this->makePanicEvaluation([
            'external_resolved' => true,
            'parked_at_base' => true,
        ]);

        $decision = $this->decide($eval, $context);

        $this->assertSame(DecisionOutcomeCode::Incident->value, $decision->decision_code);
    }

    public function test_missing_context_never_degrades(): void
    {
        $this->makeRuleSet(falseAlarmRuleActive: true);

        // AI/context pipeline down: no snapshot, so the false-alarm facts
        // default to false and the hard rule keeps the urgent incident.
        [$eval, $context] = $this->makePanicEvaluation(null);

        $decision = $this->decide($eval, $context);

        $this->assertSame(DecisionOutcomeCode::Incident->value, $decision->decision_code);
    }
}
