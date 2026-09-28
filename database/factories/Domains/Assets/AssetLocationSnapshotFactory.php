<?php

namespace Database\Factories\Domains\Assets;

use App\Domains\Assets\Enums\LocationSource;
use App\Domains\Assets\Models\Asset;
use App\Domains\Assets\Models\AssetLocationSnapshot;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<AssetLocationSnapshot>
 */
class AssetLocationSnapshotFactory extends Factory
{
    /**
     * Each row lands one second further back: points are unique per asset and
     * instant (the feed's idempotency index), so several rows for the same
     * asset cannot share `now()`. Stays within the last hour, i.e. "fresh".
     */
    private static int $sequence = 0;

    protected $model = AssetLocationSnapshot::class;

    public function definition(): array
    {
        return [
            'asset_id' => Asset::factory(),
            'latitude' => fake()->latitude(),
            'longitude' => fake()->longitude(),
            'speed' => fake()->randomFloat(2, 0, 120),
            'heading' => fake()->numberBetween(0, 359),
            'recorded_at' => now()->subSeconds(self::$sequence++ % 3600),
            'source' => LocationSource::Provider,
        ];
    }

    public function fromGps(): static
    {
        return $this->state(fn () => [
            'source' => LocationSource::Gps,
        ]);
    }

    public function manual(): static
    {
        return $this->state(fn () => [
            'source' => LocationSource::Manual,
        ]);
    }
}
