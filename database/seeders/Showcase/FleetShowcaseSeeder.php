<?php

namespace Database\Seeders\Showcase;

use App\Domains\Assets\Actions\RefreshAssetLivePosition;
use App\Domains\Assets\Enums\AssetStatus;
use App\Domains\Assets\Enums\TelemetryType;
use App\Domains\Assets\Models\Asset;
use App\Domains\Assets\Models\AssetType;
use App\Domains\Drivers\Enums\AssignmentSource;
use App\Domains\Drivers\Enums\AssignmentType;
use App\Domains\Drivers\Enums\DriverStatus;
use App\Domains\Drivers\Models\Driver;
use App\Domains\Drivers\Models\DriverAssignment;
use Carbon\CarbonImmutable;
use Database\Seeders\Showcase\Support\ShowcaseContext;
use Database\Seeders\Showcase\Support\ShowcaseGeo;
use Database\Seeders\Showcase\Support\ShowcaseRandom;
use Illuminate\Support\Facades\DB;

/**
 * Flota: activos y conductores (los reales si el tenant tiene; si no, una
 * flota simulada), y todo lo que cuelga de ellos: trazas GPS, telemetría
 * por tipo, dispositivos, referencias externas, asignaciones, contactos,
 * documentos (incluidos vencidos y por vencer) y bitácora de estatus.
 *
 * Todo es "rellenar huecos": un activo/conductor que ya tiene trazas,
 * telemetría, dispositivos, contactos, documentos o bitácora no recibe más.
 * Así los datos reales de Samsara nunca se tocan y la segunda corrida no
 * duplica. Los activos/conductores simulados se reconocen por
 * `external_primary_id = showcase-…`.
 */
class FleetShowcaseSeeder extends ShowcaseStep
{
    private const TRUCKS = [
        ['Kenworth', 'T680', 'T'], ['Freightliner', 'Cascadia', 'T'], ['International', 'LT625', 'T'],
        ['Volvo', 'VNL 760', 'T'], ['Nissan', 'NP300', 'U'], ['Isuzu', 'ELF 600', 'C'],
        ['Hino', '500 Serie', 'C'], ['Volkswagen', 'Crafter', 'U'],
    ];

    private const FIRST_NAMES = ['José', 'Juan', 'Luis', 'Carlos', 'Miguel', 'Jorge', 'Francisco', 'Alejandro', 'Ricardo', 'Fernando', 'Héctor', 'Raúl', 'Eduardo', 'Arturo', 'Roberto', 'Adriana', 'Gabriela', 'Rosa', 'Martín', 'Óscar'];

    private const LAST_NAMES = ['Garza', 'Treviño', 'González', 'Martínez', 'Rodríguez', 'Hernández', 'López', 'Cantú', 'Villarreal', 'Salinas', 'Guerra', 'Elizondo', 'Ramírez', 'Flores', 'Sánchez', 'Leal', 'Cavazos', 'Zambrano'];

    public function run(): void
    {
        $this->ensureAssets();
        $this->ensureDrivers();

        $this->ctx->assets = Asset::query()->where('team_id', $this->ctx->team->id)->orderBy('id')->get();
        $this->ctx->drivers = Driver::query()->where('team_id', $this->ctx->team->id)->orderBy('id')->get();

        $this->seedLocations();
        $this->seedTelemetry();
        $this->seedDevicesAndReferences();
        $this->seedAssignments();
        $this->seedDriverContacts();
        $this->seedDriverDocuments();
        $this->seedDriverStatusLog();

        $this->ctx->info("Flota: {$this->ctx->assets->count()} activos, {$this->ctx->drivers->count()} conductores.");
    }

