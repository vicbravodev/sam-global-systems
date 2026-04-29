<?php

namespace Tests\Feature\Domains\TenantConfig;

use App\Domains\TenantConfig\Actions\UpdateTenantSetting;
use App\Domains\TenantConfig\Enums\SettingGroup;
use App\Domains\TenantConfig\Enums\SettingUpdatedByType;
use App\Domains\TenantConfig\Enums\SettingValueType;
use App\Domains\TenantConfig\Events\TenantSettingUpdated;
use App\Domains\TenantConfig\Models\TenantConfigVersion;
use App\Domains\TenantConfig\Models\TenantSetting;
use App\Domains\TenantConfig\Support\CacheKeys;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Event;
use InvalidArgumentException;
use Tests\TestCase;

class UpdateTenantSettingTest extends TestCase
{
    use RefreshDatabase;

    public function test_creates_a_new_setting_with_version_one(): void
    {
        Event::fake();
        $team = User::factory()->create()->currentTeam;

        $action = app(UpdateTenantSetting::class);

        $setting = $action->execute(
            teamId: $team->id,
            settingKey: 'operational.max_concurrent_incidents',
            settingGroup: SettingGroup::Operational,
            valueType: SettingValueType::Number,
            value: 100,
        );

        $this->assertSame(1, $setting->version);
        $this->assertSame(100, $setting->typed_value);
        $this->assertSame(SettingGroup::Operational, $setting->setting_group);
        $this->assertTrue($setting->is_active);

        Event::assertDispatched(TenantSettingUpdated::class);
    }

    public function test_updating_existing_setting_increments_version(): void
    {
        $team = User::factory()->create()->currentTeam;
        $action = app(UpdateTenantSetting::class);

        $action->execute(
            teamId: $team->id,
            settingKey: 'operational.max',
            settingGroup: SettingGroup::Operational,
            valueType: SettingValueType::Number,
            value: 5,
        );

        $second = $action->execute(
            teamId: $team->id,
            settingKey: 'operational.max',
            settingGroup: SettingGroup::Operational,
            valueType: SettingValueType::Number,
            value: 10,
        );

        $this->assertSame(2, $second->version);
        $this->assertSame(10, $second->typed_value);
        $this->assertSame(1, TenantSetting::withoutGlobalScopes()->count());
    }

    public function test_invalid_value_type_is_rejected(): void
    {
        $team = User::factory()->create()->currentTeam;
        $action = app(UpdateTenantSetting::class);

        $this->expectException(InvalidArgumentException::class);

        $action->execute(
            teamId: $team->id,
            settingKey: 'feature.threshold',
            settingGroup: SettingGroup::Operational,
            valueType: SettingValueType::Number,
            value: 'not_a_number',
        );
    }

    public function test_critical_groups_trigger_config_snapshot(): void
    {
        $team = User::factory()->create()->currentTeam;
        $action = app(UpdateTenantSetting::class);

        $action->execute(
            teamId: $team->id,
            settingKey: 'ai.confidence_threshold',
            settingGroup: SettingGroup::Ai,
            valueType: SettingValueType::Number,
            value: 0.7,
        );

        $this->assertSame(
            1,
            TenantConfigVersion::withoutGlobalScopes()->where('team_id', $team->id)->count(),
            'Updating an Ai-group setting must create a config version snapshot',
        );

        $action->execute(
            teamId: $team->id,
            settingKey: 'operational.max_concurrent_incidents',
            settingGroup: SettingGroup::Operational,
            valueType: SettingValueType::Number,
            value: 50,
        );

        $this->assertSame(
            1,
            TenantConfigVersion::withoutGlobalScopes()->where('team_id', $team->id)->count(),
            'Operational-group setting must NOT create an extra snapshot',
        );
    }

    public function test_cache_is_invalidated_on_update(): void
    {
        $team = User::factory()->create()->currentTeam;
        Cache::put(CacheKeys::setting($team->id, 'k'), ['hit' => true, 'value' => 'old'], 300);

        app(UpdateTenantSetting::class)->execute(
            teamId: $team->id,
            settingKey: 'k',
            settingGroup: SettingGroup::Operational,
            valueType: SettingValueType::String,
            value: 'new',
            updatedByType: SettingUpdatedByType::User,
            updatedById: 1,
        );

        $this->assertNull(Cache::get(CacheKeys::setting($team->id, 'k')));
    }
}
