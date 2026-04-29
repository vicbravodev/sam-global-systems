<?php

namespace Database\Factories\Domains\TenantConfig;

use App\Domains\TenantConfig\Models\TenantEscalationConfig;
use App\Models\Team;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<TenantEscalationConfig>
 */
class TenantEscalationConfigFactory extends Factory
{
    protected $model = TenantEscalationConfig::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'team_id' => Team::factory(),
            'escalation_type' => 'incident_critical',
            'trigger_conditions_json' => ['priority' => 'critical', 'status' => 'open'],
            'steps_json' => [
                ['delay_minutes' => 0, 'channels' => ['push']],
                ['delay_minutes' => 15, 'channels' => ['sms', 'voice']],
            ],
            'time_constraints_json' => null,
            'is_active' => true,
        ];
    }
}
