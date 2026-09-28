<?php

namespace App\Domains\Assets\Commands;

use App\Domains\Assets\Enums\AssetCategory;
use App\Domains\Assets\Enums\AssetStatus;
use App\Domains\Assets\Enums\DeviceStatus;
use App\Domains\Assets\Models\Asset;
use App\Domains\Assets\Models\AssetDevice;
use App\Domains\Tenancy\Actions\RecordUsageEvent;
use App\Models\Team;
use App\Support\TenantContext;
use Illuminate\Console\Command;
use Illuminate\Database\Eloquent\Builder;

class RecordAssetUsageMeters extends Command
{
    protected $signature = 'assets:record-usage-meters';

    protected $description = 'Record daily usage meters for monitored assets and active cameras per team';

    /**
     * Device types that are a camera. Dashcams are synced from the provider as
     * devices attached to a vehicle (Samsara `cameraSerial` → `camera`), not as
     * assets of their own.
     *
     * @var array<int, string>
     */
    public const array CAMERA_DEVICE_TYPES = ['camera', 'dashcam'];

    public function handle(RecordUsageEvent $recordUsage): int
    {
        $date = now()->toDateString();

        // Comando de plataforma: recorre todos los tenants a propósito, y
        // cuenta los activos de cada uno dentro de su contexto. Ver §2.1.
        Team::query()->each(function (Team $team) use ($recordUsage, $date) {
            TenantContext::for($team->id, function () use ($team, $recordUsage, $date) {
                $this->recordMonitoredAssets($team, $recordUsage, $date);
                $this->recordActiveCameras($team, $recordUsage, $date);
            });
        });

        $this->info('Asset usage meters recorded successfully.');

        return self::SUCCESS;
    }

    private function recordMonitoredAssets(Team $team, RecordUsageEvent $recordUsage, string $date): void
    {
        $count = Asset::query()
            ->where('status', '!=', AssetStatus::Inactive)
            ->count();

        if ($count > 0) {
            $recordUsage->execute(
                teamId: $team->id,
                meterCode: 'monitored_assets',
                quantity: $count,
                eventKey: "monitored_assets:{$team->id}:{$date}",
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
                ->where('status', '!=', AssetStatus::Inactive))
            ->count();

        // Stand-alone cameras registered as assets of their own (fixed cameras),
        // unless they already carry an attached camera device counted above.
        $cameraAssets = Asset::query()
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
            );
        }
    }
}
