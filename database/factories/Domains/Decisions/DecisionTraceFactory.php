<?php

namespace Database\Factories\Domains\Decisions;

use App\Domains\Decisions\Enums\DecisionSourceType;
use App\Domains\Decisions\Models\Decision;
use App\Domains\Decisions\Models\DecisionTrace;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<DecisionTrace>
 */
class DecisionTraceFactory extends Factory
{
    protected $model = DecisionTrace::class;

    public function definition(): array
    {
        return [
            'decision_id' => Decision::factory(),
            'rule_code' => null,
            'source_type' => DecisionSourceType::Ai,
            'source_reference_id' => null,
            'step_order' => 1,
            'input_fragment_json' => [],
            'output_fragment_json' => [],
            'explanation' => 'Default trace step.',
        ];
    }
}
