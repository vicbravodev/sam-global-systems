<?php

namespace Tests\Feature\Domains\AI;

use App\Domains\AI\Actions\BuildAIInputContext;
use App\Domains\AI\Actions\EvaluateEventWithAI;
use App\Domains\AI\Actions\ReevaluateEventWithNewEvidence;
use App\Domains\AI\Actions\ResolveTenantAIProfile;
use App\Domains\AI\Enums\EvaluationMode;
use App\Domains\AI\Enums\EventClassification;
use App\Domains\AI\Enums\ReevaluationTrigger;
use App\Domains\AI\Models\AIEventEvaluation;
use App\Domains\AI\Models\AIMediaAssessment;
use App\Domains\AI\Support\MediaVerdictFusion;
use App\Domains\Context\Actions\BuildEventContext;
use App\Domains\Context\Models\EventMediaContext;
use App\Domains\Decisions\Jobs\RunDecisionEngineJob;
use App\Domains\Normalization\Models\EventCategory;
use App\Domains\Normalization\Models\EventSeverity;
use App\Domains\Normalization\Models\NormalizedEvent;
use App\Models\Team;
use App\Models\User;
use Database\Seeders\AIMeterSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Tests\Concerns\AssertsSystemLog;
use Tests\TestCase;

/**
 * El veredicto visual (ai_media_assessments) debe entrar a la evaluación:
 * el contexto del agente lo lleva, y la reevaluación lo fusiona en
 * confianza/riesgo/explicación/razonamiento de forma determinista.
 */