    private function ensureAssets(): void
    {
        if (Asset::query()->where('team_id', $this->ctx->team->id)->exists()) {
            return;
        }

        $random = $this->ctx->random('assets');
        $vehicle = AssetType::query()->where('code', 'vehicle')->value('id');
        $trailer = AssetType::query()->where('code', 'trailer')->value('id') ?? $vehicle;
        $total = $this->ctx->light ? 8 : 36;

        for ($n = 1; $n <= $total; $n++) {
            $isTrailer = $n % 9 === 0;
            [$make, $model, $prefix] = self::TRUCKS[$n % count(self::TRUCKS)];
            $plate = sprintf('%s%02d-%s', chr(65 + $n % 26), $n, strtoupper(substr(md5($this->ctx->team->slug.$n), 0, 3)));
            $status = AssetStatus::from($random->weighted([
                'active' => 80, 'offline' => 5, 'alert' => 4, 'critical' => 2, 'maintenance' => 5, 'inactive' => 4,
            ]));
            $lastSeen = match ($status) {
                AssetStatus::Offline => $this->ctx->now->subHours($random->int(5, 40)),
                AssetStatus::Inactive => $this->ctx->now->subDays($random->int(10, 40)),
                default => $this->ctx->now->subMinutes($random->int(0, 20)),
            };

            Asset::query()->create([
                'team_id' => $this->ctx->team->id,
                'asset_type_id' => $isTrailer ? $trailer : $vehicle,
                'provider_id' => $this->ctx->provider?->id,
                'source_integration_id' => $this->ctx->integration?->id,
                'external_primary_id' => "showcase-{$this->ctx->team->id}-asset-{$n}",
                'name' => $isTrailer
                    ? sprintf('R-%d Remolque caja seca %s', 400 + $n, $plate)
                    : sprintf('%s-%d %s %s %s', $prefix, 100 + $n, $make, $model, $plate),
                'code' => $isTrailer ? 'R-'.(400 + $n) : $prefix.'-'.(100 + $n),
                'status' => $status,
                'metadata_json' => [
                    'showcase' => true,
                    'make' => strtoupper($make),
                    'model' => $model,
                    'year' => (string) $random->int(2016, 2025),
                    'plate' => $plate,
                    'vin' => strtoupper(substr(hash('sha256', $plate), 0, 17)),
                    'has_camera' => ! $isTrailer,
                ],
                'first_seen_at' => $this->ctx->startDay()->subMonths($random->int(3, 18)),
                'last_seen_at' => $lastSeen,
                'device_last_connected_at' => $lastSeen,
                'device_health_status' => $status === AssetStatus::Offline ? 'Disconnected' : 'Connected',
                'device_connectivity_polled_at' => $this->ctx->now->subMinutes(5),
            ]);
            $this->ctx->count('assets');
        }
    }

    private function ensureDrivers(): void
    {
        if (Driver::query()->where('team_id', $this->ctx->team->id)->exists()) {
            return;
        }

        $random = $this->ctx->random('drivers');
        $total = $this->ctx->light ? 8 : 40;

        for ($n = 1; $n <= $total; $n++) {
            $first = $random->pick(self::FIRST_NAMES);
            $last = $random->pick(self::LAST_NAMES).' '.$random->pick(self::LAST_NAMES);

            Driver::query()->create([
                'team_id' => $this->ctx->team->id,
                'external_primary_id' => "showcase-{$this->ctx->team->id}-driver-{$n}",
                'first_name' => $first,
                'last_name' => $last,
                'full_name' => "{$first} {$last}",
                'employee_code' => sprintf('OP-%04d', 1000 + $n),
                'status' => DriverStatus::from($random->weighted([
                    'active' => 78, 'off_duty' => 12, 'unavailable' => 4, 'under_review' => 4, 'suspended' => 2,
                ])),
                'phone' => ShowcaseRandom::fictionalPhone($n),
                'metadata_json' => ['showcase' => true, 'license_class' => 'E', 'shift' => $random->pick(['matutino', 'vespertino', 'nocturno'])],
                'first_seen_at' => $this->ctx->startDay()->subMonths($random->int(2, 30)),
                'last_seen_at' => $this->ctx->now->subMinutes($random->int(0, 600)),
            ]);
            $this->ctx->count('drivers');
        }
    }

