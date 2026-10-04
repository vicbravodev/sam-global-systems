# Monitoreo HOS — PR 1: observación sin envío — Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** SAM sondea cada minuto los relojes HOS de Samsara de los choferes inscritos (por tags y/o unidades), guarda su estado y abre/cierra episodios HOS (break, manejo, turno, ciclo, fin de pausa, violación) — registrándolo todo en bitácora y logs, **sin mandar nada** al chofer.

**Architecture:** Orquestador programado (`PollHosClocksJob`) → job por integración (`SyncHosClocksJob`) que lee `/fleet/hos/clocks` y `/tags` vía `ProviderAdapter`, resuelve el conjunto inscrito (`ResolveHosEnrollment`), detecta situaciones con una clase pura (`HosSituationDetector`) y persiste estado + episodios (`ProcessHosReadings`). Todo vive en el dominio `Drivers` (más DTO y métodos de adapter en `Integrations`).

**Tech Stack:** Laravel 13 · PHP 8.5 · PostgreSQL 18 (SQLite en tests) · PHPUnit 13 · Http::fake.

**Spec:** `docs/superpowers/specs/2026-10-04-hos-monitoring-design.md` (secciones 2, 3.1–3.8, 4, 5, 6 y 7 — PR 1).

## Global Constraints

- Tenant = `Team`. Toda tabla nueva lleva `foreignId('team_id')->constrained()->cascadeOnDelete()` + `index('team_id')` y su modelo `use App\Concerns\BelongsToTenant`.
- Patrones de `TenantContext`: orquestador = `withoutTenant` + `for($teamId)` por fila; job que recibe un modelo = trabajar dentro de `TenantContext::for($integration->team_id, ...)`; actions que reciben `int $teamId` = `TenantContext::for`.
- Ids de Samsara son únicos platform-wide: toda resolución de chofer/unidad filtra `team_id` explícito.
- Sólo se vigilan choferes cuyo `currentVehicle` es un `Asset` **`monitored`** del tenant (cobro incluido en el tracto-día; sin medidor nuevo, sin `RecordUsageEvent`).
- Logging sólo vía `App\Support\SystemLog`; códigos `dominio.etapa.resultado`; cada código nuevo en `docs/SAM/logging.md` (lo exige `LoggingConventionsTest`); nunca nombres, teléfonos, tokens ni payloads; excepciones como `error: $e`.
- Cada test que loguea: `assertSystemLogged(...)` + `assertNoSensitiveDataLogged()` (`Tests\Concerns\AssertsSystemLog`).
- Feature por tenant: `TenantFeature` `feature_key = 'hos_monitoring'`, `enabled = true` (opt-in: sin fila = apagado).
- Config del tenant: `TenantSetting` `setting_key = 'hos.monitoring'`, `setting_group = compliance`, `value_type = json`, mezclada sobre `config('hos.defaults')`.
- Constantes de reposo: break 28 800 s (8 h), manejo 39 600 s (11 h), turno 50 400 s (14 h).
- Commits: `type: subject en minúsculas`, **sin** `Co-Authored-By` ni banners (regla del repo). Formato PHP lo aplica el hook de Pint.
- No se crean directorios nuevos bajo `app/`: se usan `app/Domains/Drivers/{Actions,Data,Enums,Jobs,Models,Support}` (`Data` y `Support` se crean dentro del dominio existente, igual que en otros dominios) y `app/Domains/Integrations/Data`.

### Desviaciones del spec (decididas al planear, documentar en el PR)

1. **Tags sin sync** (spec §3.3): en vez de guardar tags en `metadata_json` durante el sync, se lee `GET /tags` en el ciclo (caché 5 min por tenant+integración). `/tags` ya trae `vehicles[]` y `drivers[]` por tag, así que la membresía queda fresca sin tocar `mapVehicle`/`mapDriver`.
2. **Resolución por reinicio de reloj** (spec §3.8): `break_due`, `drive_limit` y `shift_limit` se resuelven (`corrected`) cuando el reloj correspondiente vuelve a su valor completo, no al pasar a off-duty. Evita que una parada de 5 min cierre y reabra el episodio (avisos duplicados). En el PR 2 la escalera se **pausa** mientras el chofer no esté manejando/en turno.
3. `rest_complete` expira a los `rest_complete_expire_minutes` (default 35) para dejar pasar el último aviso de 30 min del PR 2.

## Review Focus

1. **Falla del proveedor a media corrida** (401, 429, 5xx, red caída al leer clocks o tags): no debe cerrar episodios como `unenrolled` ni tocar estados; el ciclo se descarta entero y se loguea degradado. → test en Task 7.
2. **Mismo id de Samsara en dos tenants** (dos clientes con el mismo `driver.id`/`vehicle.id` externo): cada tenant sólo resuelve sus propios choferes/unidades. → test de fuga en Task 7.
3. **Equipo de dos choferes en el mismo tracto** (uno `driving`, otro `sleeperBed` con manejo en 0): se vigilan por separado; el de sleeper no abre `drive_limit` ni `violation`. → test en Task 4.
4. **Jerarquía de tags** (elegir `LOCAL JC` debe incluir `LOCAL HT`, hijo con `parentTagId`): los descendientes entran. → test en Task 5.
5. **Lecturas incompletas** (fila sin `clocks`, sin `driver.id`, status `""`): no truena; sin `driver.id` se descarta, status `""` congela el estado y marca app desconectada. → tests en Tasks 1 y 6.

---

## File Structure

| Archivo | Responsabilidad |
|---|---|
| `config/hos.php` | defaults del módulo y TTL de caché de tags |
| `app/Domains/Integrations/Data/HosClockReading.php` | DTO inmutable de una fila de `/fleet/hos/clocks` (ms→s) |
| `app/Domains/Integrations/Contracts/ProviderAdapter.php` (mod) | `fetchHosClocks()`, `fetchTags()` |
| `app/Domains/Integrations/Contracts/NullProviderAdapter.php` (mod) | implementaciones vacías |
| `app/Domains/Integrations/Adapters/ProviderAdapterManager.php` (mod) | delegación |
| `app/Domains/Integrations/Adapters/SamsaraAdapter.php` (mod) | llamadas HTTP + mapeo + errores tipados |
| `app/Domains/Drivers/Enums/{HosDutyStatus,HosSituation,HosEpisodeResolution}.php` | enums |
| `database/migrations/2026_10_10_100000_create_hos_driver_states_table.php` | tabla de estado por chofer |
| `database/migrations/2026_10_10_100100_create_hos_episodes_table.php` | tabla de episodios + índice parcial |
| `app/Domains/Drivers/Models/{HosDriverState,HosEpisode}.php` + factories | modelos |
| `app/Domains/Drivers/Support/HosMonitoringConfig.php` | value object de configuración |
| `app/Domains/Drivers/Actions/ResolveHosMonitoringConfig.php` | feature + setting → config o `null` |
| `app/Domains/Drivers/Support/HosSituationDetector.php` + `Data/HosDetection.php` | detección pura |
| `app/Domains/Drivers/Actions/ResolveDriversFromExternalIds.php` | resolución batch tenant-scoped |
| `app/Domains/Drivers/Actions/ResolveHosEnrollment.php` + `Data/HosEnrollment.php` | conjunto inscrito |
| `app/Domains/Drivers/Actions/ProcessHosReadings.php` | estado + episodios + logs |
| `app/Domains/Drivers/Jobs/{PollHosClocksJob,SyncHosClocksJob}.php` | orquestador y job por integración |
| `routes/console.php` (mod) | schedule cada minuto |
| `docs/SAM/logging.md` (mod) | sección `### HOS (\`hos\`)` |
| `tests/Feature/Domains/Drivers/Hos/*Test.php` | tests |

---

### Task 0: Bootstrap del worktree

