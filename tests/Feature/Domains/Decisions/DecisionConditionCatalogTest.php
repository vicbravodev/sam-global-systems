<?php

namespace Tests\Feature\Domains\Decisions;

use App\Domains\AI\Models\AIEventEvaluation;
use App\Domains\Context\Models\EventContextSnapshot;
use App\Domains\Decisions\Support\DecisionConditionCatalog;
use App\Domains\Decisions\Support\DecisionFactsBuilder;
use App\Domains\Decisions\Support\RuleConditionEvaluator;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Parity guard: every field the visual builder offers must exist in the fact
 * map the live decision pipeline evaluates, with the operators it supports.
 */
class DecisionConditionCatalogTest extends TestCase
{
    use RefreshDatabase;

    public function test_every_catalog_field_exists_in_the_facts_builder_output(): void
    {
        $eval = AIEventEvaluation::factory()->create();

        $facts = (new DecisionFactsBuilder)->build($eval->fresh(), null);

        foreach (DecisionConditionCatalog::fields() as $field) {
            $this->assertArrayHasKey(
                $field['key'],
                $facts,
                "El campo «{$field['key']}» del catálogo no existe en DecisionFactsBuilder::build().",
            );
        }
    }

    public function test_every_catalog_operator_is_supported_by_the_evaluator(): void
    {
        foreach (DecisionConditionCatalog::fields() as $field) {
            $this->assertNotEmpty($field['operators']);

            foreach ($field['operators'] as $operator) {
                $this->assertContains(
                    $operator,
                    RuleConditionEvaluator::OPERATORS,
                    "El operador «{$operator}» de «{$field['key']}» no está soportado por el evaluador.",
                );
            }
        }
    }

    public function test_catalog_labels_are_spanish_strings(): void
    {
        foreach (DecisionConditionCatalog::fields() as $field) {
            $this->assertIsString($field['label']);
            $this->assertNotSame('', $field['label']);
            $this->assertContains($field['type'], ['string', 'number', 'boolean', 'enum']);
        }
    }

    public function test_facts_builder_matches_the_live_pipeline_facts(): void
    {
        $eval = AIEventEvaluation::factory()->create([
            'risk_score' => 0.9,
            'requires_action' => true,
        ]);

        $facts = (new DecisionFactsBuilder)->build($eval->fresh(), null);

        $this->assertSame('real_event', $facts['classification']);
        $this->assertSame(0.9, $facts['risk_score']);
        $this->assertTrue($facts['requires_action']);
        $this->assertFalse($facts['has_context_snapshot']);
        $this->assertNull($facts['media_assessment']);
        $this->assertSame(0, $facts['repeated_panic_count_24h']);
        $this->assertFalse($facts['harsh_driving_near_event']);
        $this->assertSame(0, $facts['nearby_safety_events_count']);
    }

    public function test_safety_correlation_facts_flow_from_the_context_snapshot(): void
    {
        $eval = AIEventEvaluation::factory()->create();

        $snapshot = EventContextSnapshot::factory()->create([
            'team_id' => $eval->team_id,
            'normalized_event_id' => $eval->normalized_event_id,
            'signals_json' => ['harsh_driving_near_event' => true],
            'recent_history_snapshot_json' => ['nearby_safety_events_count' => 2],
        ]);

        $facts = (new DecisionFactsBuilder)->build($eval->fresh(), $snapshot);

        $this->assertTrue($facts['harsh_driving_near_event']);
        $this->assertSame(2, $facts['nearby_safety_events_count']);
    }
}
