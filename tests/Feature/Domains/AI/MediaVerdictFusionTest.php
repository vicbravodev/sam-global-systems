<?php

namespace Tests\Feature\Domains\AI;

use App\Domains\AI\Actions\BuildAIInputContext;
use App\Domains\AI\Actions\EvaluateEventWithAI;
use App\Domains\AI\Actions\ReevaluateEventWithNewEvidence;
use App\Domains\AI\Actions\ResolveTenantAIProfile;
use App\Domains\AI\Enums\EvaluationMode;
use App\Domains\AI\Enums\ReevaluationTrigger;
use App\Domains\AI\Models\AIEventEvaluation;
use App\Domains\AI\Models\AIMediaAssessment;
use App\Domains\Context\Actions\BuildEventContext;
use App\Domains\Context\Models\EventMediaContext;
use App\Domains\Normalization\Models\NormalizedEvent;
use App\Models\Team;
use App\Models\User;
use Database\Seeders\AIMeterSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * El veredicto visual (ai_media_assessments) debe entrar a la evaluación:
 * el contexto del agente lo lleva, y la reevaluación lo fusiona en
 * confianza/riesgo/explicación/razonamiento de forma determinista.
 */
class MediaVerdictFusionTest extends TestCase
{
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
}
