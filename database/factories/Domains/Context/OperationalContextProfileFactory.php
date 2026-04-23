<?php

namespace Database\Factories\Domains\Context;

use App\Domains\Context\Enums\RiskLevel;
use App\Domains\Context\Models\OperationalContextProfile;
use App\Domains\Normalization\Models\NormalizedEvent;
use App\Models\Team;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<OperationalContextProfile>
 */
class OperationalContextProfileFactory extends Factory
{
    protected $model = OperationalContextProfile::class;

    public function definition(): array
    {
        return [
            'normalized_event_id' => NormalizedEvent::factory(),
            'team_id' => Team::factory(),
            'profile_code' => 'baseline',
            'risk_level' => RiskLevel::Low,
            'priority_score' => 10.00,
            'recurrence_score' => 0.00,
            'contextual_flags_json' => [],
            'summary_json' => [],
        ];
    }

    public function critical(): static
    {
        return $this->state(fn () => [
            'risk_level' => RiskLevel::Critical,
            'priority_score' => 95.00,
            'recurrence_score' => 50.00,
        ]);
    }
}
