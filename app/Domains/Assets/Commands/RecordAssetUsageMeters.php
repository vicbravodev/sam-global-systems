<?php

namespace App\Domains\Assets\Commands;

use App\Domains\Assets\Actions\RecordMonitoredAssetDay;
use App\Domains\Assets\Enums\AssetCategory;
use App\Domains\Assets\Enums\AssetStatus;
use App\Domains\Assets\Enums\DeviceStatus;
use App\Domains\Assets\Models\Asset;
use App\Domains\Assets\Models\AssetDevice;
use App\Domains\Tenancy\Actions\RecordUsageEvent;
use App\Domains\Tenancy\Support\AssetDayPricing;
use App\Models\Team;
use App\Support\TenantContext;
use Illuminate\Console\Command;
use Illuminate\Database\Eloquent\Builder;

class RecordAssetUsageMeters extends Command
{
    protected $signature = 'assets:record-usage-meters
        {--date= : Fecha local (Y-m-d) a cerrar; por defecto, hoy en la zona de facturación. Sirve para rellenar un cierre que faltó}';

    protected $description = 'Cierre diario de uso por tenant: tracto-día por unidad vigilada, pico de unidades y cámaras activas';

    /**
     * Device types that are a camera. Dashcams are synced from the provider as
     * devices attached to a vehicle (Samsara `cameraSerial` → `camera`), not as
     * assets of their own.
     *
     * @var array<int, string>
     */
    public const array CAMERA_DEVICE_TYPES = ['camera', 'dashcam'];

    public const string ASSET_DAYS_METER = 'monitored_asset_days';

    public function handle(RecordUsageEvent $recordUsage, RecordMonitoredAssetDay $recordAssetDay): int
    {
        $date = $this->resolveDate();

        if ($date === null) {
            $this->error('La fecha debe tener formato Y-m-d.');

            return self::FAILURE;
        }

        $failures = 0;

        // Comando de plataforma: recorre todos los tenants a propósito, y
        // cuenta los activos de cada uno dentro de su contexto. Ver §2.1. Un
        // tenant que falla no deja sin cierre a los que siguen.
        TenantContext::withoutTenant(fn () => Team::query()->select('id')->chunkById(100, function ($teams) use ($recordUsage, $recordAssetDay, $date, &$failures) {
            foreach ($teams as $team) {
                try {
                    TenantContext::for($team->id, function () use ($team, $recordUsage, $recordAssetDay, $date) {
                        if (! RecordMonitoredAssetDay::tenantBillable((int) $team->id)) {
                            return;
                        }

                        $this->recordMonitoredAssets($team, $recordUsage, $recordAssetDay, $date);
                        $this->recordActiveCameras($team, $recordUsage, $date);
                    });
                } catch (\Throwable $e) {
                    $failures++;
                    report($e);
                    $this->warn("Tenant {$team->id}: cierre fallido ({$e->getMessage()}).");
                }
            }
        }));

        if ($failures > 0) {
            $this->error("Cierre {$date} con {$failures} tenant(s) fallido(s): reintenta con --date={$date}.");

            return self::FAILURE;
        }

        $this->info("Cierre de uso {$date} registrado.");

        return self::SUCCESS;
    }

    private function resolveDate(): ?string
    {
        $option = $this->option('date');

        if ($option === null || $option === '') {
            return AssetDayPricing::localDate(now());
        }

        $parsed = \DateTimeImmutable::createFromFormat('!Y-m-d', (string) $option);

        return $parsed !== false && $parsed->format('Y-m-d') === $option ? $option : null;
    }

    /**
     * El cierre del día registra un tracto-día por cada unidad vigilada
     * (idempotente: la que ya se cobró al encenderse hoy no se duplica) y el
     * gauge `monitored_assets` (pico del mes, informa el tope).
     */
    private function recordMonitoredAssets(Team $team, RecordUsageEvent $recordUsage, RecordMonitoredAssetDay $recordAssetDay, string $date): void
    {
        $count = 0;

        Asset::query()
            ->where('team_id', $team->id)
            ->monitored()
            ->where('status', '!=', AssetStatus::Inactive)
            ->chunkById(200, function ($assets) use ($recordAssetDay, $date, &$count) {
                foreach ($assets as $asset) {
                    $recordAssetDay->execute($asset, $date, tenantBillable: true);
                    $count++;
                }
            });

        if ($count > 0) {
            $recordUsage->execute(
                teamId: $team->id,
                meterCode: 'monitored_assets',
                quantity: $count,
                eventKey: "monitored_assets:{$team->id}:{$date}",
                occurredAt: AssetDayPricing::localNoon($date),
            );
        }
    }

    private function recordActiveCameras(Team $team, RecordUsageEvent $recordUsage, string $date): void
    {
        $attachedCameras = AssetDevice::query()
            ->whereIn('device_type', self::CAMERA_DEVICE_TYPES)
            ->where('status', DeviceStatus::Active)
            ->whereNull('detached_at')
            ->whereHas('asset', fn (Builder $asset) => $asset
                ->where('team_id', $team->id)
                ->monitored()
                ->where('status', '!=', AssetStatus::Inactive))
            ->count();

        // Stand-alone cameras registered as assets of their own (fixed cameras),
        // unless they already carry an attached camera device counted above.
        $cameraAssets = Asset::query()
            ->monitored()
            ->where('status', '!=', AssetStatus::Inactive)
            ->whereHas('assetType', fn ($query) => $query->where('category', AssetCategory::Camera))
            ->whereDoesntHave('devices', fn (Builder $device) => $device
                ->whereIn('device_type', self::CAMERA_DEVICE_TYPES)
                ->where('status', DeviceStatus::Active)
                ->whereNull('detached_at'))
            ->count();

        $count = $attachedCameras + $cameraAssets;

        if ($count > 0) {
            $recordUsage->execute(
                teamId: $team->id,
                meterCode: 'active_cameras',
                quantity: $count,
                eventKey: "active_cameras:{$team->id}:{$date}",
                occurredAt: AssetDayPricing::localNoon($date),
            );
        }
    }
}
