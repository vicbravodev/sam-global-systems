<?php

namespace Database\Factories\Domains\AI;

use App\Domains\AI\Enums\InferenceStatus;
use App\Domains\AI\Models\AIEventEvaluation;
use App\Domains\AI\Models\AIInferenceLog;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<AIInferenceLog>
 */
class AIInferenceLogFactory extends Factory
{
    protected $model = AIInferenceLog::class;

    public function definition(): array
    {
        return [
            'evaluation_id' => AIEventEvaluation::factory(),
            'input_snapshot_json' => [],
            'output_json' => [],
            'latency_ms' => 0,
            'tokens_used' => 0,
            'media_assets_count' => 0,
            'cost_estimate' => 0,
            'status' => InferenceStatus::Success,
        ];
    }
}
