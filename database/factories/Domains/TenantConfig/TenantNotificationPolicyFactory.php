<?php

namespace Database\Factories\Domains\TenantConfig;

use App\Domains\TenantConfig\Models\TenantNotificationPolicy;
use App\Models\Team;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<TenantNotificationPolicy>
 */
class TenantNotificationPolicyFactory extends Factory
{
    protected $model = TenantNotificationPolicy::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'team_id' => Team::factory(),
            'policy_code' => 'default',
            'notification_type' => 'incident_created',
            'priority' => 'normal',
            'allowed_channels_json' => ['email', 'sms', 'push'],
            'fallback_channels_json' => ['email'],
            'recipient_rules_json' => null,
            'quiet_hours_json' => null,
            'escalation_rules_json' => null,
            'is_active' => true,
        ];
    }
}