- [ ] **Step 1:** Invocar la skill `worktree-bootstrap` (vendor, `.env`, Wayfinder). Importante: hacer `composer install` **real** en el worktree, no symlink a `vendor/` del checkout principal (con symlink los tests cargan `App\` del checkout principal y RED/GREEN miente).
- [ ] **Step 2:** Verificar línea base: `php artisan test --compact tests/Feature/Domains/Drivers tests/Feature/Architecture/LoggingConventionsTest.php` → PASS.

---

### Task 1: Lectura de relojes HOS y tags en el adapter

**Files:**
- Create: `config/hos.php`
- Create: `app/Domains/Integrations/Data/HosClockReading.php`
- Modify: `app/Domains/Integrations/Contracts/ProviderAdapter.php`, `app/Domains/Integrations/Contracts/NullProviderAdapter.php`, `app/Domains/Integrations/Adapters/ProviderAdapterManager.php`, `app/Domains/Integrations/Adapters/SamsaraAdapter.php`
- Test: `tests/Feature/Domains/Drivers/Hos/SamsaraHosAdapterTest.php`

**Interfaces:**
- Produces:
  - `HosClockReading` (readonly): `string $externalDriverId`, `?string $externalVehicleId`, `?string $dutyStatus` (valor crudo de Samsara; `null` si `""`), `?int $breakRemainingSeconds`, `?int $driveRemainingSeconds`, `?int $shiftRemainingSeconds`, `?int $cycleRemainingSeconds`, `int $violationSeconds`; `static fromSamsara(array $row): ?self`; `toArray(): array<string, int|string|null>`; `static fromArray(array $data): self`.
  - `ProviderAdapter::fetchHosClocks(TenantIntegration $integration): array<int, HosClockReading>` — lanza `ProviderUnauthorized` (sin token, 401/403), `ProviderRateLimited` (429), `ProviderUnavailable` (5xx), `ProviderRequestFailedException` (otro no-2xx).
  - `ProviderAdapter::fetchTags(TenantIntegration $integration): array<int, array{id: string, name: string, parent_id: string|null, vehicle_ids: array<int, string>, driver_ids: array<int, string>}>` — mismos errores.

- [ ] **Step 1: Crear `config/hos.php`**

```php
<?php

/*
| Monitoreo HOS (EE. UU.) — spec docs/superpowers/specs/2026-10-04-hos-monitoring-design.md.
| `defaults` es la configuración que se usa cuando el tenant no guardó la suya
| (TenantSetting `hos.monitoring`, que se mezcla encima clave por clave).
*/

return [
    'tags_cache_seconds' => (int) env('HOS_TAGS_CACHE_SECONDS', 300),

    'defaults' => [
        'tag_ids' => [],
        'included_asset_ids' => [],
        'excluded_asset_ids' => [],
        'situations' => [
            'break_due' => true,
            'drive_limit' => true,
            'shift_limit' => true,
            'cycle_limit' => true,
            'rest_complete' => true,
        ],
        'lead_minutes' => [30, 15, 0],
        'cycle_lead_hours' => [5, 1],
        'rest_complete_nudge_minutes' => [15, 30],
        'rest_complete_expire_minutes' => 35,
        'ladder' => [
            ['after_minutes' => 0, 'channels' => ['samsara_driver_app']],
            ['after_minutes' => 5, 'channels' => ['samsara_driver_app', 'whatsapp']],
            ['after_minutes' => 10, 'channels' => ['voice']],
            ['after_minutes' => 15, 'escalate' => 'incident'],
        ],
    ],
];
```

- [ ] **Step 2: Escribir el test que falla**

```php
<?php

namespace Tests\Feature\Domains\Drivers\Hos;

use App\Domains\Integrations\Contracts\ProviderAdapter;
use App\Domains\Integrations\Exceptions\ProviderUnauthorized;
use App\Domains\Integrations\Exceptions\ProviderUnavailable;
use App\Domains\Integrations\Models\IntegrationCredential;
use App\Domains\Integrations\Models\IntegrationProvider;
use App\Domains\Integrations\Models\TenantIntegration;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class SamsaraHosAdapterTest extends TestCase
{
    use RefreshDatabase;

    private function integration(): TenantIntegration
    {
        $user = User::factory()->create();
        $provider = IntegrationProvider::where('code', 'samsara')->first()
            ?? IntegrationProvider::factory()->samsara()->create();

        $integration = TenantIntegration::withoutGlobalScopes()->create([
            'team_id' => $user->currentTeam->id,
            'provider_id' => $provider->id,
            'name' => 'Samsara Fleet',
            'status' => 'active',
            'auth_type' => 'api_key',
            'credentials_encrypted' => '',
        ]);

        IntegrationCredential::create([
            'tenant_integration_id' => $integration->id,
            'key' => 'api_token',
            'value_encrypted' => 'sk-test',
        ]);

        return $integration->load('provider');
    }

    /** Fila real de /fleet/hos/clocks (2026-10-04), anonimizada. */
    private function drivingRow(): array
    {
        return [
            'driver' => ['id' => '58072405', 'name' => 'Chofer Uno'],
            'currentVehicle' => ['id' => '281474993186040', 'name' => 'T-0321 USA'],
            'currentDutyStatus' => ['hosStatusType' => 'driving'],
            'violations' => ['shiftDrivingViolationDurationMs' => 0, 'cycleViolationDurationMs' => 0],
            'clocks' => [
                'break' => ['timeUntilBreakDurationMs' => 1593045],
                'drive' => ['driveRemainingDurationMs' => 12393045],
                'shift' => ['shiftRemainingDurationMs' => 21433967],
                'cycle' => ['cycleRemainingDurationMs' => 223033967, 'cycleStartedAtTime' => '2026-10-04T00:13:16.000Z'],
            ],
        ];
    }

    public function test_it_maps_hos_clocks_to_seconds(): void
    {
        Http::fake([
            'api.samsara.com/fleet/hos/clocks*' => Http::response([
                'data' => [
                    $this->drivingRow(),
                    // App desconectada: status vacío, sin vehículo.
                    ['driver' => ['id' => '54293941', 'name' => 'Chofer Dos'], 'currentDutyStatus' => ['hosStatusType' => ''], 'violations' => ['shiftDrivingViolationDurationMs' => 60000, 'cycleViolationDurationMs' => 0]],
                    // Sin driver.id: se descarta.
                    ['currentDutyStatus' => ['hosStatusType' => 'offDuty']],
                ],
                'pagination' => ['endCursor' => '', 'hasNextPage' => false],
            ]),
        ]);

        $readings = app(ProviderAdapter::class)->fetchHosClocks($this->integration());

        $this->assertCount(2, $readings);
        $this->assertSame('58072405', $readings[0]->externalDriverId);
        $this->assertSame('281474993186040', $readings[0]->externalVehicleId);
        $this->assertSame('driving', $readings[0]->dutyStatus);
        $this->assertSame(1593, $readings[0]->breakRemainingSeconds);
        $this->assertSame(12393, $readings[0]->driveRemainingSeconds);
        $this->assertSame(21433, $readings[0]->shiftRemainingSeconds);
        $this->assertSame(223033, $readings[0]->cycleRemainingSeconds);
        $this->assertSame(0, $readings[0]->violationSeconds);

        $this->assertNull($readings[1]->dutyStatus);
        $this->assertNull($readings[1]->externalVehicleId);
        $this->assertNull($readings[1]->breakRemainingSeconds);
        $this->assertSame(60, $readings[1]->violationSeconds);

        Http::assertSent(fn ($request) => str_contains($request->url(), 'limit=512'));
    }

    public function test_it_follows_pagination(): void
    {
        Http::fakeSequence('api.samsara.com/fleet/hos/clocks*')
            ->push(['data' => [$this->drivingRow()], 'pagination' => ['endCursor' => 'abc', 'hasNextPage' => true]])
            ->push(['data' => [array_replace($this->drivingRow(), ['driver' => ['id' => '2', 'name' => 'x']])], 'pagination' => ['endCursor' => '', 'hasNextPage' => false]]);

        $readings = app(ProviderAdapter::class)->fetchHosClocks($this->integration());

        $this->assertSame(['58072405', '2'], array_map(fn ($r) => $r->externalDriverId, $readings));
        Http::assertSent(fn ($request) => str_contains($request->url(), 'after=abc'));
    }

    public function test_a_rejected_token_throws_unauthorized(): void
    {
        Http::fake(['api.samsara.com/fleet/hos/clocks*' => Http::response(['message' => 'invalid token'], 401)]);

        $this->expectException(ProviderUnauthorized::class);

        app(ProviderAdapter::class)->fetchHosClocks($this->integration());
    }

    public function test_a_server_error_throws_unavailable(): void
    {
        Http::fake(['api.samsara.com/tags*' => Http::response([], 503)]);

        $this->expectException(ProviderUnavailable::class);

        app(ProviderAdapter::class)->fetchTags($this->integration());
    }

    public function test_it_maps_tags_with_members_and_parent(): void
    {
        Http::fake([
            'api.samsara.com/tags*' => Http::response([
                'data' => [
                    ['id' => '4738197', 'name' => 'USA', 'vehicles' => [['id' => '281474993186040', 'name' => 'T-0321']], 'drivers' => [['id' => '58072405', 'name' => 'Chofer Uno']]],
                    ['id' => '8142583', 'name' => 'LOCAL HT', 'parentTagId' => '4691922', 'drivers' => [['id' => '9', 'name' => 'x']]],
                ],
                'pagination' => ['endCursor' => '', 'hasNextPage' => false],
            ]),
        ]);

        $tags = app(ProviderAdapter::class)->fetchTags($this->integration());

        $this->assertSame([
            ['id' => '4738197', 'name' => 'USA', 'parent_id' => null, 'vehicle_ids' => ['281474993186040'], 'driver_ids' => ['58072405']],
            ['id' => '8142583', 'name' => 'LOCAL HT', 'parent_id' => '4691922', 'vehicle_ids' => [], 'driver_ids' => ['9']],
        ], $tags);
    }
}
```

- [ ] **Step 3: Correr y ver que falla**

Run: `php artisan test --compact --filter=SamsaraHosAdapterTest`
Expected: FAIL — `Call to undefined method ... fetchHosClocks()`.

- [ ] **Step 4: Crear el DTO `app/Domains/Integrations/Data/HosClockReading.php`**

```php
<?php

namespace App\Domains\Integrations\Data;

use Illuminate\Support\Arr;

/**
 * One driver's row of Samsara `GET /fleet/hos/clocks`, normalized to seconds.
 *
 * `dutyStatus` keeps Samsara's raw value (`driving`, `onDuty`, `offDuty`,
 * `sleeperBed`, `yardMove`, `personalConveyance`); Samsara returns an empty
 * string when the driver app is disconnected, mapped here to `null`. A clock
 * missing from the payload is `null` (unknown), never a default.
 */
final readonly class HosClockReading
{
    public function __construct(
        public string $externalDriverId,
        public ?string $externalVehicleId,
        public ?string $dutyStatus,
        public ?int $breakRemainingSeconds,
        public ?int $driveRemainingSeconds,
        public ?int $shiftRemainingSeconds,
        public ?int $cycleRemainingSeconds,
        public int $violationSeconds,
    ) {}

    /**
     * @param  array<string, mixed>  $row
     */
    public static function fromSamsara(array $row): ?self
    {
        $driverId = Arr::get($row, 'driver.id');

        if (! is_scalar($driverId) || (string) $driverId === '') {
            return null;
        }

        $vehicleId = Arr::get($row, 'currentVehicle.id');
        $status = Arr::get($row, 'currentDutyStatus.hosStatusType');

        return new self(
            externalDriverId: (string) $driverId,
            externalVehicleId: is_scalar($vehicleId) && (string) $vehicleId !== '' ? (string) $vehicleId : null,
            dutyStatus: is_string($status) && $status !== '' ? $status : null,
            breakRemainingSeconds: self::seconds(Arr::get($row, 'clocks.break.timeUntilBreakDurationMs')),
            driveRemainingSeconds: self::seconds(Arr::get($row, 'clocks.drive.driveRemainingDurationMs')),
            shiftRemainingSeconds: self::seconds(Arr::get($row, 'clocks.shift.shiftRemainingDurationMs')),
            cycleRemainingSeconds: self::seconds(Arr::get($row, 'clocks.cycle.cycleRemainingDurationMs')),
            violationSeconds: (self::seconds(Arr::get($row, 'violations.shiftDrivingViolationDurationMs')) ?? 0)
                + (self::seconds(Arr::get($row, 'violations.cycleViolationDurationMs')) ?? 0),
        );
    }

    /**
     * @return array{external_driver_id: string, external_vehicle_id: string|null, duty_status: string|null, break_remaining_s: int|null, drive_remaining_s: int|null, shift_remaining_s: int|null, cycle_remaining_s: int|null, violation_s: int}
     */
    public function toArray(): array
    {
        return [
            'external_driver_id' => $this->externalDriverId,
            'external_vehicle_id' => $this->externalVehicleId,
            'duty_status' => $this->dutyStatus,
            'break_remaining_s' => $this->breakRemainingSeconds,
            'drive_remaining_s' => $this->driveRemainingSeconds,
            'shift_remaining_s' => $this->shiftRemainingSeconds,
            'cycle_remaining_s' => $this->cycleRemainingSeconds,
            'violation_s' => $this->violationSeconds,
        ];
    }

    /**
     * @param  array<string, mixed>  $data  shape of {@see toArray()}
     */
    public static function fromArray(array $data): self
    {
        return new self(
            externalDriverId: (string) $data['external_driver_id'],
            externalVehicleId: isset($data['external_vehicle_id']) ? (string) $data['external_vehicle_id'] : null,
            dutyStatus: isset($data['duty_status']) ? (string) $data['duty_status'] : null,
            breakRemainingSeconds: isset($data['break_remaining_s']) ? (int) $data['break_remaining_s'] : null,
            driveRemainingSeconds: isset($data['drive_remaining_s']) ? (int) $data['drive_remaining_s'] : null,
            shiftRemainingSeconds: isset($data['shift_remaining_s']) ? (int) $data['shift_remaining_s'] : null,
            cycleRemainingSeconds: isset($data['cycle_remaining_s']) ? (int) $data['cycle_remaining_s'] : null,
            violationSeconds: (int) ($data['violation_s'] ?? 0),
        );
    }

    private static function seconds(mixed $milliseconds): ?int
    {
        return is_numeric($milliseconds) ? intdiv((int) $milliseconds, 1000) : null;
    }
}
```

- [ ] **Step 5: Contrato.** En `ProviderAdapter.php`, añadir al final de la interfaz (y el `use App\Domains\Integrations\Data\HosClockReading;`):

```php
    /**
     * Current Hours-of-Service clocks of every driver the provider reports
     * (Samsara `GET /fleet/hos/clocks`). Throws the typed
     * {@see \App\Domains\Integrations\Exceptions\ProviderRequestFailed}
     * family on failure — a partial listing would look like drivers leaving
     * the monitored set, so callers must discard the whole cycle instead.
     *
     * @return array<int, HosClockReading>
     */
    public function fetchHosClocks(TenantIntegration $integration): array;

    /**
     * Every tag of the provider org with its direct members (Samsara
     * `GET /tags`). Same failure contract as {@see fetchHosClocks()}.
     *
     * @return array<int, array{id: string, name: string, parent_id: string|null, vehicle_ids: array<int, string>, driver_ids: array<int, string>}>
     */
    public function fetchTags(TenantIntegration $integration): array;
```

- [ ] **Step 6: Null y manager.** En `NullProviderAdapter.php`:

```php
    public function fetchHosClocks(TenantIntegration $integration): array
    {
        return [];
    }

    public function fetchTags(TenantIntegration $integration): array
    {
        return [];
    }
```

En `ProviderAdapterManager.php`, junto a `fetchDeviceConnectivity`:

```php
    public function fetchHosClocks(TenantIntegration $integration): array
    {
        return $this->forIntegration($integration)->fetchHosClocks($integration);
    }

    public function fetchTags(TenantIntegration $integration): array
    {
        return $this->forIntegration($integration)->fetchTags($integration);
    }
```

Si `composer analyse` reporta otra clase que implemente `ProviderAdapter` (p. ej. un fake en `tests/`), añadirle los mismos dos métodos vacíos.

- [ ] **Step 7: Samsara.** En `SamsaraAdapter.php` añadir `use App\Domains\Integrations\Data\HosClockReading;` y, después de `fetchDeviceConnectivity()`:

```php
    public function fetchHosClocks(TenantIntegration $integration): array
    {
        $readings = [];

        foreach ($this->fetchAllPages($integration, '/fleet/hos/clocks', 512) as $row) {
            $reading = HosClockReading::fromSamsara($row);

            if ($reading !== null) {
                $readings[] = $reading;
            }
        }

        return $readings;
    }

    public function fetchTags(TenantIntegration $integration): array
    {
        $members = fn (mixed $list): array => array_values(array_filter(array_map(
            fn ($member) => is_array($member) && is_scalar($member['id'] ?? null) ? (string) $member['id'] : null,
            is_array($list) ? $list : [],
        ), fn ($id) => $id !== null && $id !== ''));

        $tags = [];

        foreach ($this->fetchAllPages($integration, '/tags', 512) as $tag) {
            $id = Arr::get($tag, 'id');

            if (! is_scalar($id) || (string) $id === '') {
                continue;
            }

            $parent = Arr::get($tag, 'parentTagId');

            $tags[] = [
                'id' => (string) $id,
                'name' => (string) Arr::get($tag, 'name', ''),
                'parent_id' => is_scalar($parent) && (string) $parent !== '' ? (string) $parent : null,
                'vehicle_ids' => $members(Arr::get($tag, 'vehicles')),
                'driver_ids' => $members(Arr::get($tag, 'drivers')),
            ];
        }

        return $tags;
    }

    /**
     * Every record of a paginated Samsara list, failing typed on any non-2xx
     * (never a partial listing: a missing page would read as data removed).
     *
     * @return array<int, array<string, mixed>>
     */
    private function fetchAllPages(TenantIntegration $integration, string $path, int $limit): array
    {
        $token = $this->resolveToken($integration);

        if ($token === null) {
            throw new ProviderUnauthorized('No hay token de API configurado para esta integración de Samsara.');
        }

        $records = [];
        $cursor = null;
        $pages = 0;

        do {
            $query = ['limit' => $limit];

            if ($cursor !== null) {
                $query['after'] = $cursor;
            }

            $response = $this->client($token)->get($path, $query);
            $status = $response->status();

            if ($status === 429) {
                $retryAfter = $response->header('Retry-After');

                throw new ProviderRateLimited(max(0.0, (float) ($retryAfter === '' || $retryAfter === '0' ? 1 : $retryAfter)));
            }

            if ($status === 401 || $status === 403) {
                throw new ProviderUnauthorized("Samsara rejected the API token (HTTP {$status}).");
            }

            if ($status >= 500) {
                throw new ProviderUnavailable("Samsara returned HTTP {$status}.");
            }

            if (! $response->successful()) {
                throw ProviderRequestFailedException::fromResponse("GET {$path}", $response);
            }

            foreach ((array) $response->json('data', []) as $record) {
                $records[] = (array) $record;
            }

            $cursor = $response->json('pagination.endCursor');
            $hasNext = (bool) $response->json('pagination.hasNextPage', false);
            $pages++;
            // endCursor de Samsara es un token opaco (string no vacío o null).
        } while ($hasNext && is_string($cursor) && $cursor !== '' && $pages < self::MAX_PAGES);

        return $records;
    }
```


- [ ] **Step 8: Correr y ver que pasa**

Run: `php artisan test --compact --filter=SamsaraHosAdapterTest`
Expected: PASS (5 tests).

- [ ] **Step 9: Commit**

```bash
git add config/hos.php app/Domains/Integrations tests/Feature/Domains/Drivers/Hos/SamsaraHosAdapterTest.php
git commit -m "feat: lee relojes hos y tags de samsara en el adapter"
```

---

### Task 2: Enums, tablas y modelos de estado y episodios

**Files:**
- Create: `app/Domains/Drivers/Enums/HosDutyStatus.php`, `HosSituation.php`, `HosEpisodeResolution.php`
- Create: `database/migrations/2026_10_10_100000_create_hos_driver_states_table.php`, `database/migrations/2026_10_10_100100_create_hos_episodes_table.php`
- Create: `app/Domains/Drivers/Models/HosDriverState.php`, `app/Domains/Drivers/Models/HosEpisode.php`
- Create: `database/factories/Domains/Drivers/HosDriverStateFactory.php`, `database/factories/Domains/Drivers/HosEpisodeFactory.php`
- Test: `tests/Feature/Domains/Drivers/Hos/HosModelsTest.php`

**Interfaces:**
- Consumes: `HosClockReading` (Task 1).
- Produces:
  - `enum HosDutyStatus: string { OffDuty='offDuty'; SleeperBed='sleeperBed'; Driving='driving'; OnDuty='onDuty'; YardMove='yardMove'; PersonalConveyance='personalConveyance'; isWorking(): bool }`
  - `enum HosSituation: string { BreakDue='break_due'; DriveLimit='drive_limit'; ShiftLimit='shift_limit'; CycleLimit='cycle_limit'; RestComplete='rest_complete'; Violation='violation' }`
  - `enum HosEpisodeResolution: string { Corrected='corrected'; Expired='expired'; Incident='incident'; Unenrolled='unenrolled' }`
  - `HosDriverState` (casts: `duty_status` → `HosDutyStatus`, timestamps) con `toReading(): HosClockReading` y relaciones `driver()`, `asset()`.
  - `HosEpisode` (casts: `situation` → `HosSituation`, `resolution` → `HosEpisodeResolution`, `snapshot_json` → array) con scope `open()` y relaciones `driver()`, `asset()`.

- [ ] **Step 1: Test que falla**

```php
<?php

namespace Tests\Feature\Domains\Drivers\Hos;

use App\Domains\Drivers\Enums\HosDutyStatus;
use App\Domains\Drivers\Enums\HosEpisodeResolution;
use App\Domains\Drivers\Enums\HosSituation;
use App\Domains\Drivers\Models\Driver;
use App\Domains\Drivers\Models\HosDriverState;
use App\Domains\Drivers\Models\HosEpisode;
use App\Models\Team;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class HosModelsTest extends TestCase
{
    use RefreshDatabase;

    public function test_a_state_rebuilds_its_reading(): void
    {
        $driver = Driver::factory()->create();
        $state = HosDriverState::factory()->create([
            'team_id' => $driver->team_id,
            'driver_id' => $driver->id,
            'duty_status' => HosDutyStatus::Driving,
            'break_remaining_s' => 1593,
            'violation_s' => 0,
        ]);

        $reading = $state->fresh()->toReading('58072405', '281');

        $this->assertSame('driving', $reading->dutyStatus);
        $this->assertSame(1593, $reading->breakRemainingSeconds);
        $this->assertSame('58072405', $reading->externalDriverId);
    }

    public function test_only_one_open_episode_per_driver_and_situation(): void
    {
        $driver = Driver::factory()->create();
        $attributes = ['team_id' => $driver->team_id, 'driver_id' => $driver->id, 'situation' => HosSituation::BreakDue];

        HosEpisode::factory()->create($attributes + ['resolved_at' => now(), 'resolution' => HosEpisodeResolution::Corrected]);
        HosEpisode::factory()->create($attributes);

        $this->expectException(UniqueConstraintViolationException::class);

        HosEpisode::factory()->create($attributes);
    }

    public function test_models_are_tenant_scoped(): void
    {
        $driver = Driver::factory()->create();
        HosEpisode::factory()->create(['team_id' => $driver->team_id, 'driver_id' => $driver->id]);
        $other = Team::factory()->create();

        \App\Support\TenantContext::for($other->id, fn () => $this->assertSame(0, HosEpisode::query()->count()));
        \App\Support\TenantContext::for($driver->team_id, fn () => $this->assertSame(1, HosEpisode::query()->open()->count()));
    }
}
```

- [ ] **Step 2:** Run `php artisan test --compact --filter=HosModelsTest` → FAIL (`Class ... HosDriverState not found`).

- [ ] **Step 3: Enums**

```php
<?php

namespace App\Domains\Drivers\Enums;

/** Samsara `currentDutyStatus.hosStatusType` values (raw strings kept as backing values). */
enum HosDutyStatus: string
{
    case OffDuty = 'offDuty';
    case SleeperBed = 'sleeperBed';
    case Driving = 'driving';
    case OnDuty = 'onDuty';
    case YardMove = 'yardMove';
    case PersonalConveyance = 'personalConveyance';

    /** On the clock for the 14-hour window: driving or working without driving. */
    public function isWorking(): bool
    {
        return in_array($this, [self::Driving, self::OnDuty, self::YardMove], true);
    }
}
```

```php
<?php

namespace App\Domains\Drivers\Enums;

enum HosSituation: string
{
    case BreakDue = 'break_due';
    case DriveLimit = 'drive_limit';
    case ShiftLimit = 'shift_limit';
    case CycleLimit = 'cycle_limit';
    case RestComplete = 'rest_complete';
    case Violation = 'violation';
}
```

```php
<?php

namespace App\Domains\Drivers\Enums;

enum HosEpisodeResolution: string
{
    case Corrected = 'corrected';
    case Expired = 'expired';
    case Incident = 'incident';
    case Unenrolled = 'unenrolled';
}
```

- [ ] **Step 4: Migraciones**

`2026_10_10_100000_create_hos_driver_states_table.php`:

```php
<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Monitoreo HOS (spec 2026-10-04): último snapshot de relojes HOS por
     * chofer vigilado. Es el "antes" contra el que se detectan transiciones
     * (p. ej. fin de pausa) en el siguiente sondeo.
     */
    public function up(): void
    {
        Schema::create('hos_driver_states', function (Blueprint $table) {
            $table->id();
            $table->foreignId('team_id')->constrained()->cascadeOnDelete();
            $table->foreignId('driver_id')->constrained()->cascadeOnDelete();
            $table->foreignId('asset_id')->nullable()->constrained()->nullOnDelete();
            $table->string('duty_status', 32)->nullable();
            $table->timestamp('status_since')->nullable();
            $table->unsignedInteger('break_remaining_s')->nullable();
            $table->unsignedInteger('drive_remaining_s')->nullable();
            $table->unsignedInteger('shift_remaining_s')->nullable();
            $table->unsignedInteger('cycle_remaining_s')->nullable();
            $table->unsignedInteger('violation_s')->default(0);
            $table->timestamp('app_disconnected_since')->nullable();
            $table->timestamp('observed_at');
            $table->timestamps();

            $table->index('team_id');
            $table->unique(['team_id', 'driver_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('hos_driver_states');
    }
};
```

`2026_10_10_100100_create_hos_episodes_table.php`:

```php
<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Monitoreo HOS (spec 2026-10-04): cada situación detectada (break,
     * manejo, turno, ciclo, fin de pausa, violación) es un episodio con su
     * ciclo de vida. `ladder_step`/`next_nudge_at`/`incident_id` los usa la
     * escalera de insistencia (PR 2). El índice parcial garantiza un solo
     * episodio ABIERTO por chofer y situación: es la defensa de idempotencia
     * ante sondeos solapados.
     */
    public function up(): void
    {
        Schema::create('hos_episodes', function (Blueprint $table) {
            $table->id();
            $table->foreignId('team_id')->constrained()->cascadeOnDelete();
            $table->foreignId('driver_id')->constrained()->cascadeOnDelete();
            $table->foreignId('asset_id')->nullable()->constrained()->nullOnDelete();
            $table->string('situation', 32);
            $table->timestamp('opened_at');
            $table->timestamp('resolved_at')->nullable();
            $table->string('resolution', 32)->nullable();
            $table->unsignedSmallInteger('ladder_step')->default(0);
            $table->timestamp('next_nudge_at')->nullable();
            $table->foreignId('incident_id')->nullable()->constrained()->nullOnDelete();
            $table->json('snapshot_json');
            $table->timestamps();

            $table->index('team_id');
            $table->index(['team_id', 'resolved_at', 'next_nudge_at']);
        });

        // PostgreSQL y SQLite aceptan índices parciales con la misma sintaxis.
        DB::statement('CREATE UNIQUE INDEX hos_episodes_one_open_per_situation ON hos_episodes (team_id, driver_id, situation) WHERE resolved_at IS NULL');
    }

    public function down(): void
    {
        Schema::dropIfExists('hos_episodes');
    }
};
```

- [ ] **Step 5: Modelos**

`app/Domains/Drivers/Models/HosDriverState.php`:

```php
<?php

namespace App\Domains\Drivers\Models;

use App\Concerns\BelongsToTenant;
use App\Domains\Assets\Models\Asset;
use App\Domains\Drivers\Enums\HosDutyStatus;
use App\Domains\Integrations\Data\HosClockReading;
use Database\Factories\Domains\Drivers\HosDriverStateFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Last HOS clock snapshot SAM observed for a monitored driver.
 */
class HosDriverState extends Model
{
    /** @use HasFactory<HosDriverStateFactory> */
    use BelongsToTenant, HasFactory;

    protected $fillable = [
        'team_id',
        'driver_id',
        'asset_id',
        'duty_status',
        'status_since',
        'break_remaining_s',
        'drive_remaining_s',
        'shift_remaining_s',
        'cycle_remaining_s',
        'violation_s',
        'app_disconnected_since',
        'observed_at',
    ];

    /**
     * @return BelongsTo<Driver, $this>
     */
    public function driver(): BelongsTo
    {
        return $this->belongsTo(Driver::class);
    }

    /**
     * @return BelongsTo<Asset, $this>
     */
    public function asset(): BelongsTo
    {
        return $this->belongsTo(Asset::class);
    }

    /** The stored snapshot as a reading, the "before" side of transition checks. */
    public function toReading(string $externalDriverId, ?string $externalVehicleId): HosClockReading
    {
        return new HosClockReading(
            externalDriverId: $externalDriverId,
            externalVehicleId: $externalVehicleId,
            dutyStatus: $this->duty_status?->value,
            breakRemainingSeconds: $this->break_remaining_s,
            driveRemainingSeconds: $this->drive_remaining_s,
            shiftRemainingSeconds: $this->shift_remaining_s,
            cycleRemainingSeconds: $this->cycle_remaining_s,
            violationSeconds: $this->violation_s,
        );
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'duty_status' => HosDutyStatus::class,
            'status_since' => 'datetime',
            'break_remaining_s' => 'integer',
            'drive_remaining_s' => 'integer',
            'shift_remaining_s' => 'integer',
            'cycle_remaining_s' => 'integer',
            'violation_s' => 'integer',
            'app_disconnected_since' => 'datetime',
            'observed_at' => 'datetime',
        ];
    }

    protected static function newFactory(): HosDriverStateFactory
    {
        return HosDriverStateFactory::new();
    }
}
```

`app/Domains/Drivers/Models/HosEpisode.php`:

```php
<?php

namespace App\Domains\Drivers\Models;

use App\Concerns\BelongsToTenant;
use App\Domains\Assets\Models\Asset;
use App\Domains\Drivers\Enums\HosEpisodeResolution;
use App\Domains\Drivers\Enums\HosSituation;
use Database\Factories\Domains\Drivers\HosEpisodeFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One HOS situation of one driver, from detection until it is corrected,
 * expires, escalates to an incident or the driver leaves the monitored set.
 */
class HosEpisode extends Model
{
    /** @use HasFactory<HosEpisodeFactory> */
    use BelongsToTenant, HasFactory;

    protected $fillable = [
        'team_id',
        'driver_id',
        'asset_id',
        'situation',
        'opened_at',
        'resolved_at',
        'resolution',
        'ladder_step',
        'next_nudge_at',
        'incident_id',
        'snapshot_json',
    ];

    /**
     * @return BelongsTo<Driver, $this>
     */
    public function driver(): BelongsTo
    {
        return $this->belongsTo(Driver::class);
    }

    /**
     * @return BelongsTo<Asset, $this>
     */
    public function asset(): BelongsTo
    {
        return $this->belongsTo(Asset::class);
    }

    /**
     * @param  Builder<HosEpisode>  $query
     * @return Builder<HosEpisode>
     */
    public function scopeOpen(Builder $query): Builder
    {
        return $query->whereNull('resolved_at');
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'situation' => HosSituation::class,
            'resolution' => HosEpisodeResolution::class,
            'opened_at' => 'datetime',
            'resolved_at' => 'datetime',
            'next_nudge_at' => 'datetime',
            'ladder_step' => 'integer',
            'snapshot_json' => 'array',
        ];
    }

    protected static function newFactory(): HosEpisodeFactory
    {
        return HosEpisodeFactory::new();
    }
}
```

- [ ] **Step 6: Factories** (el chofer se crea en el mismo team que la fila: `team_id` se deriva del driver).

`database/factories/Domains/Drivers/HosDriverStateFactory.php`:

```php
<?php

namespace Database\Factories\Domains\Drivers;

use App\Domains\Drivers\Enums\HosDutyStatus;
use App\Domains\Drivers\Models\Driver;
use App\Domains\Drivers\Models\HosDriverState;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<HosDriverState>
 */
class HosDriverStateFactory extends Factory
{
    protected $model = HosDriverState::class;

    public function definition(): array
    {
        return [
            'driver_id' => Driver::factory(),
            'team_id' => fn (array $attributes) => Driver::withoutGlobalScopes()->find($attributes['driver_id'])?->team_id,
            'duty_status' => HosDutyStatus::OffDuty,
            'status_since' => now(),
            'break_remaining_s' => 28800,
            'drive_remaining_s' => 39600,
            'shift_remaining_s' => 50400,
            'cycle_remaining_s' => 252000,
            'violation_s' => 0,
            'observed_at' => now(),
        ];
    }
}
```

`database/factories/Domains/Drivers/HosEpisodeFactory.php`:

```php
<?php

namespace Database\Factories\Domains\Drivers;

use App\Domains\Drivers\Enums\HosSituation;
use App\Domains\Drivers\Models\Driver;
use App\Domains\Drivers\Models\HosEpisode;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<HosEpisode>
 */
class HosEpisodeFactory extends Factory
{
    protected $model = HosEpisode::class;

    public function definition(): array
    {
        return [
            'driver_id' => Driver::factory(),
            'team_id' => fn (array $attributes) => Driver::withoutGlobalScopes()->find($attributes['driver_id'])?->team_id,
            'situation' => HosSituation::BreakDue,
            'opened_at' => now(),
            'snapshot_json' => [],
        ];
    }
}
```

- [ ] **Step 7:** Run `php artisan test --compact --filter=HosModelsTest` → PASS (3 tests).
- [ ] **Step 8: Commit**

```bash
git add app/Domains/Drivers/Enums app/Domains/Drivers/Models database/migrations/2026_10_10_1000* database/factories/Domains/Drivers tests/Feature/Domains/Drivers/Hos/HosModelsTest.php
git commit -m "feat: tablas y modelos de estado y episodios hos"
```

---

### Task 3: Configuración del módulo por tenant

**Files:**
- Create: `app/Domains/Drivers/Support/HosMonitoringConfig.php`
- Create: `app/Domains/Drivers/Actions/ResolveHosMonitoringConfig.php`
- Test: `tests/Feature/Domains/Drivers/Hos/ResolveHosMonitoringConfigTest.php`

**Interfaces:**
- Consumes: `config('hos.defaults')` (Task 1), `HosSituation` (Task 2), `App\Contracts\TenantConfig\TenantConfigResolver::resolve(int $teamId, string $settingKey, mixed $systemDefault = null): mixed`.
- Produces:
  - `HosMonitoringConfig` (readonly): `FEATURE_KEY = 'hos_monitoring'`, `SETTING_KEY = 'hos.monitoring'`; props `array<int,string> $tagIds`, `array<int,int> $includedAssetIds`, `array<int,int> $excludedAssetIds`, `array<string,bool> $situations`, `array<int,int> $leadMinutes`, `array<int,int> $cycleLeadHours`, `array<int,int> $restCompleteNudgeMinutes`, `int $restCompleteExpireMinutes`, `array<int, array<string,mixed>> $ladder`; `static fromArray(array $stored, array $defaults): self`; `enabled(HosSituation $s): bool` (Violation siempre `true`); `leadSeconds(): int`; `cycleLeadSeconds(): int`; `restCompleteExpireSeconds(): int`.
  - `ResolveHosMonitoringConfig::execute(int $teamId): ?HosMonitoringConfig` — `null` si la feature no está activa.

- [ ] **Step 1: Test que falla**

```php
<?php

namespace Tests\Feature\Domains\Drivers\Hos;

use App\Domains\Drivers\Actions\ResolveHosMonitoringConfig;
use App\Domains\Drivers\Enums\HosSituation;
use App\Domains\Drivers\Support\HosMonitoringConfig;
use App\Domains\Tenancy\Models\TenantFeature;
use App\Domains\TenantConfig\Enums\SettingGroup;
use App\Domains\TenantConfig\Enums\SettingValueType;
use App\Domains\TenantConfig\Models\TenantSetting;
use App\Models\Team;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ResolveHosMonitoringConfigTest extends TestCase
{
    use RefreshDatabase;

    private function enable(Team $team, bool $enabled = true): void
    {
        TenantFeature::factory()->create(['team_id' => $team->id, 'feature_key' => HosMonitoringConfig::FEATURE_KEY, 'enabled' => $enabled]);
    }

    public function test_without_the_feature_there_is_no_config(): void
    {
        $team = Team::factory()->create();

        $this->assertNull(app(ResolveHosMonitoringConfig::class)->execute($team->id));

        $this->enable($team, enabled: false);
        $this->assertNull(app(ResolveHosMonitoringConfig::class)->execute($team->id));
    }

    public function test_defaults_apply_when_the_tenant_saved_nothing(): void
    {
        $team = Team::factory()->create();
        $this->enable($team);

        $config = app(ResolveHosMonitoringConfig::class)->execute($team->id);

        $this->assertSame([], $config->tagIds);
        $this->assertSame(1800, $config->leadSeconds());
        $this->assertSame(18000, $config->cycleLeadSeconds());
        $this->assertSame(2100, $config->restCompleteExpireSeconds());
        $this->assertTrue($config->enabled(HosSituation::RestComplete));
    }

    public function test_the_tenant_setting_overrides_key_by_key(): void
    {
        $team = Team::factory()->create();
        $this->enable($team);
        TenantSetting::factory()->create([
            'team_id' => $team->id,
            'setting_key' => HosMonitoringConfig::SETTING_KEY,
            'setting_group' => SettingGroup::Compliance,
            'value_type' => SettingValueType::Json,
            'value_json' => [
                'tag_ids' => [4738197, '7076291'],
                'excluded_asset_ids' => ['12'],
                'situations' => ['rest_complete' => false],
                'lead_minutes' => [20, 10, 0],
            ],
        ]);

        $config = app(ResolveHosMonitoringConfig::class)->execute($team->id);

        $this->assertSame(['4738197', '7076291'], $config->tagIds);
        $this->assertSame([12], $config->excludedAssetIds);
        $this->assertFalse($config->enabled(HosSituation::RestComplete));
        $this->assertTrue($config->enabled(HosSituation::BreakDue));
        $this->assertTrue($config->enabled(HosSituation::Violation));
        $this->assertSame(1200, $config->leadSeconds());
    }
}
```

- [ ] **Step 2:** Run `php artisan test --compact --filter=ResolveHosMonitoringConfigTest` → FAIL (clase no existe).

- [ ] **Step 3: `HosMonitoringConfig`**

```php
<?php

namespace App\Domains\Drivers\Support;

use App\Domains\Drivers\Enums\HosSituation;

/**
 * A tenant's HOS monitoring configuration: the stored `hos.monitoring`
 * setting merged key by key over `config('hos.defaults')`.
 */
final readonly class HosMonitoringConfig
{
    public const string FEATURE_KEY = 'hos_monitoring';

    public const string SETTING_KEY = 'hos.monitoring';

    /**
     * @param  array<int, string>  $tagIds
     * @param  array<int, int>  $includedAssetIds
     * @param  array<int, int>  $excludedAssetIds
     * @param  array<string, bool>  $situations
     * @param  array<int, int>  $leadMinutes
     * @param  array<int, int>  $cycleLeadHours
     * @param  array<int, int>  $restCompleteNudgeMinutes
     * @param  array<int, array<string, mixed>>  $ladder
     */
    public function __construct(
        public array $tagIds,
        public array $includedAssetIds,
        public array $excludedAssetIds,
        public array $situations,
        public array $leadMinutes,
        public array $cycleLeadHours,
        public array $restCompleteNudgeMinutes,
        public int $restCompleteExpireMinutes,
        public array $ladder,
    ) {}

    /**
     * @param  array<string, mixed>  $stored
     * @param  array<string, mixed>  $defaults
     */
    public static function fromArray(array $stored, array $defaults): self
    {
        $value = fn (string $key): mixed => array_key_exists($key, $stored) ? $stored[$key] : $defaults[$key];
        $ints = fn (mixed $list): array => array_values(array_map('intval', array_filter((array) $list, 'is_numeric')));

        return new self(
            tagIds: array_values(array_map('strval', array_filter((array) $value('tag_ids'), 'is_scalar'))),
            includedAssetIds: $ints($value('included_asset_ids')),
            excludedAssetIds: $ints($value('excluded_asset_ids')),
            situations: array_map('boolval', array_replace((array) $defaults['situations'], (array) ($stored['situations'] ?? []))),
            leadMinutes: $ints($value('lead_minutes')),
            cycleLeadHours: $ints($value('cycle_lead_hours')),
            restCompleteNudgeMinutes: $ints($value('rest_complete_nudge_minutes')),
            restCompleteExpireMinutes: (int) $value('rest_complete_expire_minutes'),
            ladder: array_values((array) $value('ladder')),
        );
    }

    public function enabled(HosSituation $situation): bool
    {
        // Una violación ya ocurrió: no es opcional observarla.
        if ($situation === HosSituation::Violation) {
            return true;
        }

        return $this->situations[$situation->value] ?? false;
    }

    /** Earliest warning before a limit: the largest lead (default 30 min). */
    public function leadSeconds(): int
    {
        return max([0, ...$this->leadMinutes]) * 60;
    }

    public function cycleLeadSeconds(): int
    {
        return max([0, ...$this->cycleLeadHours]) * 3600;
    }

    public function restCompleteExpireSeconds(): int
    {
        return $this->restCompleteExpireMinutes * 60;
    }
}
```

- [ ] **Step 4: `ResolveHosMonitoringConfig`**

```php
<?php

namespace App\Domains\Drivers\Actions;

use App\Contracts\TenantConfig\TenantConfigResolver;
use App\Domains\Drivers\Support\HosMonitoringConfig;
use App\Domains\Tenancy\Models\TenantFeature;
use App\Support\TenantContext;

/**
 * The tenant's HOS monitoring config, or null when the `hos_monitoring`
 * feature is not explicitly enabled (opt-in: no row means off).
 */
class ResolveHosMonitoringConfig
{
    public function __construct(private readonly TenantConfigResolver $settings) {}

    public function execute(int $teamId): ?HosMonitoringConfig
    {
        return TenantContext::for($teamId, function () use ($teamId): ?HosMonitoringConfig {
            $enabled = TenantFeature::query()
                ->where('team_id', $teamId)
                ->where('feature_key', HosMonitoringConfig::FEATURE_KEY)
                ->where('enabled', true)
                ->exists();

            if (! $enabled) {
                return null;
            }

            $stored = $this->settings->resolve($teamId, HosMonitoringConfig::SETTING_KEY, []);

            return HosMonitoringConfig::fromArray(is_array($stored) ? $stored : [], (array) config('hos.defaults'));
        });
    }
}
```

- [ ] **Step 5:** Run `php artisan test --compact --filter=ResolveHosMonitoringConfigTest` → PASS (3 tests).
- [ ] **Step 6: Commit**

```bash
git add app/Domains/Drivers/Support/HosMonitoringConfig.php app/Domains/Drivers/Actions/ResolveHosMonitoringConfig.php tests/Feature/Domains/Drivers/Hos/ResolveHosMonitoringConfigTest.php
git commit -m "feat: configuracion de monitoreo hos por tenant"
```

---

### Task 4: Detector de situaciones (puro)

**Files:**
- Create: `app/Domains/Drivers/Data/HosDetection.php`
- Create: `app/Domains/Drivers/Support/HosSituationDetector.php`
- Test: `tests/Unit/Domains/Drivers/HosSituationDetectorTest.php`

**Interfaces:**
- Consumes: `HosClockReading` (Task 1), `HosDutyStatus`, `HosSituation`, `HosEpisodeResolution` (Task 2), `HosMonitoringConfig` (Task 3).
- Produces:
  - `HosDetection` (readonly): `array<int, HosSituation> $open`, `array<string, HosEpisodeResolution> $resolve` (llave = `HosSituation->value`).
  - `HosSituationDetector::detect(?HosClockReading $previous, HosClockReading $current, HosMonitoringConfig $config, array<string, CarbonInterface> $openSituations, CarbonInterface $now): HosDetection` — `$openSituations` = situación abierta → `opened_at`.
  - Constantes públicas `FULL_BREAK_SECONDS = 28800`, `FULL_DRIVE_SECONDS = 39600`, `FULL_SHIFT_SECONDS = 50400`.

- [ ] **Step 1: Test que falla**

```php
<?php

namespace Tests\Unit\Domains\Drivers;

use App\Domains\Drivers\Enums\HosEpisodeResolution;
use App\Domains\Drivers\Enums\HosSituation;
use App\Domains\Drivers\Support\HosMonitoringConfig;
use App\Domains\Drivers\Support\HosSituationDetector;
use App\Domains\Integrations\Data\HosClockReading;
use Carbon\CarbonImmutable;
use PHPUnit\Framework\TestCase;

class HosSituationDetectorTest extends TestCase
{
    private function config(array $overrides = []): HosMonitoringConfig
    {
        $defaults = require __DIR__.'/../../../../config/hos.php';

        return HosMonitoringConfig::fromArray($overrides, $defaults['defaults']);
    }

    private function reading(?string $status, ?int $break = 28800, ?int $drive = 39600, ?int $shift = 50400, ?int $cycle = 252000, int $violation = 0): HosClockReading
    {
        return new HosClockReading('1', '100', $status, $break, $drive, $shift, $cycle, $violation);
    }

    private function detect(?HosClockReading $previous, HosClockReading $current, array $open = [], array $config = []): \App\Domains\Drivers\Data\HosDetection
    {
        return (new HosSituationDetector)->detect($previous, $current, $this->config($config), $open, CarbonImmutable::parse('2026-10-04 12:00:00'));
    }

    public function test_driving_near_the_break_opens_break_due(): void
    {
        $result = $this->detect(null, $this->reading('driving', break: 26 * 60));

        $this->assertSame([HosSituation::BreakDue], $result->open);
    }

    public function test_driving_far_from_every_limit_opens_nothing(): void
    {
        $this->assertSame([], $this->detect(null, $this->reading('driving', break: 3 * 3600))->open);
    }

    public function test_break_due_resolves_only_when_the_break_clock_resets(): void
    {
        $open = ['break_due' => CarbonImmutable::parse('2026-10-04 11:50:00')];

        // Parado 5 min: el reloj no se ha reiniciado, el episodio sigue abierto.
        $this->assertSame([], $this->detect(null, $this->reading('offDuty', break: 600), $open)->resolve);

        $this->assertSame(
            ['break_due' => HosEpisodeResolution::Corrected],
            $this->detect(null, $this->reading('offDuty', break: 28800), $open)->resolve,
        );
    }

    public function test_an_open_situation_is_not_reopened(): void
    {
        $open = ['break_due' => CarbonImmutable::parse('2026-10-04 11:50:00')];

        $this->assertSame([], $this->detect(null, $this->reading('driving', break: 600), $open)->open);
    }

    public function test_drive_and_shift_limits(): void
    {
        $result = $this->detect(null, $this->reading('driving', drive: 20 * 60, shift: 25 * 60));

        $this->assertEqualsCanonicalizing([HosSituation::DriveLimit, HosSituation::ShiftLimit], $result->open);

        // En turno sin manejar: aplica la ventana de 14 h, no la de manejo.
        $this->assertSame([HosSituation::ShiftLimit], $this->detect(null, $this->reading('onDuty', drive: 20 * 60, shift: 25 * 60))->open);
    }

    public function test_cycle_limit_opens_and_resolves_on_reset(): void
    {
        $this->assertSame([HosSituation::CycleLimit], $this->detect(null, $this->reading('offDuty', cycle: 4 * 3600))->open);

        $open = ['cycle_limit' => CarbonImmutable::parse('2026-10-03 12:00:00')];
        $this->assertSame(['cycle_limit' => HosEpisodeResolution::Corrected], $this->detect(null, $this->reading('offDuty', cycle: 252000), $open)->resolve);
    }

    public function test_violation_opens_and_resolves(): void
    {
        $this->assertSame([HosSituation::Violation], $this->detect(null, $this->reading('driving', violation: 60))->open);
        $this->assertSame([HosSituation::Violation, HosSituation::DriveLimit], $this->detect(null, $this->reading('driving', drive: 0))->open);

        $open = ['violation' => CarbonImmutable::parse('2026-10-04 11:00:00')];
        $this->assertSame(['violation' => HosEpisodeResolution::Corrected], $this->detect(null, $this->reading('offDuty', drive: 0), $open)->resolve);
    }

    public function test_the_sleeping_partner_of_a_team_truck_opens_nothing(): void
    {
        // Equipo de dos choferes: el de sleeper con manejo/turno en 0 está cumpliendo su descanso.
        $this->assertSame([], $this->detect(null, $this->reading('sleeperBed', drive: 0, shift: 0))->open);
    }

    public function test_rest_complete_opens_on_the_reset_transition_only(): void
    {
        $previous = $this->reading('offDuty', break: 600);

        $this->assertSame([HosSituation::RestComplete], $this->detect($previous, $this->reading('offDuty', break: 28800))->open);
        // Sin "antes" no hay transición.
        $this->assertSame([], $this->detect(null, $this->reading('offDuty', break: 28800))->open);
        // Ya arrancó: no hay nada que recordar.
        $this->assertSame([], $this->detect($previous, $this->reading('driving', break: 28800))->open);
        // Descanso de 10 h cumplido (manejo vuelve a 11 h).
        $this->assertSame([HosSituation::RestComplete], $this->detect($this->reading('sleeperBed', break: 28800, drive: 0), $this->reading('sleeperBed', break: 28800, drive: 39600))->open);
    }

    public function test_rest_complete_resolves_when_driving_or_expires(): void
    {
        $open = ['rest_complete' => CarbonImmutable::parse('2026-10-04 11:50:00')];

        $this->assertSame(['rest_complete' => HosEpisodeResolution::Corrected], $this->detect(null, $this->reading('driving'), $open)->resolve);
        $this->assertSame([], $this->detect(null, $this->reading('offDuty'), $open)->resolve);

        $expired = ['rest_complete' => CarbonImmutable::parse('2026-10-04 11:20:00')];
        $this->assertSame(['rest_complete' => HosEpisodeResolution::Expired], $this->detect(null, $this->reading('offDuty'), $expired)->resolve);
    }

    public function test_disabled_situations_do_not_open(): void
    {
        $result = $this->detect(null, $this->reading('driving', break: 600), config: ['situations' => ['break_due' => false]]);

        $this->assertSame([], $result->open);
    }

    public function test_a_disconnected_app_freezes_everything(): void
    {
        $open = ['break_due' => CarbonImmutable::parse('2026-10-04 11:50:00')];
        $result = $this->detect(null, $this->reading(null, break: 28800, violation: 60), $open);

        $this->assertSame([], $result->open);
        $this->assertSame([], $result->resolve);
    }

    public function test_missing_clocks_never_open_a_situation(): void
    {
        $this->assertSame([], $this->detect(null, $this->reading('driving', break: null, drive: null, shift: null, cycle: null))->open);
    }
}
```

- [ ] **Step 2:** Run `php artisan test --compact --filter=HosSituationDetectorTest` → FAIL.

- [ ] **Step 3: `HosDetection`**

```php
<?php

namespace App\Domains\Drivers\Data;

use App\Domains\Drivers\Enums\HosEpisodeResolution;
use App\Domains\Drivers\Enums\HosSituation;

final readonly class HosDetection
{
    /**
     * @param  array<int, HosSituation>  $open  situations to open now
     * @param  array<string, HosEpisodeResolution>  $resolve  open situation value → how it ends
     */
    public function __construct(
        public array $open,
        public array $resolve,
    ) {}
}
```

- [ ] **Step 4: `HosSituationDetector`**

```php
<?php

namespace App\Domains\Drivers\Support;

use App\Domains\Drivers\Data\HosDetection;
use App\Domains\Drivers\Enums\HosDutyStatus;
use App\Domains\Drivers\Enums\HosEpisodeResolution;
use App\Domains\Drivers\Enums\HosSituation;
use App\Domains\Integrations\Data\HosClockReading;
use Carbon\CarbonInterface;

/**
 * Pure HOS rule evaluation for one driver: given the previous and current
 * clock readings and the situations already open, which open and which end.
 *
 * Limit situations (break, drive, shift) end when their clock RESETS, not
 * when the driver goes off duty: a 5-minute stop must not close and reopen
 * the episode (duplicate reminders). The reminder ladder (PR 2) pauses while
 * the driver is not working instead. A disconnected driver app (null status)
 * freezes everything: an unknown state is neither a breach nor a fix.
 */
class HosSituationDetector
{
    public const int FULL_BREAK_SECONDS = 28800;

    public const int FULL_DRIVE_SECONDS = 39600;

    public const int FULL_SHIFT_SECONDS = 50400;

    /**
     * @param  array<string, CarbonInterface>  $openSituations  situation value → opened_at
     */
    public function detect(
        ?HosClockReading $previous,
        HosClockReading $current,
        HosMonitoringConfig $config,
        array $openSituations,
        CarbonInterface $now,
    ): HosDetection {
        $status = HosDutyStatus::tryFrom((string) $current->dutyStatus);

        if ($status === null) {
            return new HosDetection([], []);
        }

        $driving = $status === HosDutyStatus::Driving;
        $lead = $config->leadSeconds();
        $open = [];
        $resolve = [];

        $rules = [
            HosSituation::Violation->value => [
                'opens' => $current->violationSeconds > 0 || ($driving && $current->driveRemainingSeconds === 0),
                'ends' => $current->violationSeconds === 0 && ! ($driving && $current->driveRemainingSeconds === 0),
            ],
            HosSituation::BreakDue->value => [
                'opens' => $driving && $this->atOrBelow($current->breakRemainingSeconds, $lead),
                'ends' => $this->atOrAbove($current->breakRemainingSeconds, self::FULL_BREAK_SECONDS),
            ],
            HosSituation::DriveLimit->value => [
                'opens' => $driving && $this->atOrBelow($current->driveRemainingSeconds, $lead),
                'ends' => $this->atOrAbove($current->driveRemainingSeconds, self::FULL_DRIVE_SECONDS),
            ],
            HosSituation::ShiftLimit->value => [
                'opens' => $status->isWorking() && $this->atOrBelow($current->shiftRemainingSeconds, $lead),
                'ends' => $this->atOrAbove($current->shiftRemainingSeconds, self::FULL_SHIFT_SECONDS),
            ],
            HosSituation::CycleLimit->value => [
                'opens' => $this->atOrBelow($current->cycleRemainingSeconds, $config->cycleLeadSeconds()),
                'ends' => $current->cycleRemainingSeconds !== null && $current->cycleRemainingSeconds > $config->cycleLeadSeconds(),
            ],
        ];

        foreach ($rules as $situation => $rule) {
            if (isset($openSituations[$situation])) {
                if ($rule['ends']) {
                    $resolve[$situation] = HosEpisodeResolution::Corrected;
                }

                continue;
            }

            if ($rule['opens'] && $config->enabled(HosSituation::from($situation))) {
                $open[] = HosSituation::from($situation);
            }
        }

        $rest = HosSituation::RestComplete->value;

        if (isset($openSituations[$rest])) {
            if ($driving) {
                $resolve[$rest] = HosEpisodeResolution::Corrected;
            } elseif ($openSituations[$rest]->diffInSeconds($now) >= $config->restCompleteExpireSeconds()) {
                $resolve[$rest] = HosEpisodeResolution::Expired;
            }
        } elseif (
            $previous !== null
            && ! $driving
            && $config->enabled(HosSituation::RestComplete)
            && (
                $this->reset($previous->breakRemainingSeconds, $current->breakRemainingSeconds, self::FULL_BREAK_SECONDS)
                || $this->reset($previous->driveRemainingSeconds, $current->driveRemainingSeconds, self::FULL_DRIVE_SECONDS)
            )
        ) {
            $open[] = HosSituation::RestComplete;
        }

        return new HosDetection($open, $resolve);
    }

    private function atOrBelow(?int $seconds, int $threshold): bool
    {
        return $seconds !== null && $seconds <= $threshold;
    }

    private function atOrAbove(?int $seconds, int $threshold): bool
    {
        return $seconds !== null && $seconds >= $threshold;
    }

    /** The clock went from partially used back to full: the pause was served. */
    private function reset(?int $before, ?int $after, int $full): bool
    {
        return $before !== null && $before < $full && $this->atOrAbove($after, $full);
    }
}
```

Nota: el orden de `$rules` (violación primero) es el que espera `test_violation_opens_and_resolves` (`[Violation, DriveLimit]`).

- [ ] **Step 5:** Run `php artisan test --compact --filter=HosSituationDetectorTest` → PASS (12 tests).
- [ ] **Step 6: Commit**

```bash
git add app/Domains/Drivers/Data/HosDetection.php app/Domains/Drivers/Support/HosSituationDetector.php tests/Unit/Domains/Drivers/HosSituationDetectorTest.php
git commit -m "feat: detector puro de situaciones hos"
```

---

### Task 5: Conjunto inscrito (tags + incluidos − excluidos)

**Files:**
- Create: `app/Domains/Drivers/Actions/ResolveDriversFromExternalIds.php`
- Create: `app/Domains/Drivers/Data/HosEnrollment.php`
- Create: `app/Domains/Drivers/Actions/ResolveHosEnrollment.php`
- Test: `tests/Feature/Domains/Drivers/Hos/ResolveHosEnrollmentTest.php`

**Interfaces:**
- Consumes: `HosClockReading`, `ProviderAdapter::fetchTags()` shape (Task 1), `HosMonitoringConfig` (Task 3), `App\Domains\Assets\Actions\ResolveAssetsFromExternalIds::execute(int $providerId, iterable $externalIds, int $teamId): array<string, Asset>` (sólo `monitored`).
- Produces:
  - `ResolveDriversFromExternalIds::execute(int $providerId, iterable<string> $externalIds, int $teamId): array<string, Driver>` (llave = id externo).
  - `HosEnrollment` (readonly): `array<int, array{reading: HosClockReading, driver: Driver, asset: Asset}> $enrolled`, `array<string, int> $skippedByReason` (razones: `no_vehicle`, `driver_unresolved`, `vehicle_unresolved`, `excluded`, `no_match`).
  - `ResolveHosEnrollment::execute(TenantIntegration $integration, HosMonitoringConfig $config, array $readings, array $tags): HosEnrollment`.

- [ ] **Step 1: Test que falla**

```php
<?php

namespace Tests\Feature\Domains\Drivers\Hos;

use App\Domains\Assets\Models\Asset;
use App\Domains\Assets\Models\AssetExternalReference;
use App\Domains\Drivers\Actions\ResolveHosEnrollment;
use App\Domains\Drivers\Models\Driver;
use App\Domains\Drivers\Models\DriverExternalReference;
use App\Domains\Drivers\Support\HosMonitoringConfig;
use App\Domains\Integrations\Data\HosClockReading;
use App\Domains\Integrations\Models\IntegrationProvider;
use App\Domains\Integrations\Models\TenantIntegration;
use App\Models\Team;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ResolveHosEnrollmentTest extends TestCase
{
    use RefreshDatabase;

    private TenantIntegration $integration;

    protected function setUp(): void
    {
        parent::setUp();

        $team = Team::factory()->create();
        $provider = IntegrationProvider::factory()->samsara()->create();
        $this->integration = TenantIntegration::withoutGlobalScopes()->create([
            'team_id' => $team->id, 'provider_id' => $provider->id, 'name' => 'Samsara',
            'status' => 'active', 'auth_type' => 'api_key', 'credentials_encrypted' => '',
        ]);
    }

    private function asset(string $externalId, bool $monitored = true): Asset
    {
        $factory = Asset::factory();
        $asset = ($monitored ? $factory : $factory->pendingMonitoring())->create(['team_id' => $this->integration->team_id]);
        AssetExternalReference::factory()->create(['asset_id' => $asset->id, 'provider_id' => $this->integration->provider_id, 'external_id' => $externalId, 'external_type' => 'vehicle']);

        return $asset;
    }

    private function driver(string $externalId): Driver
    {
        $driver = Driver::factory()->create(['team_id' => $this->integration->team_id]);
        DriverExternalReference::factory()->create(['driver_id' => $driver->id, 'provider_id' => $this->integration->provider_id, 'external_id' => $externalId, 'external_type' => 'driver']);

        return $driver;
    }

    private function reading(string $driverId, ?string $vehicleId): HosClockReading
    {
        return new HosClockReading($driverId, $vehicleId, 'driving', 3600, 3600, 3600, 3600, 0);
    }

    private function config(array $stored): HosMonitoringConfig
    {
        return HosMonitoringConfig::fromArray($stored, config('hos.defaults'));
    }

    private function tags(): array
    {
        return [
            ['id' => '10', 'name' => 'TRACTOS USA', 'parent_id' => null, 'vehicle_ids' => ['v-tag'], 'driver_ids' => []],
            ['id' => '20', 'name' => 'USA', 'parent_id' => null, 'vehicle_ids' => [], 'driver_ids' => ['d-tag']],
            ['id' => '30', 'name' => 'LOCAL JC', 'parent_id' => null, 'vehicle_ids' => [], 'driver_ids' => []],
            ['id' => '31', 'name' => 'LOCAL HT', 'parent_id' => '30', 'vehicle_ids' => ['v-child'], 'driver_ids' => []],
        ];
    }

    public function test_it_enrolls_by_vehicle_tag_driver_tag_child_tag_and_manual_inclusion(): void
    {
        $byVehicleTag = $this->asset('v-tag');
        $this->driver('d1');
        $this->asset('v-plain');
        $byDriverTag = $this->driver('d-tag');
        $this->asset('v-child');
        $this->driver('d3');
        $manual = $this->asset('v-manual');
        $this->driver('d4');
        $this->asset('v-none');
        $this->driver('d5');

        $result = app(ResolveHosEnrollment::class)->execute(
            $this->integration,
            $this->config(['tag_ids' => ['10', '20', '30'], 'included_asset_ids' => [$manual->id]]),
            [
                $this->reading('d1', 'v-tag'),
                $this->reading('d-tag', 'v-plain'),
                $this->reading('d3', 'v-child'),
                $this->reading('d4', 'v-manual'),
                $this->reading('d5', 'v-none'),
            ],
            $this->tags(),
        );

        $this->assertSame(['d1', 'd-tag', 'd3', 'd4'], array_map(fn ($row) => $row['reading']->externalDriverId, $result->enrolled));
        $this->assertSame($byVehicleTag->id, $result->enrolled[0]['asset']->id);
        $this->assertSame($byDriverTag->id, $result->enrolled[1]['driver']->id);
        $this->assertSame(['no_match' => 1], $result->skippedByReason);
    }

    public function test_exclusion_wins_and_unresolvable_rows_are_counted(): void
    {
        $excluded = $this->asset('v-tag');
        $this->driver('d1');
        $this->asset('v-pending', monitored: false);
        $this->driver('d2');
        $this->asset('v-ok');

        $result = app(ResolveHosEnrollment::class)->execute(
            $this->integration,
            $this->config(['tag_ids' => ['10'], 'excluded_asset_ids' => [$excluded->id]]),
            [
                $this->reading('d1', 'v-tag'),
                $this->reading('d2', 'v-pending'),
                $this->reading('d9', 'v-ok'),
                $this->reading('d1', null),
            ],
            $this->tags(),
        );

        $this->assertSame([], $result->enrolled);
        $this->assertSame(['excluded' => 1, 'vehicle_unresolved' => 1, 'driver_unresolved' => 1, 'no_vehicle' => 1], $result->skippedByReason);
    }

    public function test_another_tenants_driver_with_the_same_external_id_is_not_resolved(): void
    {
        $this->asset('v-tag');
        $foreignTeam = Team::factory()->create();
        $foreign = Driver::factory()->create(['team_id' => $foreignTeam->id]);
        DriverExternalReference::factory()->create(['driver_id' => $foreign->id, 'provider_id' => $this->integration->provider_id, 'external_id' => 'd1', 'external_type' => 'driver']);

        $result = app(ResolveHosEnrollment::class)->execute($this->integration, $this->config(['tag_ids' => ['10']]), [$this->reading('d1', 'v-tag')], $this->tags());

        $this->assertSame([], $result->enrolled);
        $this->assertSame(['driver_unresolved' => 1], $result->skippedByReason);
    }
}
```


- [ ] **Step 2:** Run `php artisan test --compact --filter=ResolveHosEnrollmentTest` → FAIL.

- [ ] **Step 3: `ResolveDriversFromExternalIds`**

```php
<?php

namespace App\Domains\Drivers\Actions;

use App\Domains\Drivers\Models\Driver;
use App\Domains\Drivers\Models\DriverExternalReference;

/**
 * Batch provider-id → Driver resolution for fleet polls, tenant-scoped: the
 * provider's driver ids are unique platform-wide, so `$teamId` is applied
 * explicitly and another tenant's driver with the same external id is absent.
 */
class ResolveDriversFromExternalIds
{
    private const int CHUNK_SIZE = 1000;

    /**
     * @param  iterable<int, string>  $externalIds
     * @return array<string, Driver> keyed by external id
     */
    public function execute(int $providerId, iterable $externalIds, int $teamId): array
    {
        $ids = [];

        foreach ($externalIds as $externalId) {
            if ($externalId !== '') {
                $ids[$externalId] = true;
            }
        }

        $resolved = [];

        foreach (array_chunk(array_map('strval', array_keys($ids)), self::CHUNK_SIZE) as $chunk) {
            $driverIdsByExternalId = DriverExternalReference::query()
                ->where('provider_id', $providerId)
                ->whereIn('external_id', $chunk)
                ->pluck('driver_id', 'external_id');

            if ($driverIdsByExternalId->isEmpty()) {
                continue;
            }

            $drivers = Driver::query()
                ->where('team_id', $teamId)
                ->whereKey($driverIdsByExternalId->values()->unique()->all())
                ->get()
                ->keyBy('id');

            foreach ($driverIdsByExternalId as $externalId => $driverId) {
                $driver = $drivers->get($driverId);

                if ($driver !== null) {
                    $resolved[(string) $externalId] = $driver;
                }
            }
        }

        return $resolved;
    }
}
```

- [ ] **Step 4: `HosEnrollment`**

```php
<?php

namespace App\Domains\Drivers\Data;

use App\Domains\Assets\Models\Asset;
use App\Domains\Drivers\Models\Driver;
use App\Domains\Integrations\Data\HosClockReading;

final readonly class HosEnrollment
{
    /**
     * @param  array<int, array{reading: HosClockReading, driver: Driver, asset: Asset}>  $enrolled
     * @param  array<string, int>  $skippedByReason
     */
    public function __construct(
        public array $enrolled,
        public array $skippedByReason,
    ) {}
}
```

- [ ] **Step 5: `ResolveHosEnrollment`**

```php
<?php

namespace App\Domains\Drivers\Actions;

use App\Domains\Assets\Actions\ResolveAssetsFromExternalIds;
use App\Domains\Drivers\Data\HosEnrollment;
use App\Domains\Drivers\Support\HosMonitoringConfig;
use App\Domains\Integrations\Data\HosClockReading;
use App\Domains\Integrations\Models\TenantIntegration;

/**
 * Which HOS clock rows SAM watches (spec §3.4, approach A): the driver must
 * be in a MONITORED unit of the tenant (billing is the unit-day) that is not
 * excluded and that matches a chosen tag — the unit's or the driver's, a
 * child tag counting for its parent — or was added by hand.
 */
class ResolveHosEnrollment
{
    public function __construct(
        private readonly ResolveAssetsFromExternalIds $resolveAssets,
        private readonly ResolveDriversFromExternalIds $resolveDrivers,
    ) {}

    /**
     * @param  array<int, HosClockReading>  $readings
     * @param  array<int, array{id: string, name: string, parent_id: string|null, vehicle_ids: array<int, string>, driver_ids: array<int, string>}>  $tags
     */
    public function execute(TenantIntegration $integration, HosMonitoringConfig $config, array $readings, array $tags): HosEnrollment
    {
        [$taggedVehicles, $taggedDrivers] = $this->tagMembers($tags, $config->tagIds);

        $assets = $this->resolveAssets->execute(
            $integration->provider_id,
            array_values(array_filter(array_map(fn (HosClockReading $r) => $r->externalVehicleId, $readings))),
            $integration->team_id,
        );
        $drivers = $this->resolveDrivers->execute(
            $integration->provider_id,
            array_map(fn (HosClockReading $r) => $r->externalDriverId, $readings),
            $integration->team_id,
        );

        $included = array_flip($config->includedAssetIds);
        $excluded = array_flip($config->excludedAssetIds);
        $enrolled = [];
        $skipped = [];

        foreach ($readings as $reading) {
            $reason = match (true) {
                $reading->externalVehicleId === null => 'no_vehicle',
                ! isset($drivers[$reading->externalDriverId]) => 'driver_unresolved',
                ! isset($assets[$reading->externalVehicleId]) => 'vehicle_unresolved',
                isset($excluded[$assets[$reading->externalVehicleId]->id]) => 'excluded',
                isset($included[$assets[$reading->externalVehicleId]->id]),
                isset($taggedVehicles[$reading->externalVehicleId]),
                isset($taggedDrivers[$reading->externalDriverId]) => null,
                default => 'no_match',
            };

            if ($reason !== null) {
                $skipped[$reason] = ($skipped[$reason] ?? 0) + 1;

                continue;
            }

            $enrolled[] = [
                'reading' => $reading,
                'driver' => $drivers[$reading->externalDriverId],
                'asset' => $assets[$reading->externalVehicleId],
            ];
        }

        return new HosEnrollment($enrolled, $skipped);
    }

    /**
     * Members of the chosen tags and of all their descendants.
     *
     * @param  array<int, array{id: string, name: string, parent_id: string|null, vehicle_ids: array<int, string>, driver_ids: array<int, string>}>  $tags
     * @param  array<int, string>  $chosen
     * @return array{0: array<string, true>, 1: array<string, true>}
     */
    private function tagMembers(array $tags, array $chosen): array
    {
        $selected = array_fill_keys($chosen, true);

        do {
            $grew = false;

            foreach ($tags as $tag) {
                if (! isset($selected[$tag['id']]) && $tag['parent_id'] !== null && isset($selected[$tag['parent_id']])) {
                    $selected[$tag['id']] = true;
                    $grew = true;
                }
            }
        } while ($grew);

        $vehicles = [];
        $drivers = [];

        foreach ($tags as $tag) {
            if (! isset($selected[$tag['id']])) {
                continue;
            }

            foreach ($tag['vehicle_ids'] as $id) {
                $vehicles[$id] = true;
            }

            foreach ($tag['driver_ids'] as $id) {
                $drivers[$id] = true;
            }
        }

        return [$vehicles, $drivers];
    }
}
```

- [ ] **Step 6:** Run `php artisan test --compact --filter=ResolveHosEnrollmentTest` → PASS (3 tests).
- [ ] **Step 7: Commit**

```bash
git add app/Domains/Drivers/Actions/ResolveDriversFromExternalIds.php app/Domains/Drivers/Actions/ResolveHosEnrollment.php app/Domains/Drivers/Data/HosEnrollment.php tests/Feature/Domains/Drivers/Hos/ResolveHosEnrollmentTest.php
git commit -m "feat: resuelve el conjunto inscrito a monitoreo hos por tags y unidades"
```

---

### Task 6: Procesar lecturas — estado, episodios y bitácora

**Files:**
- Create: `app/Domains/Drivers/Actions/ProcessHosReadings.php`
- Modify: `docs/SAM/logging.md` (nueva sección después de `### Conductores (\`drivers\`)`)
- Test: `tests/Feature/Domains/Drivers/Hos/ProcessHosReadingsTest.php`

**Interfaces:**
- Consumes: `HosEnrollment` (Task 5), `HosSituationDetector` (Task 4), `HosDriverState`, `HosEpisode` (Task 2), `HosMonitoringConfig` (Task 3).
- Produces: `ProcessHosReadings::execute(int $teamId, HosMonitoringConfig $config, HosEnrollment $enrollment, CarbonInterface $now): array{monitored: int, opened: int, resolved: int, unenrolled: int, app_disconnected: int}`.

- [ ] **Step 1: Test que falla**

```php
<?php

namespace Tests\Feature\Domains\Drivers\Hos;

use App\Domains\Assets\Models\Asset;
use App\Domains\Drivers\Actions\ProcessHosReadings;
use App\Domains\Drivers\Data\HosEnrollment;
use App\Domains\Drivers\Enums\HosDutyStatus;
use App\Domains\Drivers\Enums\HosEpisodeResolution;
use App\Domains\Drivers\Enums\HosSituation;
use App\Domains\Drivers\Models\Driver;
use App\Domains\Drivers\Models\HosDriverState;
use App\Domains\Drivers\Models\HosEpisode;
use App\Domains\Drivers\Support\HosMonitoringConfig;
use App\Domains\Integrations\Data\HosClockReading;
use App\Models\Team;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\AssertsSystemLog;
use Tests\TestCase;

class ProcessHosReadingsTest extends TestCase
{
    use AssertsSystemLog, RefreshDatabase;

    private Team $team;

    private Driver $driver;

    private Asset $asset;

    protected function setUp(): void
    {
        parent::setUp();
        $this->team = Team::factory()->create();
        $this->driver = Driver::factory()->create(['team_id' => $this->team->id, 'full_name' => 'Juan Pérez Secreto']);
        $this->asset = Asset::factory()->create(['team_id' => $this->team->id]);
    }

    private function enrollment(HosClockReading ...$readings): HosEnrollment
    {
        return new HosEnrollment(
            array_map(fn ($r) => ['reading' => $r, 'driver' => $this->driver, 'asset' => $this->asset], $readings),
            [],
        );
    }

    private function run(HosEnrollment $enrollment, string $at = '2026-10-04 12:00:00'): array
    {
        return app(ProcessHosReadings::class)->execute(
            $this->team->id,
            HosMonitoringConfig::fromArray([], config('hos.defaults')),
            $enrollment,
            CarbonImmutable::parse($at),
        );
    }

    private function reading(?string $status, int $break = 28800): HosClockReading
    {
        return new HosClockReading('58072405', '281', $status, $break, 30000, 40000, 200000, 0);
    }

    public function test_it_stores_state_and_opens_an_episode_once(): void
    {
        $counts = $this->run($this->enrollment($this->reading('driving', break: 1500)));

        $this->assertSame(['monitored' => 1, 'opened' => 1, 'resolved' => 0, 'unenrolled' => 0, 'app_disconnected' => 0], $counts);
        $state = HosDriverState::withoutGlobalScopes()->sole();
        $this->assertSame(HosDutyStatus::Driving, $state->duty_status);
        $this->assertSame(1500, $state->break_remaining_s);
        $this->assertSame($this->asset->id, $state->asset_id);

        $episode = HosEpisode::withoutGlobalScopes()->sole();
        $this->assertSame(HosSituation::BreakDue, $episode->situation);
        $this->assertSame(1500, $episode->snapshot_json['break_remaining_s']);

        // Mismo sondeo repetido: idempotente.
        $again = $this->run($this->enrollment($this->reading('driving', break: 1440)), '2026-10-04 12:01:00');
        $this->assertSame(0, $again['opened']);
        $this->assertSame(1, HosEpisode::withoutGlobalScopes()->count());

        $opened = $this->assertSystemLogged('hos.episode.opened');
        $this->assertSame($this->team->id, $opened['input']['team_id']);
        $this->assertSame('break_due', $opened['calc']['situation']);
        $this->assertSame(1800, $opened['calc']['lead_s']);
        $this->assertNoSensitiveDataLogged();
        $this->assertStringNotContainsString('Secreto', json_encode($this->systemLogEntries()));
    }

    public function test_it_resolves_when_the_clock_resets(): void
    {
        $this->run($this->enrollment($this->reading('driving', break: 1500)));
        $counts = $this->run($this->enrollment($this->reading('offDuty', break: 28800)), '2026-10-04 12:40:00');

        $episode = HosEpisode::withoutGlobalScopes()->where('situation', HosSituation::BreakDue)->sole();
        $this->assertSame(HosEpisodeResolution::Corrected, $episode->resolution);
        $this->assertSame(1, $counts['resolved']);
        // La transición 1500 → 28800 parado abre "fin de pausa".
        $this->assertSame(1, HosEpisode::withoutGlobalScopes()->open()->where('situation', HosSituation::RestComplete)->count());
        $this->assertSystemLogged('hos.episode.resolved');
        $this->assertNoSensitiveDataLogged();
    }

    public function test_status_since_only_moves_on_a_status_change(): void
    {
        $this->run($this->enrollment($this->reading('driving')));
        $this->run($this->enrollment($this->reading('driving')), '2026-10-04 12:05:00');
        $this->assertSame('2026-10-04 12:00:00', HosDriverState::withoutGlobalScopes()->sole()->status_since->format('Y-m-d H:i:s'));

        $this->run($this->enrollment($this->reading('offDuty')), '2026-10-04 12:10:00');
        $this->assertSame('2026-10-04 12:10:00', HosDriverState::withoutGlobalScopes()->sole()->status_since->format('Y-m-d H:i:s'));
    }

    public function test_a_disconnected_app_keeps_the_last_clocks_and_logs_once(): void
    {
        $this->run($this->enrollment($this->reading('driving', break: 1500)));
        $counts = $this->run($this->enrollment($this->reading(null, break: 28800)), '2026-10-04 12:01:00');
        $this->run($this->enrollment($this->reading(null, break: 28800)), '2026-10-04 12:02:00');

        $state = HosDriverState::withoutGlobalScopes()->sole();
        $this->assertSame(1500, $state->break_remaining_s);
        $this->assertSame('2026-10-04 12:01:00', $state->app_disconnected_since->format('Y-m-d H:i:s'));
        $this->assertSame(1, $counts['app_disconnected']);
        $this->assertSame(1, HosEpisode::withoutGlobalScopes()->open()->count());
        $this->assertCount(1, array_filter($this->systemLogEntries(), fn ($e) => $e['code'] === 'hos.driver.app_disconnected'));
        $this->assertNoSensitiveDataLogged();
    }

    public function test_drivers_that_leave_the_set_close_their_episodes_as_unenrolled(): void
    {
        $this->run($this->enrollment($this->reading('driving', break: 1500)));
        $counts = $this->run(new HosEnrollment([], ['no_match' => 1]), '2026-10-04 12:01:00');

        $this->assertSame(1, $counts['unenrolled']);
        $this->assertSame(HosEpisodeResolution::Unenrolled, HosEpisode::withoutGlobalScopes()->sole()->resolution);
    }
}
```


- [ ] **Step 2:** Run `php artisan test --compact --filter=ProcessHosReadingsTest` → FAIL.

- [ ] **Step 3: Implementar `ProcessHosReadings`**

```php
<?php

namespace App\Domains\Drivers\Actions;

use App\Domains\Drivers\Data\HosEnrollment;
use App\Domains\Drivers\Enums\HosDutyStatus;
use App\Domains\Drivers\Enums\HosEpisodeResolution;
use App\Domains\Drivers\Enums\HosSituation;
use App\Domains\Drivers\Models\HosDriverState;
use App\Domains\Drivers\Models\HosEpisode;
use App\Domains\Drivers\Support\HosMonitoringConfig;
use App\Domains\Drivers\Support\HosSituationDetector;
use App\Domains\Integrations\Data\HosClockReading;
use App\Support\SystemLog;
use App\Support\TenantContext;
use Carbon\CarbonInterface;
use Illuminate\Database\UniqueConstraintViolationException;

/**
 * Applies one successful HOS poll of a tenant: stores each monitored
 * driver's clocks, opens and resolves HOS episodes, and closes the episodes
 * of drivers that left the monitored set. PR 1 only observes — nothing is
 * sent to anyone; the episodes are the log the reminder ladder will act on.
 *
 * Must only be called with a COMPLETE poll: an empty enrollment closes every
 * open episode as `unenrolled`.
 */
class ProcessHosReadings
{
    public function __construct(private readonly HosSituationDetector $detector) {}

    /**
     * @return array{monitored: int, opened: int, resolved: int, unenrolled: int, app_disconnected: int}
     */
    public function execute(int $teamId, HosMonitoringConfig $config, HosEnrollment $enrollment, CarbonInterface $now): array
    {
        return TenantContext::for($teamId, function () use ($teamId, $config, $enrollment, $now): array {
            $counts = ['monitored' => count($enrollment->enrolled), 'opened' => 0, 'resolved' => 0, 'unenrolled' => 0, 'app_disconnected' => 0];

            $driverIds = array_map(fn (array $row) => $row['driver']->id, $enrollment->enrolled);

            $states = HosDriverState::query()
                ->where('team_id', $teamId)
                ->whereIn('driver_id', $driverIds)
                ->get()
                ->keyBy('driver_id');

            $openEpisodes = HosEpisode::query()
                ->where('team_id', $teamId)
                ->open()
                ->get()
                ->groupBy('driver_id');

            foreach ($enrollment->enrolled as ['reading' => $reading, 'driver' => $driver, 'asset' => $asset]) {
                $state = $states->get($driver->id);
                $open = $openEpisodes->get($driver->id, collect())->keyBy(fn (HosEpisode $e) => $e->situation->value);

                $detection = $this->detector->detect(
                    $state?->toReading($reading->externalDriverId, $reading->externalVehicleId),
                    $reading,
                    $config,
                    $open->map(fn (HosEpisode $e) => $e->opened_at)->all(),
                    $now,
                );

                foreach ($detection->resolve as $situation => $resolution) {
                    $this->resolve($open->get($situation), $resolution, $now, $reading);
                    $counts['resolved']++;
                }

                foreach ($detection->open as $situation) {
                    if ($this->open($teamId, $driver->id, $asset->id, $situation, $reading, $config, $now)) {
                        $counts['opened']++;
                    }
                }

                if ($this->storeState($teamId, $driver->id, $asset->id, $state, $reading, $now)) {
                    $counts['app_disconnected']++;
                }
            }

            $enrolledIds = array_flip($driverIds);

            foreach ($openEpisodes as $driverId => $episodes) {
                if (isset($enrolledIds[$driverId])) {
                    continue;
                }

                foreach ($episodes as $episode) {
                    $this->resolve($episode, HosEpisodeResolution::Unenrolled, $now, null);
                    $counts['unenrolled']++;
                }
            }

            return $counts;
        });
    }

    private function open(int $teamId, int $driverId, int $assetId, HosSituation $situation, HosClockReading $reading, HosMonitoringConfig $config, CarbonInterface $now): bool
    {
        try {
            $episode = HosEpisode::query()->create([
                'team_id' => $teamId,
                'driver_id' => $driverId,
                'asset_id' => $assetId,
                'situation' => $situation,
                'opened_at' => $now,
                'snapshot_json' => $reading->toArray(),
            ]);
        } catch (UniqueConstraintViolationException) {
            // Otro sondeo solapado ya lo abrió: el índice parcial es la defensa.
            SystemLog::skipped('hos.episode.opened', reason: 'already_open', input: [
                'team_id' => $teamId,
                'driver_id' => $driverId,
            ], calc: ['situation' => $situation->value]);

            return false;
        }

        SystemLog::ok('hos.episode.opened', input: [
            'team_id' => $teamId,
            'driver_id' => $driverId,
            'asset_id' => $assetId,
        ], calc: [
            'situation' => $situation->value,
            'duty_status' => $reading->dutyStatus,
            'break_remaining_s' => $reading->breakRemainingSeconds,
            'drive_remaining_s' => $reading->driveRemainingSeconds,
            'shift_remaining_s' => $reading->shiftRemainingSeconds,
            'cycle_remaining_s' => $reading->cycleRemainingSeconds,
            'violation_s' => $reading->violationSeconds,
            'lead_s' => $config->leadSeconds(),
            'cycle_lead_s' => $config->cycleLeadSeconds(),
        ], result: ['episode_id' => $episode->id]);

        return true;
    }

    private function resolve(HosEpisode $episode, HosEpisodeResolution $resolution, CarbonInterface $now, ?HosClockReading $reading): void
    {
        $episode->forceFill(['resolved_at' => $now, 'resolution' => $resolution])->save();

        SystemLog::ok('hos.episode.resolved', input: [
            'team_id' => $episode->team_id,
            'driver_id' => $episode->driver_id,
            'episode_id' => $episode->id,
        ], calc: [
            'situation' => $episode->situation->value,
            'duty_status' => $reading?->dutyStatus,
            'open_seconds' => (int) $episode->opened_at->diffInSeconds($now),
        ], result: ['resolution' => $resolution->value]);
    }

    /**
     * @return bool whether the driver app just went dark (first poll without status)
     */
    private function storeState(int $teamId, int $driverId, int $assetId, ?HosDriverState $state, HosClockReading $reading, CarbonInterface $now): bool
    {
        $status = HosDutyStatus::tryFrom((string) $reading->dutyStatus);

        if ($status === null) {
            // Estado desconocido: se conservan los últimos relojes conocidos.
            $state ??= new HosDriverState(['team_id' => $teamId, 'driver_id' => $driverId]);
            $justDisconnected = $state->app_disconnected_since === null;
            $state->forceFill([
                'asset_id' => $assetId,
                'app_disconnected_since' => $state->app_disconnected_since ?? $now,
                'observed_at' => $now,
            ])->save();

            if ($justDisconnected) {
                SystemLog::degraded('hos.driver.app_disconnected', reason: 'empty_duty_status', input: [
                    'team_id' => $teamId,
                    'driver_id' => $driverId,
                    'asset_id' => $assetId,
                ]);
            }

            return $justDisconnected;
        }

        $state ??= new HosDriverState(['team_id' => $teamId, 'driver_id' => $driverId]);

        $state->forceFill([
            'asset_id' => $assetId,
            'duty_status' => $status,
            'status_since' => $state->duty_status === $status ? $state->status_since : $now,
            'break_remaining_s' => $reading->breakRemainingSeconds,
            'drive_remaining_s' => $reading->driveRemainingSeconds,
            'shift_remaining_s' => $reading->shiftRemainingSeconds,
            'cycle_remaining_s' => $reading->cycleRemainingSeconds,
            'violation_s' => $reading->violationSeconds,
            'app_disconnected_since' => null,
            'observed_at' => $now,
        ])->save();

        return false;
    }
}
```

- [ ] **Step 4: Catálogo de logs.** En `docs/SAM/logging.md`, después de la sección `### Conductores (\`drivers\`)` (antes de `### Analítica`), insertar:

```markdown
### HOS (`hos`) — monitoreo de horas de servicio (EE. UU.)

Spec: `docs/superpowers/specs/2026-10-04-hos-monitoring-design.md`. Nunca nombre, teléfono ni texto al chofer: sólo ids y números de los relojes.

| Código | Nivel | reason | Contexto |
|---|---|---|---|
| `hos.poll.dispatched` | ok | — | recorrido de plataforma, sin ids: result `dispatched_count`, `feature_off_count`, `tenant_blocked_count`, `sync_disabled_count` |
| `hos.poll.skipped` | skipped | `tenant_blocked` · `feature_disabled` | `team_id`, `integration_id`; calc `blocked_reason` cuando aplica. No se sondea: tenant suspendido/cancelado/expirado o feature `hos_monitoring` apagada al momento de correr el job |
| `hos.poll.failed` | degraded | `unauthorized` · `rate_limited` · `provider_error` | `team_id`, `integration_id`; `error`. Se descarta el ciclo completo (relojes o tags): no se tocan estados ni episodios para no leer un listado parcial como choferes que salieron |
| `hos.poll.completed` | ok | — | `team_id`, `integration_id`; calc `readings_count`, `tags_count`, `tag_ids_count`, `included_count`, `excluded_count`; result `monitored`, `opened`, `resolved`, `unenrolled`, `app_disconnected` y `skipped_{razón}` (`no_vehicle`, `driver_unresolved`, `vehicle_unresolved` = desconocido o no vigilado, `excluded`, `no_match`) |
| `hos.episode.opened` | ok / skipped | `already_open` | `team_id`, `driver_id`, `asset_id`; calc `situation`, `duty_status`, `break_remaining_s`, `drive_remaining_s`, `shift_remaining_s`, `cycle_remaining_s`, `violation_s`, `lead_s`, `cycle_lead_s` (umbral de apertura); result `episode_id`. `already_open` = un sondeo solapado ya lo abrió (índice parcial) |
| `hos.episode.resolved` | ok | — | `team_id`, `driver_id`, `episode_id`; calc `situation`, `duty_status`, `open_seconds`; result `resolution` (`corrected` = el reloj se reinició o el chofer arrancó tras su pausa; `expired` = fin de pausa sin arrancar tras `rest_complete_expire_minutes`; `unenrolled` = salió del conjunto vigilado) |
| `hos.driver.app_disconnected` | degraded | `empty_duty_status` | `team_id`, `driver_id`, `asset_id`. Samsara devolvió status vacío: app del chofer desconectada. Se registra sólo en la transición; se conservan los últimos relojes y no se abre ni cierra nada mientras dure |
```

- [ ] **Step 5:** Run `php artisan test --compact --filter='ProcessHosReadingsTest|LoggingConventionsTest'` → PASS.
- [ ] **Step 6: Commit**

```bash
git add app/Domains/Drivers/Actions/ProcessHosReadings.php docs/SAM/logging.md tests/Feature/Domains/Drivers/Hos/ProcessHosReadingsTest.php
git commit -m "feat: registra estado y episodios hos por chofer"
```

---

### Task 7: Jobs, schedule, fallas y fuga de tenant

**Files:**
- Create: `app/Domains/Drivers/Jobs/PollHosClocksJob.php`
- Create: `app/Domains/Drivers/Jobs/SyncHosClocksJob.php`
- Modify: `routes/console.php`
- Test: `tests/Feature/Domains/Drivers/Hos/SyncHosClocksJobTest.php`

**Interfaces:**
- Consumes: todo lo anterior; `ProviderAdapter::fetchHosClocks/fetchTags`; `ResolveHosMonitoringConfig::execute`; `ResolveHosEnrollment::execute`; `ProcessHosReadings::execute`; `App\Domains\Tenancy\Support\TenantCanSend::blockedReason(int $teamId): ?string`.
- Produces: `PollHosClocksJob` (sin argumentos, cola `telematics`) y `SyncHosClocksJob(TenantIntegration $integration)` (cola `telematics`, `ShouldBeUnique`, `uniqueId() = "hos-clocks-{id}"`). Caché de tags: llave `hos:tags:{teamId}:{integrationId}`.

- [ ] **Step 1: Test que falla**

```php
<?php

namespace Tests\Feature\Domains\Drivers\Hos;

use App\Domains\Assets\Models\Asset;
use App\Domains\Assets\Models\AssetExternalReference;
use App\Domains\Drivers\Enums\HosSituation;
use App\Domains\Drivers\Jobs\PollHosClocksJob;
use App\Domains\Drivers\Jobs\SyncHosClocksJob;
use App\Domains\Drivers\Models\Driver;
use App\Domains\Drivers\Models\DriverExternalReference;
use App\Domains\Drivers\Models\HosEpisode;
use App\Domains\Drivers\Support\HosMonitoringConfig;
use App\Domains\Integrations\Models\IntegrationCredential;
use App\Domains\Integrations\Models\IntegrationProvider;
use App\Domains\Integrations\Models\TenantIntegration;
use App\Domains\Tenancy\Models\TenantFeature;
use App\Domains\TenantConfig\Enums\SettingGroup;
use App\Domains\TenantConfig\Enums\SettingValueType;
use App\Domains\TenantConfig\Models\TenantSetting;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Tests\Concerns\AssertsSystemLog;
use Tests\Concerns\AssertsTenantIsolation;
use Tests\TestCase;

class SyncHosClocksJobTest extends TestCase
{
    use AssertsSystemLog, AssertsTenantIsolation, RefreshDatabase;

    private function tenant(bool $feature = true): TenantIntegration
    {
        $user = User::factory()->create();
        $provider = IntegrationProvider::where('code', 'samsara')->first() ?? IntegrationProvider::factory()->samsara()->create();
        $integration = TenantIntegration::withoutGlobalScopes()->create([
            'team_id' => $user->currentTeam->id, 'provider_id' => $provider->id, 'name' => 'Samsara',
            'status' => 'active', 'auth_type' => 'api_key', 'credentials_encrypted' => '',
        ]);
        IntegrationCredential::create(['tenant_integration_id' => $integration->id, 'key' => 'api_token', 'value_encrypted' => 'sk-test']);

        if ($feature) {
            TenantFeature::factory()->create(['team_id' => $integration->team_id, 'feature_key' => HosMonitoringConfig::FEATURE_KEY, 'enabled' => true]);
            TenantSetting::factory()->create([
                'team_id' => $integration->team_id, 'setting_key' => HosMonitoringConfig::SETTING_KEY,
                'setting_group' => SettingGroup::Compliance, 'value_type' => SettingValueType::Json,
                'value_json' => ['tag_ids' => ['4738197']],
            ]);
        }

        return $integration->load('provider');
    }

    private function link(TenantIntegration $integration, string $driverExternalId, string $vehicleExternalId): array
    {
        $driver = Driver::factory()->create(['team_id' => $integration->team_id]);
        DriverExternalReference::factory()->create(['driver_id' => $driver->id, 'provider_id' => $integration->provider_id, 'external_id' => $driverExternalId, 'external_type' => 'driver']);
        $asset = Asset::factory()->create(['team_id' => $integration->team_id]);
        AssetExternalReference::factory()->create(['asset_id' => $asset->id, 'provider_id' => $integration->provider_id, 'external_id' => $vehicleExternalId, 'external_type' => 'vehicle']);

        return [$driver, $asset];
    }

    private function fakeSamsara(int $breakMs = 1593045): void
    {
        Http::fake([
            'api.samsara.com/fleet/hos/clocks*' => Http::response([
                'data' => [[
                    'driver' => ['id' => '58072405', 'name' => 'Chofer Uno'],
                    'currentVehicle' => ['id' => '281', 'name' => 'T-0321 USA'],
                    'currentDutyStatus' => ['hosStatusType' => 'driving'],
                    'violations' => ['shiftDrivingViolationDurationMs' => 0, 'cycleViolationDurationMs' => 0],
                    'clocks' => [
                        'break' => ['timeUntilBreakDurationMs' => $breakMs],
                        'drive' => ['driveRemainingDurationMs' => 12393045],
                        'shift' => ['shiftRemainingDurationMs' => 21433967],
                        'cycle' => ['cycleRemainingDurationMs' => 223033967],
                    ],
                ]],
                'pagination' => ['endCursor' => '', 'hasNextPage' => false],
            ]),
            'api.samsara.com/tags*' => Http::response([
                'data' => [['id' => '4738197', 'name' => 'USA', 'vehicles' => [], 'drivers' => [['id' => '58072405']]]],
                'pagination' => ['endCursor' => '', 'hasNextPage' => false],
            ]),
        ]);
    }

    public function test_a_poll_opens_the_break_episode_for_a_tagged_driver(): void
    {
        $integration = $this->tenant();
        [$driver] = $this->link($integration, '58072405', '281');
        $this->fakeSamsara();

        app()->call([new SyncHosClocksJob($integration), 'handle']);

        $episode = HosEpisode::withoutGlobalScopes()->sole();
        $this->assertSame($driver->id, $episode->driver_id);
        $this->assertSame(HosSituation::BreakDue, $episode->situation);

        $completed = $this->assertSystemLogged('hos.poll.completed');
        $this->assertSame(1, $completed['result']['monitored']);
        $this->assertSame(1, $completed['result']['opened']);
        $this->assertNoSensitiveDataLogged();
        $this->assertStringNotContainsString('Chofer Uno', json_encode($this->systemLogEntries()));
    }

    public function test_a_provider_failure_discards_the_cycle_without_touching_episodes(): void
    {
        $integration = $this->tenant();
        $this->link($integration, '58072405', '281');
        $this->fakeSamsara();
        app()->call([new SyncHosClocksJob($integration), 'handle']);

        Http::fake(['api.samsara.com/fleet/hos/clocks*' => Http::response([], 503)]);
        app()->call([new SyncHosClocksJob($integration), 'handle']);

        $this->assertSame(1, HosEpisode::withoutGlobalScopes()->open()->count());
        $failed = $this->assertSystemLogged('hos.poll.failed');
        $this->assertSame('provider_error', $failed['reason']);
        $this->assertNoSensitiveDataLogged();
    }

    public function test_a_rejected_token_is_logged_as_unauthorized(): void
    {
        $integration = $this->tenant();
        Http::fake(['api.samsara.com/*' => Http::response(['message' => 'invalid token'], 401)]);

        app()->call([new SyncHosClocksJob($integration), 'handle']);

        $this->assertSame('unauthorized', $this->assertSystemLogged('hos.poll.failed')['reason']);
        $this->assertNoSensitiveDataLogged();
    }

    public function test_the_feature_turned_off_skips_the_poll(): void
    {
        $integration = $this->tenant(feature: false);
        Http::fake();

        app()->call([new SyncHosClocksJob($integration), 'handle']);

        Http::assertNothingSent();
        $this->assertSame('feature_disabled', $this->assertSystemLogged('hos.poll.skipped')['reason']);
    }

    public function test_the_orchestrator_only_dispatches_for_tenants_with_the_feature(): void
    {
        Queue::fake();
        $on = $this->tenant();
        $this->tenant(feature: false);

        app()->call([new PollHosClocksJob, 'handle']);

        Queue::assertPushed(SyncHosClocksJob::class, 1);
        Queue::assertPushed(SyncHosClocksJob::class, fn (SyncHosClocksJob $job) => $job->integration->id === $on->id);
        $dispatched = $this->assertSystemLogged('hos.poll.dispatched');
        $this->assertSame(1, $dispatched['result']['dispatched_count']);
        $this->assertSame(1, $dispatched['result']['feature_off_count']);
    }

    public function test_the_same_samsara_ids_in_another_tenant_never_leak(): void
    {
        $integration = $this->tenant();
        $this->link($integration, '58072405', '281');
        $other = $this->tenant();
        [$foreignDriver] = $this->link($other, '58072405', '281');
        $this->fakeSamsara();

        $this->assertNoTenantLeak($other->team_id, fn () => app()->call([new SyncHosClocksJob($integration), 'handle']));

        $this->assertSame(0, HosEpisode::withoutGlobalScopes()->where('team_id', $other->team_id)->count());
        $this->assertSame(1, HosEpisode::withoutGlobalScopes()->where('team_id', $integration->team_id)->count());
        $this->assertSame(0, HosEpisode::withoutGlobalScopes()->where('driver_id', $foreignDriver->id)->count());
    }
}
```


- [ ] **Step 2:** Run `php artisan test --compact --filter=SyncHosClocksJobTest` → FAIL.

- [ ] **Step 3: `SyncHosClocksJob`**

```php
<?php

namespace App\Domains\Drivers\Jobs;

use App\Domains\Drivers\Actions\ProcessHosReadings;
use App\Domains\Drivers\Actions\ResolveHosEnrollment;
use App\Domains\Drivers\Actions\ResolveHosMonitoringConfig;
use App\Domains\Integrations\Contracts\ProviderAdapter;
use App\Domains\Integrations\Exceptions\ProviderRateLimited;
use App\Domains\Integrations\Exceptions\ProviderRequestFailed;
use App\Domains\Integrations\Exceptions\ProviderRequestFailedException;
use App\Domains\Integrations\Exceptions\ProviderUnauthorized;
use App\Domains\Integrations\Models\TenantIntegration;
use App\Support\SystemLog;
use App\Support\TenantContext;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Cache;

/**
 * One HOS poll of one Samsara integration: clocks (+ tags when the tenant
 * enrolls by tag) → enrollment → state and episodes. A failed provider read
 * discards the WHOLE cycle — a partial listing would read as drivers leaving
 * the set and close their episodes. No retries: the next minute polls again.
 */
class SyncHosClocksJob implements ShouldBeUnique, ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 1;

    public int $timeout = 90;

    /** Releases the lock if a worker dies mid-poll. */
    public int $uniqueFor = 120;

    public function __construct(
        public readonly TenantIntegration $integration,
    ) {
        $this->onQueue('telematics');
    }

    public function handle(
        ProviderAdapter $providerAdapter,
        ResolveHosMonitoringConfig $resolveConfig,
        ResolveHosEnrollment $resolveEnrollment,
        ProcessHosReadings $processReadings,
    ): void {
        $teamId = $this->integration->team_id;
        $input = ['team_id' => $teamId, 'integration_id' => $this->integration->id];

        TenantContext::for($teamId, function () use ($providerAdapter, $resolveConfig, $resolveEnrollment, $processReadings, $teamId, $input): void {
            $config = $resolveConfig->execute($teamId);

            if ($config === null) {
                SystemLog::skipped('hos.poll.skipped', reason: 'feature_disabled', input: $input);

                return;
            }

            try {
                $readings = $providerAdapter->fetchHosClocks($this->integration);
                $tags = $config->tagIds === [] ? [] : Cache::remember(
                    "hos:tags:{$teamId}:{$this->integration->id}",
                    (int) config('hos.tags_cache_seconds', 300),
                    fn () => $providerAdapter->fetchTags($this->integration),
                );
            } catch (ProviderRequestFailed|ProviderRequestFailedException $e) {
                SystemLog::degraded('hos.poll.failed', reason: match (true) {
                    $e instanceof ProviderUnauthorized => 'unauthorized',
                    $e instanceof ProviderRateLimited => 'rate_limited',
                    default => 'provider_error',
                }, input: $input, error: $e);

                return;
            }

            $enrollment = $resolveEnrollment->execute($this->integration, $config, $readings, $tags);
            $counts = $processReadings->execute($teamId, $config, $enrollment, now()->toImmutable());

            $skipped = [];

            foreach ($enrollment->skippedByReason as $reason => $count) {
                $skipped["skipped_{$reason}"] = $count;
            }

            SystemLog::ok('hos.poll.completed', input: $input, calc: [
                'readings_count' => count($readings),
                'tags_count' => count($tags),
                'tag_ids_count' => count($config->tagIds),
                'included_count' => count($config->includedAssetIds),
                'excluded_count' => count($config->excludedAssetIds),
            ], result: $counts + $skipped);
        });
    }

    public function uniqueId(): string
    {
        return "hos-clocks-{$this->integration->id}";
    }
}
```

- [ ] **Step 4: `PollHosClocksJob`**

```php
<?php

namespace App\Domains\Drivers\Jobs;

use App\Domains\Drivers\Support\HosMonitoringConfig;
use App\Domains\Integrations\Enums\TenantIntegrationStatus;
use App\Domains\Integrations\Models\TenantIntegration;
use App\Domains\Tenancy\Models\TenantFeature;
use App\Domains\Tenancy\Support\TenantCanSend;
use App\Support\SystemLog;
use App\Support\TenantContext;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

/**
 * Scheduled every minute: fans out a {@see SyncHosClocksJob} for every active
 * Samsara integration whose tenant enabled the `hos_monitoring` feature and
 * can still be served ({@see TenantCanSend}).
 */
class PollHosClocksJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public function __construct()
    {
        $this->onQueue('telematics');
    }

    public function handle(): void
    {
        $counts = ['dispatched_count' => 0, 'feature_off_count' => 0, 'tenant_blocked_count' => 0, 'sync_disabled_count' => 0];

        // Fan-out de plataforma: recorre todos los tenants a propósito y mete
        // cada iteración en el contexto de SU tenant. Ver §2.1.
        TenantContext::withoutTenant(function () use (&$counts): void {
            $enabledTeams = TenantFeature::query()
                ->where('feature_key', HosMonitoringConfig::FEATURE_KEY)
                ->where('enabled', true)
                ->pluck('team_id')
                ->flip();

            TenantIntegration::query()
                ->where('status', TenantIntegrationStatus::Active)
                ->ofLiveTeam()
                ->with('provider')
                ->each(function (TenantIntegration $integration) use (&$counts, $enabledTeams): void {
                    if ($integration->provider?->code !== 'samsara') {
                        return;
                    }

                    if (! $enabledTeams->has($integration->team_id)) {
                        $counts['feature_off_count']++;

                        return;
                    }

                    TenantContext::for($integration->team_id, function () use ($integration, &$counts): void {
                        if (($integration->config_json['sync']['enabled'] ?? true) === false) {
                            $counts['sync_disabled_count']++;

                            return;
                        }

                        if (($blocked = TenantCanSend::blockedReason($integration->team_id)) !== null) {
                            SystemLog::skipped('hos.poll.skipped', reason: 'tenant_blocked', input: [
                                'team_id' => $integration->team_id,
                                'integration_id' => $integration->id,
                            ], calc: ['blocked_reason' => $blocked]);
                            $counts['tenant_blocked_count']++;

                            return;
                        }

                        SyncHosClocksJob::dispatch($integration);
                        $counts['dispatched_count']++;
                    });
                });
        });

        // Recorrido de plataforma: sólo conteos, nunca ids de un tenant.
        SystemLog::ok('hos.poll.dispatched', result: $counts);
    }
}
```

- [ ] **Step 5: Schedule.** En `routes/console.php` añadir el `use App\Domains\Drivers\Jobs\PollHosClocksJob;` (orden alfabético con los demás) y, junto a `PollAllDeviceConnectivityJob`:

```php
// Monitoreo HOS (EE. UU.): relojes de Samsara de los choferes inscritos (spec 2026-10-04).
Schedule::job(new PollHosClocksJob)->everyMinute()->onOneServer();
```

- [ ] **Step 6:** Run `php artisan test --compact --filter='SyncHosClocksJobTest|LoggingConventionsTest'` → PASS.
- [ ] **Step 7: Commit**

```bash
git add app/Domains/Drivers/Jobs routes/console.php tests/Feature/Domains/Drivers/Hos/SyncHosClocksJobTest.php
git commit -m "feat: sondea relojes hos cada minuto por tenant inscrito"
```

---

### Task 8: Gates, revisión y PR

- [ ] **Step 1:** `vendor/bin/pint --dirty --format agent`
- [ ] **Step 2:** `composer analyse` → sin errores nuevos (no tocar `phpstan-baseline.neon`; arreglar la causa).
- [ ] **Step 3:** `php artisan test --compact` (suite completa) → PASS. Si `public/hot` existe en el checkout (dev server), apartarlo para los tests de SSR.
- [ ] **Step 4:** Revisión de aislamiento: lanzar el agente `tenant-isolation-reviewer` sobre la rama y atender lo que reporte.
- [ ] **Step 5:** Validación en vivo (dev, team 5 `sam-pruebas`): `sail artisan migrate`; activar la feature y el setting por tinker (`SetTenantFeature` + `UpdateTenantSetting` con `tag_ids` = `["4738197","7076291"]`); `docker compose restart horizon scheduler`; tras 2–3 min revisar `hos_episodes` y los logs `hos.poll.completed` (esperado: ~13 choferes vigilados, episodios coherentes con lo que muestra Samsara).
- [ ] **Step 6:** Push y PR (`feat: monitoreo hos — pr 1 observación sin envío`), cuerpo con: resumen, desviaciones del spec (sección arriba), pruebas corridas, validación en vivo y el aviso de que no envía nada todavía. Esperar CI (`gh pr checks --watch`) y arreglar lo rojo con commits nuevos.

---

## Siguientes planes

- **PR 2 — Insistencia:** canal `samsara_driver_app` (`POST /v1/fleet/messages`), `RecipientType::Driver`, `NotificationSourceType::HosEpisode`, `HosNoticeCopy`, `AdvanceHosEpisode` (escalera sobre `ladder_step`/`next_nudge_at`, pausada mientras el chofer no esté manejando/en turno), evento interno `hos_violation`/`hos_unattended` → regla por defecto → incidente, autocierre si corrige antes del acuse. Se planea después de validar el PR 1 en vivo.
- **PR 3 — UI:** sección `?seccion=hos`, vista previa del conjunto, panel HOS de chofer y flota.
