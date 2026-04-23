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
            'name' => 'null-agent',
            'version' => '1.0',
            'model_type' => AIModelType::HeuristicPipeline,
            'provider' => 'internal',
            'modality_support_json' => ['text' => true, 'image' => false, 'video' => false, 'audio' => false],
            'config_json' => ['deterministic' => true],
            'deployed_at' => now(),
            'is_active' => true,
        ];
    }

    public function inactive(): static
    {
        return $this->state(fn () => ['is_active' => false]);
    }
}