    /**
     * Posición "de casa" de cada activo: su última ubicación real si existe,
     * y si no, una traza simulada de las últimas horas.
     */
    private function seedLocations(): void
    {
        $assetIds = array_values($this->ctx->assets->map(fn (Asset $asset): int => $asset->id)->all());

        $latestIds = DB::table('asset_location_snapshots')
            ->whereIn('asset_id', $assetIds)
            ->selectRaw('max(id) as id')
            ->groupBy('asset_id')
            ->pluck('id');

        foreach (DB::table('asset_location_snapshots')->whereIn('id', $latestIds)->get() as $row) {
            $this->ctx->homes[(int) $row->asset_id] = [
                (string) ($row->formatted_location ?? 'Sin dirección'),
                (float) $row->latitude,
                (float) $row->longitude,
            ];
        }

        $rows = [];

        foreach ($this->ctx->assets as $asset) {
            if (isset($this->ctx->homes[$asset->id])) {
                continue;
            }

            $random = $this->ctx->random('trail', (string) $asset->id);
            [$label, $lat, $lng] = ShowcaseGeo::place($random);
            $moving = $asset->status === AssetStatus::Active && $random->chance(0.55);
            $points = $moving ? 16 : 3;
            $lastAt = CarbonImmutable::parse($asset->last_seen_at ?? $this->ctx->now);
            $heading = $random->int(0, 359);

            for ($i = $points - 1; $i >= 0; $i--) {
                $stepMeters = $moving ? 900 : 15;
                [$pLat, $pLng] = ShowcaseGeo::jitter($random, $lat - $i * 0.004 * ($moving ? 1 : 0), $lng + $i * 0.003 * ($moving ? 1 : 0), $stepMeters);
                $rows[] = [
                    'asset_id' => $asset->id,
                    'latitude' => $pLat,
                    'longitude' => $pLng,
                    'formatted_location' => $label,
                    'speed' => $moving ? $random->float(35, 92, 1) : 0,
                    'heading' => ($heading + $random->int(-15, 15) + 360) % 360,
                    'recorded_at' => $lastAt->subMinutes($i * ($moving ? 6 : 45)),
                    'source' => 'provider',
                    'geocoding_metadata_json' => ['showcase' => true],
                ];
            }

            $this->ctx->homes[$asset->id] = [$label, $lat, $lng];
        }

        $this->bulkInsert('asset_location_snapshots', $rows);

        // The bulk insert skips model events: move the live position columns
        // the map and the fleet list read.
        app(RefreshAssetLivePosition::class)->forAssets($assetIds);
    }

    private function seedTelemetry(): void
    {
        $withTelemetry = DB::table('asset_telemetry_snapshots')
            ->whereIn('asset_id', $this->ctx->assets->pluck('id'))
            ->distinct()
            ->pluck('asset_id')
            ->all();
        $withTelemetry = array_flip($withTelemetry);
        $rows = [];

        foreach ($this->ctx->assets as $asset) {
            if (isset($withTelemetry[$asset->id])) {
                continue;
            }

            $random = $this->ctx->random('telemetry', (string) $asset->id);
            $online = ! in_array($asset->status, [AssetStatus::Offline, AssetStatus::Inactive], true);
            $readAt = $online ? $this->ctx->now->subMinutes($random->int(1, 9)) : CarbonImmutable::parse($asset->last_seen_at ?? $this->ctx->now->subDay());
            $moving = $online && $random->chance(0.5);
            $fuel = $random->float(18, 96, 0);
            $odometer = $random->float(85_000, 690_000, 1);

            $readings = [
                [TelemetryType::Speed, $moving ? $random->float(30, 95, 1) : 0.0, 'km/h'],
                [TelemetryType::Fuel, $fuel, '%'],
                [TelemetryType::Temperature, $random->float(78, 96, 1), '°C'],
                [TelemetryType::CameraStatus, $random->weighted(['online' => 88, 'offline' => 7, 'obstructed' => 5]), null],
                [TelemetryType::Battery, $random->float(12.2, 14.4, 2), 'V'],
                [TelemetryType::Ignition, $moving ? 'On' : $random->pick(['Off', 'Idle', 'Off']), null],
                [TelemetryType::Odometer, $odometer, 'km'],
            ];

            foreach ($readings as [$type, $value, $unit]) {
                $rows[] = $this->telemetryRow($asset->id, $type, $value, $unit, $readAt);
            }

            // Historial corto de combustible y odómetro para que la serie tenga sentido.
            for ($h = 1; $h <= 3; $h++) {
                $at = $readAt->subHours($h * 6);
                $rows[] = $this->telemetryRow($asset->id, TelemetryType::Fuel, min(100, $fuel + $h * $random->float(4, 9, 0)), '%', $at);
                $rows[] = $this->telemetryRow($asset->id, TelemetryType::Odometer, round($odometer - $h * $random->float(60, 180, 1), 1), 'km', $at);
            }
        }

        $this->bulkInsert('asset_telemetry_snapshots', $rows);
    }

    /**
     * @return array<string, mixed>
     */
    private function telemetryRow(int $assetId, TelemetryType $type, float|string $value, ?string $unit, CarbonImmutable $at): array
    {
        return [
            'asset_id' => $assetId,
            'telemetry_type' => $type->value,
            'data_json' => ['value' => $value, 'unit' => $unit],
            'recorded_at' => $at,
            'source_event_id' => null,
        ];
    }

