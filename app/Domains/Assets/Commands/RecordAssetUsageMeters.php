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
use App\Domains\Tenancy\Support\TenantCanSend;
use App\Models\Team;
use App\Support\SystemLog;
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
            // Nunca el valor recibido: basta saber que vino una opción inválida.
            SystemLog::failed('billing.daily_close.completed', reason: 'invalid_date', input: ['date_option_present' => true]);

            $this->error('La fecha debe tener formato Y-m-d.');

            return self::FAILURE;
        }

        // Solo sumas de plataforma: ningún id de tenant sale de aquí. Es un
        // objeto (no un escalar por referencia) porque la arrow fn de abajo
        // captura por valor: un `&$contador` dentro de ella apuntaría a una
        // copia y el código de salida nunca vería los fallos.
        $totals = new \ArrayObject([
            'teams_scanned_count' => 0,
            'teams_closed_count' => 0,
            'teams_blocked_count' => 0,
            'asset_days_recorded_count' => 0,
            'asset_days_already_recorded_count' => 0,
            'cameras_count' => 0,
            'teams_failed_count' => 0,
        ]);

        // Comando de plataforma: recorre todos los tenants a propósito, y
        // cuenta los activos de cada uno dentro de su contexto. Ver §2.1. Un
        // tenant que falla no deja sin cierre a los que siguen.
        TenantContext::withoutTenant(fn () => Team::query()->select('id')->chunkById(100, function ($teams) use ($recordUsage, $recordAssetDay, $date, $totals) {
            foreach ($teams as $team) {
                self::bump($totals, 'teams_scanned_count');

                try {
                    TenantContext::for($team->id, function () use ($team, $recordUsage, $recordAssetDay, $date, $totals) {
                        if (($blocked = TenantCanSend::blockedReason($team->id)) !== null) {
                            SystemLog::skipped('billing.tenant.blocked', reason: $blocked, input: ['team_id' => $team->id, 'stage' => 'daily_close', 'local_date' => $date]);
                            self::bump($totals, 'teams_blocked_count');

                            return;
                        }

                        $assets = $this->recordMonitoredAssets($team, $recordUsage, $recordAssetDay, $date);
                        $cameras = $this->recordActiveCameras($team, $recordUsage, $date);

                        $recorded = $assets['outcome_counts']['recorded'] ?? 0;
                        $alreadyRecorded = $assets['outcome_counts']['already_recorded'] ?? 0;

                        SystemLog::ok(
                            'billing.daily_close.tenant_closed',
                            input: ['team_id' => $team->id, 'local_date' => $date],
                            calc: [
                                'assets_monitored_count' => $assets['assets_monitored_count'],
                                'recorded_count' => $recorded,
                                'already_recorded_count' => $alreadyRecorded,
                                'legacy_sample_exists_count' => $assets['outcome_counts']['legacy_sample_exists'] ?? 0,
                                'attached_cameras_count' => $cameras['attached_cameras_count'],
                                'standalone_cameras_count' => $cameras['standalone_cameras_count'],
                            ],
                            result: [
                                'monitored_assets_gauge_recorded' => $assets['gauge_recorded'],
                                'active_cameras_recorded' => $cameras['recorded'],
                            ],
                        );

                        self::bump($totals, 'teams_closed_count');
                        self::bump($totals, 'asset_days_recorded_count', $recorded);
                        self::bump($totals, 'asset_days_already_recorded_count', $alreadyRecorded);
                        self::bump($totals, 'cameras_count', $cameras['attached_cameras_count'] + $cameras['standalone_cameras_count']);
                    });
                } catch (\Throwable $e) {
                    self::bump($totals, 'teams_failed_count');
                    TenantContext::for($team->id, fn () => SystemLog::failed(
                        'billing.daily_close.tenant_failed',
                        reason: 'exception',
                        input: ['team_id' => $team->id, 'local_date' => $date],
                        error: $e,
                    ));
                    report($e);
                    // Sólo la clase: el mensaje puede traer bindings o datos del tenant.
                    $this->warn("Tenant {$team->id}: cierre fallido (".$e::class.').');
                }
            }
        }));

        // Mismo criterio que resolveDate(): null o '' = hoy; cualquier otra
        // opción ya pasó la validación Y-m-d, así que nunca es '0'.
        $dateOption = $this->option('date');
        $closeInput = ['local_date' => $date, 'date_source' => $dateOption === null || $dateOption === '' ? 'today' : 'option'];
        $closeResult = $totals->getArrayCopy();

        if ($closeResult['teams_failed_count'] > 0) {
            SystemLog::degraded('billing.daily_close.completed', reason: 'tenant_failures', input: $closeInput, result: $closeResult);
        } else {
            SystemLog::ok('billing.daily_close.completed', input: $closeInput, result: $closeResult);
        }

        if ($closeResult['teams_failed_count'] > 0) {
            $this->error("Cierre {$date} con {$closeResult['teams_failed_count']} tenant(s) fallido(s): reintenta con --date={$date}.");

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

        $parsed = \DateTimeImmutable::createFromFormat('!Y-m-d', $option);

        return $parsed !== false && $parsed->format('Y-m-d') === $option ? $option : null;
    }

    /**
     * Suma sobre el acumulador compartido (todas sus claves arrancan en 0).
     *
     * @param  \ArrayObject<string, int>  $totals
     */
    private static function bump(\ArrayObject $totals, string $key, int $by = 1): void
    {
        $totals[$key] = ($totals[$key] ?? 0) + $by;
    }

    /**
     * El cierre del día registra un tracto-día por cada unidad vigilada
     * (idempotente: la que ya se cobró al encenderse hoy no se duplica) y el
     * gauge `monitored_assets` (pico del mes, informa el tope).
     *
     * @return array{assets_monitored_count: int, outcome_counts: array<string, int>, gauge_recorded: bool}
     */
    private function recordMonitoredAssets(Team $team, RecordUsageEvent $recordUsage, RecordMonitoredAssetDay $recordAssetDay, string $date): array
    {
        $count = 0;
        $outcomeCounts = [];
        $gaugeRecorded = false;

        Asset::query()
            ->where('team_id', $team->id)
            ->monitored()
            ->where('status', '!=', AssetStatus::Inactive)
            ->chunkById(200, function ($assets) use ($recordAssetDay, $date, &$count, &$outcomeCounts) {
                foreach ($assets as $asset) {
                    $outcome = $recordAssetDay->outcome($asset, $date, tenantBillable: true, fromDailyClose: true);
                    $outcomeCounts[$outcome] = ($outcomeCounts[$outcome] ?? 0) + 1;
                    $count++;
                }
            });

        if ($count > 0) {
            $gaugeRecorded = $recordUsage->record(
                teamId: $team->id,
                meterCode: 'monitored_assets',
                quantity: $count,
                eventKey: "monitored_assets:{$team->id}:{$date}",
                occurredAt: AssetDayPricing::localNoon($date),
            );
        }

        return ['assets_monitored_count' => $count, 'outcome_counts' => $outcomeCounts, 'gauge_recorded' => $gaugeRecorded];
    }

    /**
     * @return array{attached_cameras_count: int, standalone_cameras_count: int, recorded: bool}
     */
    private function recordActiveCameras(Team $team, RecordUsageEvent $recordUsage, string $date): array
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
        $recorded = false;

        if ($count > 0) {
            $recorded = $recordUsage->record(
                teamId: $team->id,
                meterCode: 'active_cameras',
                quantity: $count,
                eventKey: "active_cameras:{$team->id}:{$date}",
                occurredAt: AssetDayPricing::localNoon($date),
            );
        }

        return ['attached_cameras_count' => $attachedCameras, 'standalone_cameras_count' => $cameraAssets, 'recorded' => $recorded];
    }
}
