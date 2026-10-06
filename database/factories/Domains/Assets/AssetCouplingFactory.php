<?php

namespace Database\Factories\Domains\Assets;

use App\Domains\Assets\Enums\CouplingSource;
use App\Domains\Assets\Models\Asset;
use App\Domains\Assets\Models\AssetCoupling;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * Tracto y remolque nacen en el MISMO tenant: el remolque cuelga del team del
 * tracto.
 *
 * @extends Factory<AssetCoupling>
 */
class AssetCouplingFactory extends Factory
{
    protected $model = AssetCoupling::class;

    public function definition(): array
    {
        return [
            'tractor_asset_id' => Asset::factory(),
            'team_id' => fn (array $attributes) => Asset::query()->withoutGlobalScopes()->whereKey($attributes['tractor_asset_id'])->value('team_id'),
            'trailer_asset_id' => fn (array $attributes) => Asset::factory()->trailer()->create(['team_id' => $attributes['team_id']])->id,
            'source' => CouplingSource::CoMovement,
            'coupled_at' => now()->subHour(),
            'last_confirmed_at' => now()->subMinutes(5),
            'decoupled_at' => null,
            'decouple_reason' => null,
            'evidence_json' => null,
        ];
    }

    /**
     * @param  Asset  $tractor  el remolque se crea en su tenant
     */
    public function forTractor(Asset $tractor): static
    {
        return $this->state(fn () => [
            'tractor_asset_id' => $tractor->id,
            'team_id' => $tractor->team_id,
        ]);
    }
}