    private function seedDevicesAndReferences(): void
    {
        $ids = $this->ctx->assets->pluck('id');
        $withDevices = array_flip(DB::table('asset_devices')->whereIn('asset_id', $ids)->distinct()->pluck('asset_id')->all());
        $withRefs = array_flip(DB::table('asset_external_references')->whereIn('asset_id', $ids)->distinct()->pluck('asset_id')->all());
        $devices = [];
        $refs = [];

        foreach ($this->ctx->assets as $asset) {
            $random = $this->ctx->random('devices', (string) $asset->id);
            $attached = CarbonImmutable::parse($asset->first_seen_at ?? $this->ctx->startDay());

            if (! isset($withDevices[$asset->id])) {
                $serial = 'G'.strtoupper(substr(md5('gw'.$asset->id), 0, 9));
                $devices[] = [
                    'asset_id' => $asset->id,
                    'device_type' => 'gateway',
                    'provider_id' => $this->ctx->provider?->id,
                    'external_device_id' => $serial,
                    'status' => 'active',
                    'attached_at' => $attached,
                    'metadata_json' => ['model' => 'VG54-NA', 'firmware' => '42.'.$random->int(1, 9)],
                ];

                if (($asset->metadata_json['has_camera'] ?? true) && $random->chance(0.8)) {
                    $devices[] = [
                        'asset_id' => $asset->id,
                        'device_type' => 'dashcam',
                        'provider_id' => $this->ctx->provider?->id,
                        'external_device_id' => strtoupper(substr(md5('cm'.$asset->id), 0, 4).'-'.substr(md5('cm2'.$asset->id), 0, 3).'-'.substr(md5('cm3'.$asset->id), 0, 3)),
                        'status' => 'active',
                        'attached_at' => $attached->addDays(2),
                        'metadata_json' => ['model' => 'CM32', 'channels' => ['road', 'driver']],
                    ];
                }

                // Un gateway anterior ya retirado: historial, no se muestra en la ficha.
                if ($random->chance(0.15)) {
                    $devices[] = [
                        'asset_id' => $asset->id,
                        'device_type' => 'gateway',
                        'provider_id' => $this->ctx->provider?->id,
                        'external_device_id' => 'G'.strtoupper(substr(md5('old'.$asset->id), 0, 9)),
                        'status' => 'detached',
                        'attached_at' => $attached->subMonths(8),
                        'detached_at' => $attached->subDay(),
                        'metadata_json' => ['model' => 'VG34', 'reason' => 'Reemplazo por falla de módem'],
                    ];
                }
            }

            if (! isset($withRefs[$asset->id]) && $this->ctx->provider !== null) {
                $refs[] = [
                    'asset_id' => $asset->id,
                    'provider_id' => $this->ctx->provider->id,
                    'external_id' => $asset->external_primary_id ?? "showcase-asset-ref-{$asset->id}",
                    'external_type' => 'vehicle',
                    'metadata_json' => ['showcase' => true],
                    'first_seen_at' => $attached,
                    'last_seen_at' => $asset->last_seen_at,
                ];
            }
        }

        $this->bulkInsert('asset_devices', array_map(fn (array $d) => $d + ['detached_at' => null], $devices));
        $this->bulkInsert('asset_external_references', $refs);
    }

