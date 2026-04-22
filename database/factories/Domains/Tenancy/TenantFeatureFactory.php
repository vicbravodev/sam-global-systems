<?php

namespace Database\Factories\Domains\Tenancy;

use App\Domains\Tenancy\Enums\FeatureSource;
use App\Domains\Tenancy\Models\TenantFeature;
use App\Models\Team;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<TenantFeature>
 */
class TenantFeatureFactory extends Factory
{
    protected $model = TenantFeature::class;

    public function definition(): array
    {
        return [
            'team_id' => Team::factory(),
            'feature_key' => fake()->unique()->slug(2),
            'enabled' => true,
            'source' => FeatureSource::DefaultPlan,
            'limits_json' => null,
        ];
    }

    public function disabled(): static
    {
        return $this->state(fn () => [
            'enabled' => false,
        ]);
    }

    public function withLimits(array $limits): static
    {
        return $this->state(fn () => [
            'limits_json' => $limits,
        ]);
    }
}
