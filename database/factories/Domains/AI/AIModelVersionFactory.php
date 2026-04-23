<?php

namespace Database\Factories\Domains\AI;

use App\Domains\AI\Enums\AIModelType;
use App\Domains\AI\Models\AIModelVersion;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<AIModelVersion>
 */
class AIModelVersionFactory extends Factory
{
    protected $model = AIModelVersion::class;

    public function definition(): array
    {
        return [
            'name' => 'rules-only',
            'version' => 'v1',
            'model_type' => AIModelType::HeuristicPipeline,
            'provider' => 'internal',
            'modality_support_json' => ['text' => true],
            'config_json' => [],
            'deployed_at' => now(),
            'is_active' => true,
        ];
    }
}
