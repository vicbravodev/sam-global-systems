<?php

namespace Database\Factories\Domains\Assets;

use App\Domains\Assets\Models\Asset;
use App\Domains\Assets\Models\AssetExternalReference;
use App\Domains\Integrations\Models\IntegrationProvider;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<AssetExternalReference>
 */
class AssetExternalReferenceFactory extends Factory
{
    protected $model = AssetExternalReference::class;

    public function definition(): array
    {
        return [
            'asset_id' => Asset::factory(),
            'provider_id' => IntegrationProvider::factory(),
            'external_id' => fake()->unique()->uuid(),
            'external_type' => 'vehicle',
            'first_seen_at' => now(),
            'last_seen_at' => now(),
        ];
    }
}
