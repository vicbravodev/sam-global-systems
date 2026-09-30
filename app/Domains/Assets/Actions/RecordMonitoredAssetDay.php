<?php

namespace App\Domains\Assets\Actions;

use App\Domains\Assets\Commands\RecordAssetUsageMeters;
use App\Domains\Assets\Enums\AssetStatus;
use App\Domains\Assets\Models\Asset;
use App\Domains\Tenancy\Actions\RecordUsageEvent;
use App\Domains\Tenancy\Models\UsageEvent;
use App\Domains\Tenancy\Support\AssetDayPricing;
use App\Domains\Tenancy\Support\TenantCanSend;
use App\Support\SystemLog;
use App\Support\TenantContext;

/**
 * Registra el tracto-día de UNA unidad en el momento en que se usa
 * (decisión 2026-09-28): al encender la vigilancia y en el cierre diario de
 * cada unidad vigilada. Una fila por unidad y día LOCAL (clave idempotente),
 * así que una unidad cobra el día si estuvo vigilada en cualquier momento de
 * él —ya no depende de una sola foto nocturna— y encenderla diez veces el
 * mismo día sigue siendo un tracto-día.
 *
 * No se cobra: tenant suspendido/cancelado/expirado, unidad no vigilada o
 * inactiva.
 */
class RecordMonitoredAssetDay
{
    public function __construct(
        private readonly RecordUsageEvent $recordUsage,
    ) {}

    /**
     * @param  bool|null  $tenantBillable  ya resuelto por el llamador (lote o cierre por tenant); null = resolverlo aquí
     */
    public function execute(Asset $asset, ?string $localDate = null, ?bool $tenantBillable = null): bool
    {
        return in_array($this->outcome($asset, $localDate, $tenantBillable), ['recorded', 'already_recorded'], true);
    }

    /**
     * Igual que `execute()`, pero dice qué pasó con el tracto-día y lo narra:
     * `recorded` (lo cuenta `billing.usage.recorded`), `already_recorded`,
     * `not_monitored`, `inactive`, `tenant_not_billable` o
     * `legacy_sample_exists`.
     *
     * @param  bool  $fromDailyClose  lo pide el cierre diario: sus saltos normales van a debug
     * @return 'recorded'|'already_recorded'|'not_monitored'|'inactive'|'tenant_not_billable'|'legacy_sample_exists'
     */
    public function outcome(Asset $asset, ?string $localDate = null, ?bool $tenantBillable = null, bool $fromDailyClose = false): string
    {
        $teamId = (int) $asset->team_id;
        $localDate ??= AssetDayPricing::localDate(now());

        return TenantContext::for($teamId, function () use ($asset, $teamId, $localDate, $tenantBillable, $fromDailyClose): string {
            $input = ['team_id' => $teamId, 'asset_id' => $asset->id, 'local_date' => $localDate];

            if (! $asset->isMonitored()) {
                SystemLog::skipped('billing.monitored_day.skipped', reason: 'not_monitored', input: $input, calc: ['monitoring_state' => $asset->monitoring_state->value], debug: $fromDailyClose);

                return 'not_monitored';
            }

            if ($asset->status === AssetStatus::Inactive) {
                SystemLog::skipped('billing.monitored_day.skipped', reason: 'inactive', input: $input, calc: ['asset_status' => $asset->status->value], debug: $fromDailyClose);

                return 'inactive';
            }

            $blocked = $tenantBillable === null
                ? TenantCanSend::blockedReason($teamId)
                : ($tenantBillable ? null : 'resolved_by_caller');

            if ($blocked !== null) {
                SystemLog::skipped('billing.monitored_day.skipped', reason: 'tenant_not_billable', input: $input, calc: ['blocked_reason' => $blocked]);

                return 'tenant_not_billable';
            }

            $legacyEventKey = self::legacyEventKey($teamId, $localDate);

            if ($this->legacySampleExists($teamId, $legacyEventKey)) {
                SystemLog::skipped('billing.monitored_day.skipped', reason: 'legacy_sample_exists', input: $input, calc: ['legacy_event_key' => $legacyEventKey]);

                return 'legacy_sample_exists';
            }

            $eventKey = self::eventKey($teamId, (int) $asset->id, $localDate);

            $inserted = $this->recordUsage->record(
                teamId: $teamId,
                meterCode: AssetDayPricing::METER_CODE,
                quantity: 1,
                eventKey: $eventKey,
                metadata: ['asset_id' => $asset->id, 'local_date' => $localDate],
                occurredAt: AssetDayPricing::localNoon($localDate),
                debug: $fromDailyClose,
            );

            if ($inserted) {
                return 'recorded';
            }

            SystemLog::skipped('billing.monitored_day.skipped', reason: 'already_recorded', input: $input, result: ['event_key' => $eventKey], debug: $fromDailyClose);

            return 'already_recorded';
        });
    }

    /**
     * Un tenant suspendido, cancelado o expirado no acumula tracto-días.
     */
    public static function tenantBillable(int $teamId): bool
    {
        return TenantCanSend::blockedReason($teamId) === null;
    }

    public static function eventKey(int $teamId, int $assetId, string $localDate): string
    {
        return "monitored_asset_day:{$teamId}:{$assetId}:{$localDate}";
    }

    /**
     * Transición desde la foto nocturna por tenant (una fila con la cantidad
     * total del día): si ese día ya se cobró con el modelo anterior, no se
     * vuelve a cobrar unidad por unidad.
     */
    private function legacySampleExists(int $teamId, string $legacyEventKey): bool
    {
        return UsageEvent::query()
            ->where('team_id', $teamId)
            ->where('event_key', $legacyEventKey)
            ->exists();
    }

    private static function legacyEventKey(int $teamId, string $localDate): string
    {
        return RecordAssetUsageMeters::ASSET_DAYS_METER.":{$teamId}:{$localDate}";
    }
}
