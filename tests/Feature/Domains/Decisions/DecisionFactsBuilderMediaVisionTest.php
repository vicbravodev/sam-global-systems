<?php

namespace Tests\Feature\Domains\Decisions;

use App\Domains\AI\Models\AIEventEvaluation;
use App\Domains\AI\Models\AIMediaAssessment;
use App\Domains\Decisions\Support\DecisionFactsBuilder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Roadmap V2-A1: the structured vision signals the media inspector extracts
 * per asset must surface as aggregated decision facts.
 */
class DecisionFactsBuilderMediaVisionTest extends TestCase
{
    use RefreshDatabase;

    private function buildFacts(AIEventEvaluation $eval): array
    {
        return (new DecisionFactsBuilder)->build($eval->fresh(), null);
    }

    public function test_vision_facts_are_null_without_assessments(): void
    {
        $facts = $this->buildFacts(AIEventEvaluation::factory()->create());

        $this->assertNull($facts['media_passenger_detected']);
        $this->assertNull($facts['media_visible_threat']);
        $this->assertNull($facts['media_persons_visible_count']);
        $this->assertNull($facts['media_cabin_appears_normal']);
    }

    public function test_alarming_evidence_dominates_across_assessments(): void
    {
        $eval = AIEventEvaluation::factory()->create();

        AIMediaAssessment::factory()->create([
            'evaluation_id' => $eval->id,
            'extracted_signals_json' => [
                'passenger_detected' => false,
                'visible_threat' => null,
                'persons_visible_count' => 1,
                'cabin_appears_normal' => true,
            ],
        ]);

        AIMediaAssessment::factory()->create([
            'evaluation_id' => $eval->id,
            'extracted_signals_json' => [
                'passenger_detected' => true,
                'visible_threat' => false,
                'persons_visible_count' => 3,
                'cabin_appears_normal' => false,
            ],
        ]);

        $facts = $this->buildFacts($eval);

        $this->assertTrue($facts['media_passenger_detected']);
        $this->assertFalse($facts['media_visible_threat']);
        $this->assertSame(3, $facts['media_persons_visible_count']);
        $this->assertFalse($facts['media_cabin_appears_normal']);
    }

    public function test_signals_resolve_across_evaluation_versions_of_the_same_event(): void
    {
        $previous = AIEventEvaluation::factory()->create();

        AIMediaAssessment::factory()->create([
            'evaluation_id' => $previous->id,
            'extracted_signals_json' => ['passenger_detected' => true],
        ]);

        $reevaluation = AIEventEvaluation::factory()->create([
            'normalized_event_id' => $previous->normalized_event_id,
            'team_id' => $previous->team_id,
            'evaluation_version' => 2,
        ]);

        $facts = $this->buildFacts($reevaluation);

        $this->assertTrue($facts['media_passenger_detected']);
    }

    public function test_signals_from_another_event_do_not_leak(): void
    {
        $other = AIEventEvaluation::factory()->create();

        AIMediaAssessment::factory()->create([
            'evaluation_id' => $other->id,
            'extracted_signals_json' => ['passenger_detected' => true, 'visible_threat' => true],
        ]);

        $facts = $this->buildFacts(AIEventEvaluation::factory()->create());

        $this->assertNull($facts['media_passenger_detected']);
        $this->assertNull($facts['media_visible_threat']);
    }

    public function test_non_boolean_or_malformed_signals_are_ignored(): void
    {
        $eval = AIEventEvaluation::factory()->create();

        AIMediaAssessment::factory()->create([
            'evaluation_id' => $eval->id,
            'extracted_signals_json' => [
                'passenger_detected' => 'yes',
                'visible_threat' => 1,
                'persons_visible_count' => 'many',
                'cabin_appears_normal' => [],
            ],
        ]);

        $facts = $this->buildFacts($eval);

        $this->assertNull($facts['media_passenger_detected']);
        $this->assertNull($facts['media_visible_threat']);
        $this->assertNull($facts['media_persons_visible_count']);
        $this->assertNull($facts['media_cabin_appears_normal']);
    }
}
