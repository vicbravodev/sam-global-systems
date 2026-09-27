<?php

namespace Tests\Feature\Domains\Decisions;

use App\Domains\AI\Models\AIEventEvaluation;
use App\Domains\AI\Models\AIMediaAssessment;
use App\Domains\Decisions\Support\DecisionFactsBuilder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * El hecho `media_assessment` es el peor caso de todas las medias del evento
 * (todas las versiones de evaluación), no solo el último veredicto: una
 * cámara que no ve nada no borra a otra que sí vio el evento.
 */
class DecisionFactsBuilderMediaAssessmentTest extends TestCase
{
    use RefreshDatabase;

    private function mediaAssessmentFact(AIEventEvaluation $eval): ?string
    {
        return (new DecisionFactsBuilder)->build($eval->fresh(), null)['media_assessment'];
    }

    public function test_null_without_assessments(): void
    {
        $this->assertNull($this->mediaAssessmentFact(AIEventEvaluation::factory()->create()));
    }

    public function test_an_older_confirmation_dominates_a_later_contradiction(): void
    {
        $eval = AIEventEvaluation::factory()->create();

        AIMediaAssessment::factory()->create([
            'evaluation_id' => $eval->id,
            'assessed_at' => now()->subMinutes(5),
        ]);
        AIMediaAssessment::factory()->contradicts()->create([
            'evaluation_id' => $eval->id,
            'assessed_at' => now(),
        ]);

        $this->assertSame('confirms_event', $this->mediaAssessmentFact($eval));
    }

    public function test_confirmation_from_a_previous_evaluation_version_still_counts(): void
    {
        $previous = AIEventEvaluation::factory()->create();

        AIMediaAssessment::factory()->create([
            'evaluation_id' => $previous->id,
            'assessed_at' => now()->subMinutes(5),
        ]);

        $reevaluation = AIEventEvaluation::factory()->create([
            'normalized_event_id' => $previous->normalized_event_id,
            'team_id' => $previous->team_id,
            'evaluation_version' => 2,
        ]);

        AIMediaAssessment::factory()->contradicts()->create([
            'evaluation_id' => $reevaluation->id,
            'assessed_at' => now(),
        ]);

        $this->assertSame('confirms_event', $this->mediaAssessmentFact($reevaluation));
    }

    public function test_visible_threat_counts_as_confirmation(): void
    {
        $eval = AIEventEvaluation::factory()->create();

        AIMediaAssessment::factory()->inconclusive()->create([
            'evaluation_id' => $eval->id,
            'extracted_signals_json' => ['visible_threat' => true],
            'assessed_at' => now()->subMinutes(5),
        ]);
        AIMediaAssessment::factory()->contradicts()->create([
            'evaluation_id' => $eval->id,
            'assessed_at' => now(),
        ]);

        $this->assertSame('confirms_event', $this->mediaAssessmentFact($eval));
    }

    public function test_contradiction_wins_over_inconclusive(): void
    {
        $eval = AIEventEvaluation::factory()->create();

        AIMediaAssessment::factory()->contradicts()->create([
            'evaluation_id' => $eval->id,
            'assessed_at' => now()->subMinutes(5),
        ]);
        AIMediaAssessment::factory()->inconclusive()->create([
            'evaluation_id' => $eval->id,
            'assessed_at' => now(),
        ]);

        $this->assertSame('contradicts_event', $this->mediaAssessmentFact($eval));
    }

    public function test_only_inconclusive_returns_the_latest(): void
    {
        $eval = AIEventEvaluation::factory()->create();

        AIMediaAssessment::factory()->inconclusive()->create(['evaluation_id' => $eval->id]);

        $this->assertSame('inconclusive', $this->mediaAssessmentFact($eval));
    }
}
