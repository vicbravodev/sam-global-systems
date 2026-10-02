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
use Tests\Concerns\AssertsSystemLog;
use Tests\TestCase;

class UpdateTenantSettingTest extends TestCase
{
    use AssertsSystemLog;
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

        $lines = $this->systemLogEntries('tenant_config.setting.updated');
        $this->assertCount(2, $lines);
        $this->assertTrue($lines[0]['context']['result']['created']);
        $this->assertSame(['team_id' => $team->id, 'setting_key' => 'operational.max', 'setting_group' => 'operational', 'value_type' => 'number', 'updated_by_type' => 'system', 'updated_by_id' => null], $lines[1]['context']['input']);
        $this->assertSame(5, $lines[1]['context']['result']['previous_value']);
        $this->assertSame(10, $lines[1]['context']['result']['value']);
        $this->assertSame(2, $lines[1]['context']['result']['version']);
        $this->assertNoSensitiveDataLogged();
    }

    public function test_text_values_are_never_logged(): void
    {
        $team = User::factory()->create()->currentTeam;

        app(UpdateTenantSetting::class)->execute(
            teamId: $team->id,
            settingKey: 'notifications.contact_note',
            settingGroup: SettingGroup::Operational,
            valueType: SettingValueType::String,
            value: 'Llamar a Ana al 5512345678',
        );

        $ctx = $this->assertSystemLogged('tenant_config.setting.updated');
        $this->assertSame('[not_logged]', $ctx['result']['value']);
        $this->assertStringNotContainsString('Ana', (string) json_encode($this->systemLogEntries()));
        $this->assertNoSensitiveDataLogged();
    }

    public function test_invalid_value_type_is_rejected(): void
    {
        $team = User::factory()->create()->currentTeam;
        $action = app(UpdateTenantSetting::class);

        try {
            $action->execute(
                teamId: $team->id,
                settingKey: 'feature.threshold',
                settingGroup: SettingGroup::Operational,
                valueType: SettingValueType::Number,
                value: 'not_a_number',
            );
            $this->fail('Debió rechazarse.');
        } catch (InvalidArgumentException) {
        }

        $this->assertSystemLogged('tenant_config.setting.updated', fn (array $c) => $c['reason'] === 'type_mismatch'
            && $c['input']['setting_key'] === 'feature.threshold'
            && $c['input']['value_type'] === 'number');
        $this->assertStringNotContainsString('not_a_number', (string) json_encode($this->systemLogEntries()));
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
