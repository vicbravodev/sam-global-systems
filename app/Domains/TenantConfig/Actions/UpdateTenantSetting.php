<?php

namespace App\Domains\TenantConfig\Actions;

use App\Domains\TenantConfig\Enums\SettingGroup;
use App\Domains\TenantConfig\Enums\SettingUpdatedByType;
use App\Domains\TenantConfig\Enums\SettingValueType;
use App\Domains\TenantConfig\Events\TenantSettingUpdated;
use App\Domains\TenantConfig\Models\TenantSetting;
use App\Domains\TenantConfig\Support\CacheKeys;
use App\Support\LoggableCode;
use App\Support\SystemLog;
use App\Support\TenantContext;
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
        return TenantContext::for($teamId, function () use ($teamId, $settingKey, $settingGroup, $valueType, $value, $updatedByType, $updatedById) {
            if (! $valueType->accepts($value)) {
                SystemLog::skipped('tenant_config.setting.updated', reason: 'type_mismatch', input: [
                    'team_id' => $teamId,
                    'setting_key' => LoggableCode::guard($settingKey),
                    'value_type' => $valueType->value,
                ]);

                throw new InvalidArgumentException(
                    "Value for setting '{$settingKey}' is not compatible with declared type {$valueType->value}.",
                );
            }

            $existing = TenantSetting::query()
                ->where('team_id', $teamId)
                ->where('setting_key', $settingKey)
                ->first();

            $previousTypedValue = $existing?->typed_value;
            $storedJson = $this->wrap($value, $valueType);

            if ($existing !== null) {
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
                $setting = TenantSetting::query()->create([
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

            $snapshotted = $this->shouldSnapshot($settingGroup);

            if ($snapshotted) {
                $this->snapshotTenantConfig->execute($teamId, $updatedByType, $updatedById);
            }

            // El valor sólo si es número o booleano: un texto o un JSON puede
            // llevar contactos o texto libre del tenant.
            SystemLog::ok('tenant_config.setting.updated', input: [
                'team_id' => $teamId,
                'setting_key' => LoggableCode::guard($settingKey),
                'setting_group' => $settingGroup->value,
                'value_type' => $valueType->value,
                'updated_by_type' => $updatedByType->value,
                'updated_by_id' => $updatedById,
            ], result: [
                'setting_id' => $setting->id,
                'version' => $setting->version,
                'created' => $existing === null,
                'previous_value' => self::loggableValue($previousTypedValue),
                'value' => self::loggableValue($value),
                'snapshotted' => $snapshotted,
            ]);

            return $setting->refresh();
        });
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

    private static function loggableValue(mixed $value): int|float|bool|string|null
    {
        return match (true) {
            is_bool($value), is_int($value), is_float($value), $value === null => $value,
            default => '[not_logged]',
        };
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
