<?php

namespace Database\Factories\Domains\TenantConfig;

use App\Domains\TenantConfig\Models\TenantScheduleProfile;
use App\Models\Team;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<TenantScheduleProfile>
 */
class TenantScheduleProfileFactory extends Factory
{
    protected $model = TenantScheduleProfile::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'team_id' => Team::factory(),
            'profile_code' => 'business_hours',
            'timezone' => 'America/Mexico_City',
            'operating_hours_json' => [
                'monday' => ['start' => '08:00', 'end' => '18:00'],
                'tuesday' => ['start' => '08:00', 'end' => '18:00'],
                'wednesday' => ['start' => '08:00', 'end' => '18:00'],
                'thursday' => ['start' => '08:00', 'end' => '18:00'],
                'friday' => ['start' => '08:00', 'end' => '18:00'],
                'saturday' => null,
                'sunday' => null,
            ],
            'holidays_json' => [],
            'shift_rules_json' => null,
            'after_hours_behavior_json' => [
                'suppress_low_priority' => true,
                'escalation_policy' => 'on_call_only',
                'notification_channels' => ['sms', 'push'],
            ],
            'is_active' => true,
        ];
    }
}
