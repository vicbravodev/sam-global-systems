<?php

namespace Database\Factories\Domains\Audit;

use App\Domains\Audit\Models\DomainEventLog;
use App\Models\Team;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<DomainEventLog>
 */
class DomainEventLogFactory extends Factory
{
    protected $model = DomainEventLog::class;

    public function definition(): array
    {
        return [
            'team_id' => Team::factory(),
            'event_name' => 'App\\Domains\\Normalization\\Events\\EventNormalized',
            'aggregate_type' => 'App\\Domains\\Normalization\\Models\\NormalizedEvent',
            'aggregate_id' => $this->faker->numberBetween(1, 1_000),
            'payload_json' => [],
            'occurred_at' => now(),
            'correlation_id' => Str::uuid()->toString(),
            'causation_id' => null,
        ];
    }
}
