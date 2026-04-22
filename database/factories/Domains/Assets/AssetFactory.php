<?php

namespace Database\Factories\Domains\Assets;

use App\Domains\Assets\Enums\AssetStatus;
use App\Domains\Assets\Models\Asset;
use App\Domains\Assets\Models\AssetType;
use App\Models\Team;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Asset>
 */
class AssetFactory extends Factory
{
    protected $model = Asset::class;

    public function definition(): array
    {
        return [
            'team_id' => Team::factory(),
            'asset_type_id' => AssetType::factory(),
            'name' => fake()->words(3, true),
            'status' => AssetStatus::Active,
            'first_seen_at' => now(),
            'last_seen_at' => now(),
        ];
    }

    public function active(): static
    {
        return $this->state(fn () => [
            'status' => AssetStatus::Active,
        ]);
    }

    public function inactive(): static
    {
        return $this->state(fn () => [
            'status' => AssetStatus::Inactive,
        ]);
    }

    public function offline(): static
    {
        return $this->state(fn () => [
            'status' => AssetStatus::Offline,
        ]);
    }

    public function alert(): static
    {
        return $this->state(fn () => [
            'status' => AssetStatus::Alert,
        ]);
    }

    public function critical(): static
    {
        return $this->state(fn () => [
            'status' => AssetStatus::Critical,
        ]);
    }

    public function maintenance(): static
    {
        return $this->state(fn () => [
            'status' => AssetStatus::Maintenance,
        ]);
    }
}