class MediaVerdictFusionTest extends TestCase
{
    use AssertsSystemLog;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(AIMeterSeeder::class);
    }

    /**
     * @return array{0: NormalizedEvent, 1: AIEventEvaluation, 2: Team}
     */
    private function evaluatedEvent(): array
    {
        $user = User::factory()->create();
        $team = $user->currentTeam;

        $event = NormalizedEvent::factory()->create([
            'team_id' => $team->id,
            'payload_normalized_json' => ['severity' => 'high'],
        ]);

        app(BuildEventContext::class)->execute($event);

        $baseline = app(EvaluateEventWithAI::class)->execute($event->fresh());

        return [$event->fresh(), $baseline, $team];
    }

    private function makeMedia(NormalizedEvent $event): EventMediaContext
    {
        return EventMediaContext::factory()->create([
            'team_id' => $event->team_id,
            'normalized_event_id' => $event->id,
        ]);
    }

    public function test_input_context_includes_latest_media_verdict_per_media(): void
    {
        [$event, $baseline, $team] = $this->evaluatedEvent();

        $mediaA = $this->makeMedia($event);
        $mediaB = $this->makeMedia($event);

        // Dos veredictos para la misma media desde versiones de evaluación
        // distintas (unique por evaluación+media): el más reciente gana.
        $laterEvaluation = AIEventEvaluation::factory()->create([
            'team_id' => $team->id,
            'normalized_event_id' => $event->id,
            'evaluation_version' => $baseline->evaluation_version + 1,
        ]);

        AIMediaAssessment::factory()->create([
            'evaluation_id' => $baseline->id,
            'event_media_context_id' => $mediaA->id,
            'assessed_at' => now()->subMinutes(10),
        ]);
        AIMediaAssessment::factory()->contradicts()->create([
            'evaluation_id' => $laterEvaluation->id,
            'event_media_context_id' => $mediaA->id,
            'assessed_at' => now(),
        ]);
        AIMediaAssessment::factory()->inconclusive()->create([
            'evaluation_id' => $baseline->id,
            'event_media_context_id' => $mediaB->id,
            'assessed_at' => now(),
        ]);

        $profile = app(ResolveTenantAIProfile::class)->execute($team->id);
        $context = app(BuildAIInputContext::class)->execute($event, null, $profile);

        $this->assertCount(2, $context->mediaAssessments);

        $byMedia = collect($context->mediaAssessments)->keyBy('media_context_id');

        $this->assertSame('contradicts_event', $byMedia[$mediaA->id]['result']);
        $this->assertSame('inconclusive', $byMedia[$mediaB->id]['result']);
        $this->assertArrayHasKey('confidence', $byMedia[$mediaA->id]);
        $this->assertArrayHasKey('summary', $byMedia[$mediaA->id]);

        $this->assertSame(
            $context->mediaAssessments,
            $context->toArray()['media_assessments'],
        );
    }

    public function test_reevaluation_with_contradicting_media_degrades_confidence_and_mentions_the_visual_verdict(): void
    {
        [$event, $baseline] = $this->evaluatedEvent();

        foreach ([1, 2] as $i) {
            AIMediaAssessment::factory()->contradicts()->create([
                'evaluation_id' => $baseline->id,
                'event_media_context_id' => $this->makeMedia($event)->id,
                'assessed_at' => now()->subMinutes($i),
            ]);
        }
        AIMediaAssessment::factory()->inconclusive()->create([
            'evaluation_id' => $baseline->id,
            'event_media_context_id' => $this->makeMedia($event)->id,
        ]);

        $reevaluation = app(ReevaluateEventWithNewEvidence::class)->execute(
            $event,
            ReevaluationTrigger::MediaArrived,
        );

        // NullEventEvaluationAgent devuelve 0.85; la fusión resta 0.15.
        $this->assertEqualsWithDelta(0.70, (float) $reevaluation->confidence_score, 0.001);
        $this->assertSame(EvaluationMode::Hybrid, $reevaluation->evaluation_mode);
        $this->assertEqualsWithDelta(
            round(max(0.0, (float) $baseline->risk_score - 0.15), 2),
            (float) $reevaluation->risk_score,
            0.001,
        );

        $steps = $reevaluation->signals_json['reasoning_steps'] ?? [];
        $this->assertTrue(
            collect($steps)->contains(fn (string $step) => str_contains($step, 'Análisis visual')),
            'La cadena de razonamiento debe incluir el veredicto visual.',
        );

        $this->assertStringContainsString('contradicen', $reevaluation->explanation_text);

        $factors = $reevaluation->signals_json['key_factors'] ?? [];
        $this->assertSame(2, $factors['media_contradicts_count'] ?? null);
        $this->assertSame(3, $factors['media_assessed_count'] ?? null);

        $ctx = $this->assertSystemLogged('ai.media_fusion.applied', fn (array $c): bool => $c['input']['evaluation_id'] === $reevaluation->id);
        $this->assertSame('real_event', $ctx['input']['classification']);
        $this->assertFalse($ctx['input']['is_critical_event']);
        $calc = $ctx['calc'];
        $this->assertSame('contradicts', $calc['branch']);
        $this->assertSame(2, $calc['media_contradicts_count']);
        $this->assertSame(3, $calc['media_assessed_count']);
        $this->assertSame(0, $calc['media_confirms_count']);
        $this->assertSame(0, $calc['media_visible_threat_count']);
        $this->assertFalse($calc['dismissive']);
        $this->assertSame([0.05, 0.99], $calc['confidence_bounds']);
        $this->assertSame([0.0, 1.0], $calc['risk_bounds']);

        $confidence = round(max($calc['confidence_bounds'][0], min($calc['confidence_bounds'][1], $calc['confidence_before'] + $calc['confidence_delta'])), 2);
        $this->assertSame($confidence, $calc['confidence_after']);
        $this->assertSame($confidence, (float) $reevaluation->confidence_score);

        $risk = round(max($calc['risk_bounds'][0], min($calc['risk_bounds'][1], $calc['risk_before'] + $calc['risk_delta'])), 2);
        $this->assertSame($risk, $calc['risk_after']);
        $this->assertSame($risk, (float) $reevaluation->risk_score);

        // La línea de fusión va antes de la prioridad de la misma evaluación.
        $codes = array_column(array_filter(
            $this->systemLogEntries(),
            fn (array $e): bool => ($e['context']['input']['evaluation_id'] ?? null) === $reevaluation->id,
        ), 'code');
        $this->assertLessThan(array_search('ai.priority.resolved', $codes, true), array_search('ai.media_fusion.applied', $codes, true));

        $this->assertNoSensitiveDataLogged();
        $this->assertStringNotContainsString('Análisis visual', json_encode($this->systemLogEntries()));
    }

    public function test_confirming_media_boosts_confidence(): void
    {
        [$event, $baseline] = $this->evaluatedEvent();

        foreach ([1, 2] as $i) {
            AIMediaAssessment::factory()->create([
                'evaluation_id' => $baseline->id,
                'event_media_context_id' => $this->makeMedia($event)->id,
                'assessed_at' => now()->subMinutes($i),
            ]);
        }

        $reevaluation = app(ReevaluateEventWithNewEvidence::class)->execute(
            $event,
            ReevaluationTrigger::MediaArrived,
        );

        $this->assertEqualsWithDelta(0.95, (float) $reevaluation->confidence_score, 0.001);
        $this->assertSame(EvaluationMode::Hybrid, $reevaluation->evaluation_mode);
        $this->assertStringContainsString('confirman', $reevaluation->explanation_text);

        $applied = $this->assertSystemLogged('ai.media_fusion.applied', fn (array $c): bool => $c['input']['evaluation_id'] === $reevaluation->id);
        $this->assertSame('confirms', $applied['calc']['branch']);
        $this->assertSame(2, $applied['calc']['media_confirms_count']);
    }

    public function test_evaluation_completed_lets_a_reader_recompute_the_fused_risk_and_confidence(): void
    {
        [$event, $baseline] = $this->evaluatedEvent();

        AIMediaAssessment::factory()->create([
            'evaluation_id' => $baseline->id,
            'event_media_context_id' => $this->makeMedia($event)->id,
        ]);

        $reevaluation = app(ReevaluateEventWithNewEvidence::class)->execute(
            $event,
            ReevaluationTrigger::MediaArrived,
        );

        $completed = $this->assertSystemLogged('ai.evaluation.completed', fn (array $c): bool => $c['result']['evaluation_id'] === $reevaluation->id);
        $calc = $completed['calc'];

        $this->assertTrue($calc['fusion_applied']);
        $this->assertNotSame(0.0, $calc['fusion_risk_delta']);
        $this->assertNotSame(0.0, $calc['fusion_confidence_delta']);
        $this->assertSame([0.0, 1.0], $calc['risk_clamp']);
        $this->assertSame([0.05, 0.99], $calc['confidence_clamp']);

        $risk = round(max($calc['risk_clamp'][0], min($calc['risk_clamp'][1], $calc['risk_after_agent'] + $calc['fusion_risk_delta'])), 2);
        $this->assertSame($risk, $calc['risk_score']);
        $this->assertSame($risk, (float) $reevaluation->risk_score);

        $confidence = round(max($calc['confidence_clamp'][0], min($calc['confidence_clamp'][1], $calc['base_confidence'] + $calc['fusion_confidence_delta'])), 2);
        $this->assertSame($confidence, $calc['confidence']);
        $this->assertSame($confidence, (float) $reevaluation->confidence_score);

        $this->assertNoSensitiveDataLogged();
    }

    public function test_reevaluation_without_media_assessments_is_unchanged(): void
    {
        [$event] = $this->evaluatedEvent();

        $reevaluation = app(ReevaluateEventWithNewEvidence::class)->execute(
            $event,
            ReevaluationTrigger::MediaArrived,
        );

        $this->assertEqualsWithDelta(0.85, (float) $reevaluation->confidence_score, 0.001);
        $this->assertSame(EvaluationMode::AiText, $reevaluation->evaluation_mode);
        $this->assertStringNotContainsString('Análisis visual', $reevaluation->explanation_text);

        $ctx = $this->assertSystemLogged('ai.media_fusion.skipped', fn (array $c): bool => $c['input']['evaluation_id'] === $reevaluation->id);
        $this->assertSame('no_media', $ctx['reason']);
        $this->assertSame(0, $ctx['calc']['media_assessed_count']);
        $entry = collect($this->systemLogEntries('ai.media_fusion.skipped'))
            ->first(fn (array $e): bool => $e['context']['input']['evaluation_id'] === $reevaluation->id);
        $this->assertSame('debug', $entry['level']);
        $this->assertSystemNotLogged('ai.media_fusion.applied');

        $completed = $this->assertSystemLogged('ai.evaluation.completed', fn (array $c): bool => $c['result']['evaluation_id'] === $reevaluation->id);
        $this->assertFalse($completed['calc']['fusion_applied']);
        $this->assertSame(0.0, $completed['calc']['fusion_risk_delta']);
        $this->assertSame($completed['calc']['risk_after_agent'], $completed['calc']['risk_score']);
        $this->assertNoSensitiveDataLogged();
    }

    public function test_inconclusive_only_assessments_leave_the_evaluation_unchanged(): void
    {
        [$event, $baseline] = $this->evaluatedEvent();

        foreach ([1, 2] as $i) {
            AIMediaAssessment::factory()->inconclusive()->create([
                'evaluation_id' => $baseline->id,
                'event_media_context_id' => $this->makeMedia($event)->id,
                'assessed_at' => now()->subMinutes($i),
            ]);
        }

        $reevaluation = app(ReevaluateEventWithNewEvidence::class)->execute(
            $event,
            ReevaluationTrigger::MediaArrived,
        );

        $this->assertEqualsWithDelta(0.85, (float) $reevaluation->confidence_score, 0.001);
        $this->assertSame(EvaluationMode::AiText, $reevaluation->evaluation_mode);

        $ctx = $this->assertSystemLogged('ai.media_fusion.skipped', fn (array $c): bool => $c['input']['evaluation_id'] === $reevaluation->id);
        $this->assertSame('no_verdict', $ctx['reason']);
        $this->assertSame(2, $ctx['calc']['media_assessed_count']);
        $entry = collect($this->systemLogEntries('ai.media_fusion.skipped'))
            ->first(fn (array $e): bool => $e['context']['input']['evaluation_id'] === $reevaluation->id);
        $this->assertSame('info', $entry['level']);
    }

    public function test_media_verdicts_from_other_tenants_do_not_leak_into_the_evaluation(): void
    {
        [$event] = $this->evaluatedEvent();

        // Otro tenant con su propio evento, media y veredicto contradictorio.
        $otherUser = User::factory()->create();
        $otherEvent = NormalizedEvent::factory()->create([
            'team_id' => $otherUser->currentTeam->id,
        ]);
        $otherEvaluation = AIEventEvaluation::factory()->create([
            'team_id' => $otherUser->currentTeam->id,
            'normalized_event_id' => $otherEvent->id,
        ]);
        AIMediaAssessment::factory()->contradicts()->create([
            'evaluation_id' => $otherEvaluation->id,
            'event_media_context_id' => EventMediaContext::factory()->create([
                'team_id' => $otherUser->currentTeam->id,
                'normalized_event_id' => $otherEvent->id,
            ])->id,
        ]);

        $reevaluation = app(ReevaluateEventWithNewEvidence::class)->execute(
            $event,
            ReevaluationTrigger::MediaArrived,
        );

        $this->assertEqualsWithDelta(0.85, (float) $reevaluation->confidence_score, 0.001);
        $this->assertSame(EvaluationMode::AiText, $reevaluation->evaluation_mode);
    }

    /**
     * @return list<array<string, mixed>>
     */
    private static function verdicts(string ...$results): array
    {
        return array_map(fn (string $result): array => ['result' => $result, 'extracted_signals' => []], $results);
    }

    public function test_contradicting_media_raises_confidence_when_ai_said_false_positive(): void
    {
        $fusion = app(MediaVerdictFusion::class)->fuse(
            self::verdicts('contradicts_event', 'contradicts_event'),
            EventClassification::FalsePositive,
        );

        $this->assertEqualsWithDelta(0.15, $fusion['confidenceDelta'], 0.001);
        $this->assertEqualsWithDelta(-0.15, $fusion['riskDelta'], 0.001);
    }

    public function test_confirming_media_lowers_confidence_when_ai_said_false_positive(): void
    {
        $fusion = app(MediaVerdictFusion::class)->fuse(
            self::verdicts('confirms_event'),
            EventClassification::Noise,
        );

        $this->assertEqualsWithDelta(-0.10, $fusion['confidenceDelta'], 0.001);
        $this->assertEqualsWithDelta(0.10, $fusion['riskDelta'], 0.001);
        $this->assertStringContainsString('confirma', $fusion['sentence']);
    }

    public function test_a_single_confirmation_dominates_a_majority_of_contradictions(): void
    {
        $fusion = app(MediaVerdictFusion::class)->fuse(
            self::verdicts('contradicts_event', 'contradicts_event', 'contradicts_event', 'confirms_event'),
            EventClassification::RealEvent,
        );

        $this->assertEqualsWithDelta(0.10, $fusion['confidenceDelta'], 0.001);
        $this->assertEqualsWithDelta(0.10, $fusion['riskDelta'], 0.001);
        $this->assertSame(1, $fusion['keyFactors']['media_confirms_count']);
        $this->assertSame(3, $fusion['keyFactors']['media_contradicts_count']);
    }

    public function test_visible_threat_counts_as_confirmation_even_if_the_result_contradicts(): void
    {
        $fusion = app(MediaVerdictFusion::class)->fuse(
            [
                ['result' => 'contradicts_event', 'extracted_signals' => ['visible_threat' => true]],
                ['result' => 'contradicts_event', 'extracted_signals' => []],
            ],
            EventClassification::RealEvent,
        );

        $this->assertEqualsWithDelta(0.10, $fusion['riskDelta'], 0.001);
        $this->assertSame(1, $fusion['keyFactors']['media_visible_threat_count']);
        $this->assertStringContainsString('amenaza visible', $fusion['sentence']);
    }

    public function test_contradicting_media_never_lowers_risk_of_a_critical_event(): void
    {
        $fusion = app(MediaVerdictFusion::class)->fuse(
            self::verdicts('contradicts_event', 'contradicts_event'),
            EventClassification::RealEvent,
            isCriticalEvent: true,
        );

        $this->assertSame(0.0, $fusion['riskDelta']);
        $this->assertSame(0.0, $fusion['confidenceDelta']);
        $this->assertStringContainsString('sin confirmación visual', $fusion['sentence']);
    }

    public function test_critical_detection_by_severity_or_emergency_category(): void
    {
        $team = User::factory()->create()->currentTeam;
        $medium = EventSeverity::factory()->medium()->create();

        $critical = NormalizedEvent::factory()->create([
            'team_id' => $team->id,
            'event_severity_id' => EventSeverity::factory()->critical()->create()->id,
        ]);
        $emergency = NormalizedEvent::factory()->create([
            'team_id' => $team->id,
            'event_severity_id' => $medium->id,
            'event_category_id' => EventCategory::factory()->emergency()->create()->id,
        ]);
        $ordinary = NormalizedEvent::factory()->create([
            'team_id' => $team->id,
            'event_severity_id' => $medium->id,
        ]);

        $this->assertTrue(MediaVerdictFusion::isCriticalEvent($critical));
        $this->assertTrue(MediaVerdictFusion::isCriticalEvent($emergency));
        $this->assertFalse(MediaVerdictFusion::isCriticalEvent($ordinary));
    }

    public function test_reevaluation_of_a_critical_event_with_contradicting_media_keeps_its_risk(): void
    {
        // Solo interesa la evaluación, no el motor de decisiones aguas abajo.
        Bus::fake([RunDecisionEngineJob::class]);

        $team = User::factory()->create()->currentTeam;

        $event = NormalizedEvent::factory()->create([
            'team_id' => $team->id,
            'event_severity_id' => EventSeverity::factory()->critical()->create()->id,
            'payload_normalized_json' => ['severity' => 'critical'],
        ]);

        app(BuildEventContext::class)->execute($event);
        $baseline = app(EvaluateEventWithAI::class)->execute($event->fresh());

        AIMediaAssessment::factory()->contradicts()->create([
            'evaluation_id' => $baseline->id,
            'event_media_context_id' => $this->makeMedia($event)->id,
        ]);

        $reevaluation = app(ReevaluateEventWithNewEvidence::class)->execute(
            $event->fresh(),
            ReevaluationTrigger::MediaArrived,
        );

        $this->assertEqualsWithDelta((float) $baseline->risk_score, (float) $reevaluation->risk_score, 0.001);
        $this->assertStringContainsString('sin confirmación visual', $reevaluation->explanation_text);

        $ctx = $this->assertSystemLogged('ai.media_fusion.applied', fn (array $c): bool => $c['input']['evaluation_id'] === $reevaluation->id);
        $this->assertSame('critical_no_reduce', $ctx['calc']['branch']);
        $this->assertSame(0.0, $ctx['calc']['risk_delta']);
        $this->assertSame(0.0, $ctx['calc']['confidence_delta']);
        $this->assertTrue($ctx['input']['is_critical_event']);
        $this->assertSame($ctx['calc']['risk_before'], $ctx['calc']['risk_after']);
        $this->assertNoSensitiveDataLogged();
    }

    public function test_explain_reports_the_branch_for_each_case(): void
    {
        $fusion = app(MediaVerdictFusion::class);

        $empty = $fusion->explain([], EventClassification::RealEvent);
        $this->assertSame('no_media', $empty['branch']);
        $this->assertNull($empty['fusion']);
        $this->assertSame(0, $empty['assessed']);
        $this->assertFalse($empty['dismissive']);

        $inconclusive = $fusion->explain(self::verdicts('inconclusive', 'inconclusive'), EventClassification::FalsePositive);
        $this->assertSame('no_verdict', $inconclusive['branch']);
        $this->assertNull($inconclusive['fusion']);
        $this->assertSame(2, $inconclusive['assessed']);
        $this->assertFalse($inconclusive['dismissive']);

        $confirms = $fusion->explain(self::verdicts('confirms_event', 'contradicts_event'), EventClassification::Noise);
        $this->assertSame('confirms', $confirms['branch']);
        $this->assertSame(1, $confirms['confirms']);
        $this->assertSame(1, $confirms['contradicts']);
        $this->assertTrue($confirms['dismissive']);
        $this->assertSame($fusion->fuse(self::verdicts('confirms_event', 'contradicts_event'), EventClassification::Noise), $confirms['fusion']);

        $critical = $fusion->explain(self::verdicts('contradicts_event'), EventClassification::RealEvent, isCriticalEvent: true);
        $this->assertSame('critical_no_reduce', $critical['branch']);
        $this->assertSame(0.0, $critical['fusion']['riskDelta']);

        $contradicts = $fusion->explain(
            [['result' => 'contradicts_event', 'extracted_signals' => []], ['result' => 'contradicts_event', 'extracted_signals' => []]],
            EventClassification::RealEvent,
        );
        $this->assertSame('contradicts', $contradicts['branch']);
        $this->assertSame(2, $contradicts['contradicts']);
        $this->assertSame(0, $contradicts['visible_threats']);
        $this->assertFalse($contradicts['dismissive']);
    }
}
