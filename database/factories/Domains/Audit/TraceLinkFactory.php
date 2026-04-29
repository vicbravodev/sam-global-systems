<?php

namespace Database\Factories\Domains\Audit;

use App\Domains\Audit\Enums\TraceRelationType;
use App\Domains\Audit\Models\TraceLink;
use App\Models\Team;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<TraceLink>
 */
class TraceLinkFactory extends Factory
{
    protected $model = TraceLink::class;

    public function definition(): array
    {
        return [
            'team_id' => Team::factory(),
            'trace_id' => Str::uuid()->toString(),
            'source_type' => 'App\\Domains\\Ingestion\\Models\\RawEvent',
            'source_id' => $this->faker->numberBetween(1, 1_000),
            'target_type' => 'App\\Domains\\Normalization\\Models\\NormalizedEvent',
            'target_id' => $this->faker->numberBetween(1, 1_000),
            'relation_type' => TraceRelationType::CausedBy,
        ];
    }
}
