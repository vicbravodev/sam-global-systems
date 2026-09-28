<?php

namespace App\Domains\Assets\Actions;

use App\Domains\Assets\Commands\RecordAssetUsageMeters;
use App\Domains\Assets\Enums\AssetStatus;
use App\Domains\Assets\Models\Asset;
use App\Domains\Tenancy\Actions\RecordUsageEvent;
use App\Domains\Tenancy\Models\UsageEvent;
use App\Domains\Tenancy\Support\AssetDayPricing;
use App\Domains\Tenancy\Support\TenantCanSend;
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
        $teamId = (int) $asset->team_id;
        $localDate ??= AssetDayPricing::localDate(now());

        if (! $asset->isMonitored() || $asset->status === AssetStatus::Inactive) {
            return false;
        }

        $tenantBillable ??= self::tenantBillable($teamId);

        if (! $tenantBillable) {
            return false;
        }

        return TenantContext::for($teamId, function () use ($asset, $teamId, $localDate) {
            if ($this->legacySampleExists($teamId, $localDate)) {
                return false;
            }

            $this->recordUsage->execute(
                teamId: $teamId,
                meterCode: AssetDayPricing::METER_CODE,
                quantity: 1,
                eventKey: self::eventKey($teamId, (int) $asset->id, $localDate),
                metadata: ['asset_id' => $asset->id, 'local_date' => $localDate],
                occurredAt: AssetDayPricing::localNoon($localDate),
            );

            return true;
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
    private function legacySampleExists(int $teamId, string $localDate): bool
    {
        return UsageEvent::query()
            ->where('team_id', $teamId)
            ->where('event_key', RecordAssetUsageMeters::ASSET_DAYS_METER.":{$teamId}:{$localDate}")
            ->exists();
    }
}
