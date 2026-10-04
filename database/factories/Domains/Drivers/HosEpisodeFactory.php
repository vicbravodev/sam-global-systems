<?php

namespace Database\Factories\Domains\Drivers;

use App\Domains\Drivers\Enums\HosSituation;
use App\Domains\Drivers\Models\Driver;
use App\Domains\Drivers\Models\HosEpisode;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<HosEpisode>
 */
class HosEpisodeFactory extends Factory
{
    protected $model = HosEpisode::class;

    public function definition(): array
    {
        return [
            'driver_id' => Driver::factory(),
            'team_id' => fn (array $attributes) => Driver::withoutGlobalScopes()->whereKey($attributes['driver_id'])->value('team_id'),
            'situation' => HosSituation::BreakDue,
            'opened_at' => now(),
            'snapshot_json' => [],
        ];
    }
}
