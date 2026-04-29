<?php

namespace Database\Factories\Domains\Audit;

use App\Domains\Audit\Enums\ChangeActorType;
use App\Domains\Audit\Enums\ChangeType;
use App\Domains\Audit\Models\ChangeHistory;
use App\Models\Team;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<ChangeHistory>
 */
class ChangeHistoryFactory extends Factory
{
    protected $model = ChangeHistory::class;

    public function definition(): array
    {
        return [
            'team_id' => Team::factory(),
            'entity_type' => 'App\\Models\\Team',
            'entity_id' => $this->faker->numberBetween(1, 1_000),
            'changed_by_type' => ChangeActorType::System,
            'changed_by_id' => null,
            'change_type' => ChangeType::Updated,
            'before_json' => ['status' => 'old'],
            'after_json' => ['status' => 'new'],
            'changed_fields_json' => ['status'],
            'reason' => null,
            'occurred_at' => now(),
        ];
    }
}