    private function seedAssignments(): void
    {
        if (! DriverAssignment::query()->where('team_id', $this->ctx->team->id)->exists()) {
            $random = $this->ctx->random('assignments');
            $assets = $this->ctx->assets->filter(fn (Asset $a) => $a->status !== AssetStatus::Inactive)->values()->all();
            $drivers = $this->ctx->drivers->values()->all();
            $assetCount = count($assets);
            $pairs = min(count($drivers), (int) floor($assetCount * 0.9));
            $rows = [];

            for ($i = 0; $i < $pairs; $i++) {
                $started = $this->ctx->now->subDays($random->int(5, 220))->setTime(6, 0);
                $rows[] = [
                    'team_id' => $this->ctx->team->id,
                    'driver_id' => $drivers[$i]->id,
                    'asset_id' => $assets[$i]->id,
                    'assignment_type' => AssignmentType::PrimaryDriver->value,
                    'started_at' => $started,
                    'ended_at' => null,
                    'source' => AssignmentSource::Integration->value,
                    'metadata_json' => ['showcase' => true],
                ];

                // Historial: la unidad anterior del conductor.
                if ($random->chance(0.35) && $assetCount > 1) {
                    $previous = $assets[($i + 7) % $assetCount];
                    $rows[] = [
                        'team_id' => $this->ctx->team->id,
                        'driver_id' => $drivers[$i]->id,
                        'asset_id' => $previous->id,
                        'assignment_type' => AssignmentType::PrimaryDriver->value,
                        'started_at' => $started->subDays($random->int(40, 300)),
                        'ended_at' => $started->subDay(),
                        'source' => AssignmentSource::Integration->value,
                        'metadata_json' => ['showcase' => true],
                    ];
                }

                // Cobertura temporal de otra unidad durante un descanso.
                if ($random->chance(0.12) && $assetCount > 1) {
                    $covered = $this->ctx->now->subDays($random->int(3, 40));
                    $rows[] = [
                        'team_id' => $this->ctx->team->id,
                        'driver_id' => $drivers[$i]->id,
                        'asset_id' => $assets[($i + 3) % $assetCount]->id,
                        'assignment_type' => AssignmentType::TemporaryOperator->value,
                        'started_at' => $covered,
                        'ended_at' => $covered->addHours(10),
                        'source' => AssignmentSource::Manual->value,
                        'metadata_json' => ['showcase' => true, 'reason' => 'Cobertura de descanso'],
                    ];
                }
            }

            $this->bulkInsert('driver_assignments', $rows);
        }

        $this->ctx->primaryDriver = DriverAssignment::query()
            ->where('team_id', $this->ctx->team->id)
            ->where('assignment_type', AssignmentType::PrimaryDriver)
            ->whereNull('ended_at')
            ->pluck('driver_id', 'asset_id')
            ->map(fn ($id) => (int) $id)
            ->all();
    }

    private function seedDriverContacts(): void
    {
        $with = array_flip(DB::table('driver_contacts')->whereIn('driver_id', $this->ctx->drivers->pluck('id'))->distinct()->pluck('driver_id')->all());
        $rows = [];

        foreach ($this->ctx->drivers as $driver) {
            if (isset($with[$driver->id])) {
                continue;
            }

            $random = $this->ctx->random('contacts', (string) $driver->id);
            $phone = $driver->phone ?: ShowcaseRandom::fictionalPhone($driver->id);
            $slug = strtolower(preg_replace('/[^a-z]/i', '', (string) iconv('UTF-8', 'ASCII//TRANSLIT', (string) $driver->first_name)) ?: 'operador');

            $rows[] = ['driver_id' => $driver->id, 'contact_type' => 'mobile_phone', 'label' => 'Celular de ruta', 'value' => $phone, 'is_primary' => true, 'is_emergency' => false, 'verified_at' => $random->chance(0.85) ? $this->ctx->now->subDays($random->int(5, 200)) : null];
            $rows[] = ['driver_id' => $driver->id, 'contact_type' => 'email', 'label' => 'Correo', 'value' => "{$slug}.{$driver->id}@operadores.{$this->ctx->team->slug}.test", 'is_primary' => false, 'is_emergency' => false, 'verified_at' => null];
            $rows[] = ['driver_id' => $driver->id, 'contact_type' => 'emergency_contact', 'label' => $random->pick(['Esposa', 'Madre', 'Hermano', 'Esposo', 'Hija']), 'value' => ShowcaseRandom::fictionalPhone($driver->id + 7), 'is_primary' => false, 'is_emergency' => true, 'verified_at' => null];

            if ($random->chance(0.3)) {
                $rows[] = ['driver_id' => $driver->id, 'contact_type' => 'supervisor_contact', 'label' => 'Supervisor de turno', 'value' => '+12025550102', 'is_primary' => false, 'is_emergency' => false, 'verified_at' => null];
            }
        }

        $this->bulkInsert('driver_contacts', $rows);
    }

