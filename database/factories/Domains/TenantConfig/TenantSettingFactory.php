<?php

namespace Database\Factories\Domains\TenantConfig;

use App\Domains\TenantConfig\Enums\SettingGroup;
use App\Domains\TenantConfig\Enums\SettingUpdatedByType;
use App\Domains\TenantConfig\Enums\SettingValueType;
use App\Domains\TenantConfig\Models\TenantSetting;
use App\Models\Team;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<TenantSetting>
 */
class TenantSettingFactory extends Factory
{
    protected $model = TenantSetting::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'team_id' => Team::factory(),
            'setting_key' => 'operational.max_concurrent_incidents',
            'setting_group' => SettingGroup::Operational,
            'value_json' => ['value' => 50],
            'value_type' => SettingValueType::Number,
            'version' => 1,
            'is_active' => true,
            'updated_by_type' => SettingUpdatedByType::System,
            'updated_by_id' => null,
        ];
    }
}
