<?php

namespace App\Domains\Drivers\Actions;

use App\Domains\Audit\Actions\RecordAuditEntry;
use App\Domains\Audit\Enums\AuditActorType;
use App\Domains\Audit\Enums\AuditCategory;
use App\Domains\Drivers\Support\HosMonitoringConfig;
use App\Domains\TenantConfig\Actions\UpdateTenantSetting;
use App\Domains\TenantConfig\Enums\SettingGroup;
use App\Domains\TenantConfig\Enums\SettingUpdatedByType;
use App\Domains\TenantConfig\Enums\SettingValueType;
use App\Domains\TenantConfig\Models\TenantSetting;
use App\Support\SystemLog;
use App\Support\TenantContext;

/**
 * Guarda la configuración HOS ya validada en la TenantSetting
 * `hos.monitoring` (grupo compliance: deja versión en el historial) con
 * forma canónica — enteros, booleanos, umbrales ordenados, escalones con
 * `channels` o con `escalate`, nunca ambos — y la audita. El log sólo lleva
 * conteos y números: nunca ids de etiquetas ni de unidades.
 */
class SaveHosMonitoringConfig
{
    public function __construct(
        private readonly UpdateTenantSetting $updateTenantSetting,
        private readonly RecordAuditEntry $audit,
    ) {}

    /**
     * @param  array<string, mixed>  $validated
     */
    public function execute(int $teamId, array $validated, ?int $actorId, ?string $ipAddress = null, ?string $userAgent = null): TenantSetting
    {
        return TenantContext::for($teamId, function () use ($teamId, $validated, $actorId, $ipAddress, $userAgent): TenantSetting {
            $value = self::canonical($validated);

            $setting = $this->updateTenantSetting->execute(
                teamId: $teamId,
                settingKey: HosMonitoringConfig::SETTING_KEY,
                settingGroup: SettingGroup::Compliance,
                valueType: SettingValueType::Json,
                value: $value,
                updatedByType: $actorId !== null ? SettingUpdatedByType::User : SettingUpdatedByType::System,
                updatedById: $actorId,
            );

            $calc = [
                'tag_ids_count' => count($value['tag_ids']),
                'included_count' => count($value['included_asset_ids']),
                'excluded_count' => count($value['excluded_asset_ids']),
                'situations_on' => array_keys(array_filter($value['situations'])),
                'lead_minutes' => $value['lead_minutes'],
                'cycle_lead_hours' => $value['cycle_lead_hours'],
                'rest_complete_nudge_minutes' => $value['rest_complete_nudge_minutes'],
                'rest_complete_expire_minutes' => $value['rest_complete_expire_minutes'],
                'ladder_steps_count' => count($value['ladder']),
                'ladder_escalates' => array_filter($value['ladder'], fn (array $step): bool => isset($step['escalate'])) !== [],
            ];

            $this->audit->execute(
                actorType: $actorId !== null ? AuditActorType::User : AuditActorType::System,
                actorId: $actorId,
                action: 'hos.config.updated',
                category: AuditCategory::Domain,
                entityType: 'TenantSetting',
                entityId: $setting->id,
                summary: "Configuración del monitoreo HOS actualizada (versión {$setting->version})",
                teamId: $teamId,
                metadata: $calc,
                sourceType: 'tenant_setting',
                // La versión hace única la firma: cada guardado deja su entrada.
                sourceReferenceId: (string) $setting->version,
                ipAddress: $ipAddress,
                userAgent: $userAgent,
            );

            SystemLog::ok('hos.config.updated', input: [
                'team_id' => $teamId,
                'user_id' => $actorId,
            ], calc: $calc, result: [
                'setting_id' => $setting->id,
                'version' => $setting->version,
            ]);

            return $setting;
        });
    }

    /**
     * @param  array<string, mixed>  $validated
     * @return array{tag_ids: list<string>, included_asset_ids: list<int>, excluded_asset_ids: list<int>, situations: array<string, bool>, lead_minutes: list<int>, cycle_lead_hours: list<int>, rest_complete_nudge_minutes: list<int>, rest_complete_expire_minutes: int, ladder: list<array<string, mixed>>}
     */
    public static function canonical(array $validated): array
    {
        $situations = [];
        $stored = (array) ($validated['situations'] ?? []);

        foreach (HosMonitoringConfig::CONFIGURABLE_SITUATIONS as $key) {
            $situations[$key] = filter_var($stored[$key] ?? false, FILTER_VALIDATE_BOOLEAN);
        }

        $ladder = [];

        foreach (array_filter((array) ($validated['ladder'] ?? []), 'is_array') as $step) {
            $after = self::int($step['after_minutes'] ?? null);

            if (($step['escalate'] ?? null) === 'incident') {
                $ladder[] = ['after_minutes' => $after, 'escalate' => 'incident'];

                continue;
            }

            $ladder[] = [
                'after_minutes' => $after,
                'channels' => array_values(array_unique(array_filter((array) ($step['channels'] ?? []), 'is_string'))),
            ];
        }

        $leads = self::ints($validated['lead_minutes'] ?? []);
        rsort($leads);
        $cycle = self::ints($validated['cycle_lead_hours'] ?? []);
        rsort($cycle);
        $nudges = self::ints($validated['rest_complete_nudge_minutes'] ?? []);
        sort($nudges);

        return [
            'tag_ids' => array_values(array_unique(array_map('strval', array_filter((array) ($validated['tag_ids'] ?? []), 'is_string')))),
            'included_asset_ids' => self::ints($validated['included_asset_ids'] ?? []),
            'excluded_asset_ids' => self::ints($validated['excluded_asset_ids'] ?? []),
            'situations' => $situations,
            'lead_minutes' => $leads,
            'cycle_lead_hours' => $cycle,
            'rest_complete_nudge_minutes' => $nudges,
            'rest_complete_expire_minutes' => self::int($validated['rest_complete_expire_minutes'] ?? null),
            'ladder' => $ladder,
        ];
    }

    /**
     * @return list<int>
     */
    private static function ints(mixed $list): array
    {
        return array_values(array_unique(array_map(self::int(...), (array) $list)));
    }

    private static function int(mixed $value): int
    {
        return is_numeric($value) ? (int) $value : 0;
    }
}
