<?php

namespace Database\Factories\Domains\TenantConfig;

use App\Domains\TenantConfig\Enums\SettingUpdatedByType;
use App\Domains\TenantConfig\Models\TenantConfigVersion;
use App\Models\Team;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<TenantConfigVersion>
 */
class TenantConfigVersionFactory extends Factory
{
    protected $model = TenantConfigVersion::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'team_id' => Team::factory(),
            'version' => 1,
            'snapshot_json' => [
                'version' => 1,
                'captured_at' => now()->toIso8601String(),
                'settings' => [],
                'rule_overrides' => [],
                'ai_profile' => null,
                'notification_policies' => [],
                'escalation_configs' => [],
                'schedule_profiles' => [],
            ],
            'created_by_type' => SettingUpdatedByType::System,
            'created_by_id' => null,
        ];
    }
}