    private function seedDriverDocuments(): void
    {
        $with = array_flip(DB::table('driver_documents')->whereIn('driver_id', $this->ctx->drivers->pluck('id'))->distinct()->pluck('driver_id')->all());
        $rows = [];
        $today = $this->ctx->now->startOfDay();

        foreach ($this->ctx->drivers as $driver) {
            if (isset($with[$driver->id])) {
                continue;
            }

            $random = $this->ctx->random('documents', (string) $driver->id);
            $licenseState = $random->weighted(['valid' => 78, 'expiring' => 12, 'expired' => 10]);
            $licenseExpires = match ($licenseState) {
                'expired' => $today->subDays($random->int(3, 60)),
                'expiring' => $today->addDays($random->int(5, 28)),
                default => $today->addDays($random->int(120, 1400)),
            };

            $rows[] = $this->document($driver->id, 'license', 'LF-'.(4_000_000 + $driver->id * 13), $licenseExpires->subYears(4), $licenseExpires, match ($licenseState) {
                'expired' => 'expired',
                'expiring' => 'pending_renewal',
                default => 'valid',
            }, ['tipo' => 'Licencia federal tipo E', 'emisor' => 'SICT']);

            $medicalExpires = $random->chance(0.1) ? $today->subDays($random->int(1, 30)) : $today->addDays($random->int(20, 360));
            $rows[] = $this->document($driver->id, 'medical_cert', 'AM-'.(80_000 + $driver->id), $medicalExpires->subYear(), $medicalExpires, $medicalExpires->isPast() ? 'expired' : 'valid', ['tipo' => 'Examen psicofísico integral']);

            $rows[] = $this->document($driver->id, 'identification', 'INE-'.strtoupper(substr(md5((string) $driver->id), 0, 10)), $today->subYears(3), $today->addYears(7), 'valid', ['tipo' => 'INE']);

            if ($random->chance(0.25)) {
                $permit = $today->addDays($random->int(-20, 200));
                $rows[] = $this->document($driver->id, 'special_permit', 'MP-'.(5_000 + $driver->id), $permit->subYear(), $permit, $permit->isPast() ? 'expired' : 'valid', ['tipo' => 'Materiales peligrosos (NOM-005-SCT)']);
            }
        }

        $this->bulkInsert('driver_documents', $rows);
    }

    /**
     * @param  array<string, string>  $metadata
     * @return array<string, mixed>
     */
    private function document(int $driverId, string $type, string $number, CarbonImmutable $issued, CarbonImmutable $expires, string $status, array $metadata): array
    {
        return [
            'driver_id' => $driverId,
            'document_type' => $type,
            'document_number' => $number,
            'issued_at' => $issued->toDateString(),
            'expires_at' => $expires->toDateString(),
            'status' => $status,
            'file_url' => null,
            'metadata_json' => ['showcase' => true, ...$metadata],
        ];
    }

    private function seedDriverStatusLog(): void
    {
        $with = array_flip(DB::table('driver_statuses')->whereIn('driver_id', $this->ctx->drivers->pluck('id'))->distinct()->pluck('driver_id')->all());
        $rows = [];
        $states = [
            'on_duty' => ['En servicio', 'low', 50],
            'off_duty' => ['Fuera de turno', 'low', 25],
            'resting' => ['Descanso obligatorio', 'low', 10],
            'fatigue_watch' => ['Vigilancia por fatiga', 'medium', 6],
            'under_review' => ['En revisión por incidente', 'high', 5],
            'suspended' => ['Suspendido temporalmente', 'critical', 2],
        ];

        foreach ($this->ctx->drivers as $driver) {
            if (isset($with[$driver->id])) {
                continue;
            }

            $random = $this->ctx->random('status-log', (string) $driver->id);
            $cursor = $this->ctx->startDay()->addHours($random->int(0, 72));
            $entries = [];

            while ($cursor->lessThan($this->ctx->now) && count($entries) < 8) {
                $code = $random->weighted(array_map(fn ($s) => $s[2], $states));
                $until = $cursor->addHours($random->int(12, 24 * max(2, intdiv($this->ctx->days, 5))));
                $entries[] = [$code, $cursor, $until->lessThan($this->ctx->now) ? $until : null];
                $cursor = $until;
            }

            foreach ($entries as [$code, $from, $to]) {
                $rows[] = [
                    'driver_id' => $driver->id,
                    'status_code' => $code,
                    'status_label' => $states[$code][0],
                    'severity' => $states[$code][1],
                    'effective_from' => $from,
                    'effective_to' => $to,
                    'source_event_id' => null,
                    'metadata_json' => ['showcase' => true],
                ];
            }
        }

        $this->bulkInsert('driver_statuses', $rows);
    }

    /**
     * Útil para otros pasos: conductor que va en la unidad (o uno al azar).
     */
    public static function driverFor(ShowcaseContext $ctx, Asset $asset, ShowcaseRandom $random): ?Driver
    {
        $driverId = $ctx->primaryDriver[$asset->id] ?? null;

        if ($driverId !== null) {
            return $ctx->drivers->firstWhere('id', $driverId);
        }

        return $ctx->drivers->isEmpty() ? null : $random->pick($ctx->drivers->all());
    }
}
