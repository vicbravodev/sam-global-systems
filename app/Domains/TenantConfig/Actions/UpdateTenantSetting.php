<?php

namespace App\Domains\TenantConfig\Actions;

use App\Domains\TenantConfig\Enums\SettingGroup;
use App\Domains\TenantConfig\Enums\SettingUpdatedByType;
use App\Domains\TenantConfig\Enums\SettingValueType;
use App\Domains\TenantConfig\Events\TenantSettingUpdated;
use App\Domains\TenantConfig\Models\TenantSetting;
use App\Domains\TenantConfig\Support\CacheKeys;
use Illuminate\Support\Facades\Cache;
use InvalidArgumentException;

class UpdateTenantSetting
{
    public function __construct(
        private readonly SnapshotTenantConfig $snapshotTenantConfig,
    ) {}

    /**
     * @param  array<string, mixed>|scalar|null  $value
     */
    public function execute(
        int $teamId,
        string $settingKey,
        SettingGroup $settingGroup,
        SettingValueType $valueType,
        mixed $value,
        SettingUpdatedByType $updatedByType = SettingUpdatedByType::System,
        ?int $updatedById = null,
    ): TenantSetting {
        if (! $valueType->accepts($value)) {
            throw new InvalidArgumentException(
                "Value for setting '{$settingKey}' is not compatible with declared type {$valueType->value}.",
            );
        }

        $existing = TenantSetting::withoutGlobalScopes()
            ->where('team_id', $teamId)
            ->where('setting_key', $settingKey)
            ->first();

        $previousTypedValue = $existing?->typed_value;
        $storedJson = $this->wrap($value, $valueType);

        if ($existing) {
            $existing->fill([
                'setting_group' => $settingGroup,
                'value_json' => $storedJson,
                'value_type' => $valueType,
                'version' => $existing->version + 1,
                'is_active' => true,
                'updated_by_type' => $updatedByType,
                'updated_by_id' => $updatedById,
            ])->save();
            $setting = $existing;
        } else {
            $setting = TenantSetting::withoutGlobalScopes()->create([
                'team_id' => $teamId,
                'setting_key' => $settingKey,
                'setting_group' => $settingGroup,
                'value_json' => $storedJson,
                'value_type' => $valueType,
                'version' => 1,
                'is_active' => true,
                'updated_by_type' => $updatedByType,
                'updated_by_id' => $updatedById,
            ]);
        }

        Cache::forget(CacheKeys::setting($teamId, $settingKey));

        TenantSettingUpdated::dispatch(
            $teamId,
            $settingKey,
            $settingGroup,
            $previousTypedValue,
            $value,
            $updatedByType,
            $updatedById,
        );

        if ($this->shouldSnapshot($settingGroup)) {
            $this->snapshotTenantConfig->execute($teamId, $updatedByType, $updatedById);
        }

        return $setting->refresh();
    }

    /**
     * @return array<string, mixed>
     */
    private function wrap(mixed $value, SettingValueType $type): array
    {
        if ($type === SettingValueType::Json && is_array($value) && ! array_is_list($value)) {
            return $value;
        }

        return ['value' => $value];
    }

    private function shouldSnapshot(SettingGroup $group): bool
    {
        return in_array($group, [
            SettingGroup::Ai,
            SettingGroup::Escalation,
            SettingGroup::Compliance,
        ], true);
    }
}
