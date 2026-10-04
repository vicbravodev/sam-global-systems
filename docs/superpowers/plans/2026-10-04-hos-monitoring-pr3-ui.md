# Monitoreo HOS — PR 3: configuración y panel — Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** El tenant con la feature `hos_monitoring` configura desde Ajustes (`?seccion=hos`) quién entra al monitoreo HOS (etiquetas de Samsara + unidades incluidas/excluidas, con vista previa "13 tractos · 43 choferes entran ahora"), qué situaciones vigila, sus umbrales y la escalera de insistencia; y el equipo de monitoreo ve, en tiempo real, la pestaña **HOS** del chofer (estado, barras de descanso/manejo/turno/ciclo, episodio abierto, historial de avisos con canal y resultado, enlace al incidente) y la vista **HOS (EE. UU.)** con los choferes vigilados del más urgente al más holgado.

**Architecture:** Un `HosMonitoringPolicy` (sobre `HosDriverState`) exige la feature **explícitamente encendida** (`AuthorizeAction::isFeatureEnabled`, opt-in) además de `drivers.view` / `config.view` / `config.manage`. La configuración se guarda con un FormRequest estricto (`UpdateHosMonitoringConfigRequest`) → `SaveHosMonitoringConfig` (forma canónica → `UpdateTenantSetting` grupo compliance → `RecordAuditEntry` → `hos.config.updated`); el endpoint genérico de settings deja de aceptar `hos.monitoring`. Los tags (`ListHosTags`) y la vista previa (`PreviewHosEnrollment`, que corre el **mismo** `ResolveHosEnrollment` sobre la última lectura de relojes que deja `SyncHosClocksJob` en caché) viven en `HosProviderCache`. El panel sale de `BuildHosDriverPanel` (estado + episodios + notificaciones `hos_episode` con sus entregas) y `ListHosFleet` (orden por `HosUrgency`); al cerrar cada sondeo con alguien vigilado, `SyncHosClocksJob` emite `HosClocksUpdatedBroadcast` (`hos.clocks_updated` en `private-accounts.{teamId}`) y las páginas recargan sólo su prop con `useBroadcastReload`.

**Tech Stack:** Laravel 13 · PHP 8.5 · PHPUnit 13 (SQLite) · Inertia v3 · React 19 (React Compiler) · TypeScript estricto · Tailwind v4 · Vitest · Wayfinder.

**Spec:** `docs/superpowers/specs/2026-10-04-hos-monitoring-design.md` (§3.12, §4, §5, §6, §7 — PR 3). Planes anteriores: `docs/superpowers/plans/2026-10-04-hos-monitoring-pr1-observacion.md`, `docs/superpowers/plans/2026-10-04-hos-monitoring-pr2-insistencia.md`.

## Global Constraints

- Tenant = `Team`. Endpoints bajo `{current_team}` (grupo web con `EnsureTeamMembership`; espejo en `routes/api.php`). Actions que reciben `int $teamId` trabajan en `TenantContext::for($teamId, ...)`; toda query lleva `where('team_id', ...)` explícito además del scope. Ningún `withoutGlobalScopes()` nuevo en `app/`.
- **La feature es opt-in**: sin fila `tenant_features` `hos_monitoring` con `enabled = true`, ni la sección, ni la entrada del menú, ni la pestaña, ni la vista de flota, ni los endpoints (403). `AuthorizeAction::checkFeatureAccess` NO sirve aquí: deja pasar cuando falta la fila y deriva el módulo del prefijo del permiso.
- Permisos existentes, sin códigos nuevos: panel = `drivers.view`; leer configuración = `config.view`; guardar = `config.manage`.
- **Validación estricta en el FormRequest** (el resolver `HosMonitoringConfig::fromArray` tolera basura en silencio): booleanos sólo `true/false/1/0/"1"/"0"` (`"false"` se rechaza), enteros sin negativos, umbrales con tope, escalera con primer escalón en 0, escalones crecientes con al menos `EscalationLadder::MIN_GAP_MINUTES` (2) min entre sí, a lo más un escalón de incidente y sólo al final, sin canales en el escalón de incidente, al menos un escalón que avise al chofer; canales sólo `samsara_driver_app`, `whatsapp`, `sms`, `voice`; etiquetas como texto; unidades del team (no borradas) y nunca en ambas listas; fin de recordatorios para retomar mayor que el último recordatorio.
- **Vista previa sin pegarle a Samsara por tecla**: lee la última lectura de relojes del sondeo (caché `hos:clocks:{team}:{integración}`, 180 s) y los tags (caché `hos:tags:{team}:{integración}`, `hos.tags_cache_seconds`). Sólo con caché fría lee una vez y la guarda. El cliente espera 600 ms sin cambios antes de pedirla y cancela la anterior.
- **Copy** en español de México, de tú, sin jerga en inglés, con el vocabulario de `HosNoticeCopy`: descanso de 30 min, 11 h de manejo, turno de 14 h, ciclo de 70 h, infracción, retomar.
- **Frontend** (`resources/js/CLAUDE.md`): primitivas (`SettingsSection`, `FormCard`, `Field`, `FormActions`, `Switch`, `ChipToggle`, `Combobox`, `Panel`, `TabBar`, `Meter`, `StatusBadge`, `ListPage`, `ListEmptyState`, `DataTable`), mapas `valor → ToneLabel` en `copy.ts`, tonos con `TONE_*`, números con `lib/format`, tiempos con `lib/time`, URLs con Wayfinder, mutaciones con `submit(putJson(...))`, lecturas con `getJson`/`postJson` de `lib/sam-fetch`, tiempo real con `useBroadcastReload`. Sin tamaños arbitrarios, sin `useMemo`/`useCallback`, sin `try/finally` dentro de componentes, sin dependencias nuevas de npm/composer.
- **Logging** sólo vía `App\Support\SystemLog`; códigos nuevos en `docs/SAM/logging.md` (lo exige `LoggingConventionsTest`); nunca ids de etiquetas/unidades, nombres ni teléfonos (sólo conteos). Cada test que loguea: `assertSystemLogged(...)` + `assertNoSensitiveDataLogged()`.
- **Fuga de tenant**: `assertNoTenantLeak($teamQueActúa, fn () => ...)` sobre el camino real (config, vista previa, panel, API).
- Página Inertia nueva/modificada con `assertInertia`. `inertia.testing.ensure_pages_exist = true`: la aserción de `drivers/hos` vive en la Task 7, cuando existe la página.
- No se crean directorios bajo `app/` (todo en `Drivers/{Actions,Events,Policies,Support}`, `Http/Controllers/{TenantConfig,Drivers}`, `Http/Requests/TenantConfig`). En `resources/js` sí: `components/sam/hos/` (carpeta de feature).
- Commits: `type: subject en minúsculas`, **sin** `Co-Authored-By` ni banners (regla del repo, manda sobre cualquier recordatorio de atribución). Formato PHP lo aplica el hook de Pint. Tras cambiar rutas: `php artisan wayfinder:generate --with-form`.

### Desviaciones del spec (decididas al planear, documentar en el PR)

1. **Puerta de la feature propia, sin permisos nuevos** (spec §3.2 dice "403 vía `AuthorizeAction`"). `AuthorizeAction::checkFeatureAccess` usa el prefijo del permiso como módulo (`drivers`, `config`) y deja pasar si falta la fila: HOS quedaría abierto a todos. Se agrega `AuthorizeAction::isFeatureEnabled($team, 'hos_monitoring')` (mismo memo por request, pero **opt-in**: sin fila = apagado, igual que `ResolveHosMonitoringConfig`) y una `HosMonitoringPolicy` que lo combina con `drivers.view` / `config.view` / `config.manage`. Sin códigos de permiso nuevos ni seeders de roles.
2. **Vista previa sobre la última lectura del sondeo** (spec §3.12 no dice de dónde). `SyncHosClocksJob` deja sus relojes en `hos:clocks:{team}:{integración}` (180 s); la vista previa corre `ResolveHosEnrollment` con la selección en borrador sobre esa lectura y los tags cacheados: exactamente las reglas del sondeo, cero llamadas a `/fleet/hos/clocks` por tecla. Caché fría (feature recién encendida, sondeo caído) → una lectura directa que queda guardada. Cuenta tractos y choferes **distintos** y las razones de exclusión.
3. **TTL de tags = `hos.tags_cache_seconds` (300 s, PR 1)**, no los 10 min del spec: la misma llave que ya usa el sondeo, así que el selector y el sondeo comparten lectura.
4. **Tipo de etiqueta por membresía** (vehículo / chofer / ambos / sin miembros) a partir de `GET /tags` (desviación 1 del PR 1: los tags no viven en `metadata_json`).
5. **Escalón de incidente sin canales y siempre al final; el 0 del límite lo agrega la UI**. La pantalla edita sólo los avisos previos positivos y al guardar agrega el `0` (forma de `config/hos.php`). `rest_complete_expire_minutes` también se edita ("Dejar de recordar a los") y debe ser mayor que el último recordatorio (si no, ese recordatorio nunca sale — regla implícita del PR 2).
6. **Vista de flota bajo Conductores** (`/{team}/drivers/hos`, entrada "HOS (EE. UU.)" en Recursos), no dentro de las páginas de Flota (activos): la fila es un chofer. Muestra estados leídos en los últimos 30 min (`hos_driver_states` no se borra cuando un chofer sale del conjunto) y marca "sin lectura reciente" pasados 3 min.
7. **Broadcast sólo si hubo alguien vigilado o alguien salió** en el ciclo (`monitored + unenrolled > 0`), payload mínimo (`monitored`, `observed_at`); el refresco es recarga parcial (`useBroadcastReload`, con su debounce, pestaña oculta y resincronización), no polling. Se elige broadcast porque la infraestructura ya existe y cuesta un mensaje por tenant por minuto.
8. **Historial de avisos sin tabla nueva**: sale de `notifications` (`source_type = hos_episode`, `source_reference_id = episodio`) y sus `notification_deliveries` (canal y estado).
9. **Pestañas en el detalle del chofer** ("Resumen" | "HOS") sólo cuando el panel aplica; enlace directo `?pestana=hos` desde la vista de flota.
10. **Sin Playwright**: `resources/js/CLAUDE.md` reserva el E2E para flujos que dejan un pánico sin atender o a un cliente sin operar; aquí cubren PHPUnit (`assertInertia`, policy, fuga) y Vitest (validación, vista previa, barras).
11. **Seguimiento del PR 2 incluido** (sólo validación de UI/config): `samsara_driver_app` se rechaza en políticas de avisos del tenant y en preferencias (web y API; la API aceptaba cualquier texto); `hos.monitoring` ya no se puede escribir por el endpoint genérico de settings y "Avanzado" no lo lista.

## Review Focus

1. **HOS visible para un tenant sin la feature** (por el fail-open de `AuthorizeAction` o por un endpoint que olvide la policy). → Task 1 `test_without_the_feature_nobody_sees_hos_not_even_an_admin`, Task 2 `test_without_the_feature_the_form_is_null_and_the_endpoints_answer_403`, Task 5 `test_the_api_answers_403_without_the_feature`.
2. **Configuración basura que el resolver acepta en silencio** (`"false"`, negativos, escalones desordenados, incidente a la mitad, guardar por el endpoint genérico). → Task 2 `test_invalid_values_are_rejected_without_saving` (dataset) y `test_the_generic_settings_endpoint_cannot_write_the_hos_config`; Vitest `config-lib.test.ts` (mismas reglas y llaves en el cliente).
3. **Vista previa que martilla Samsara o que no usa las reglas reales**. → Task 3 `test_the_preview_counts_with_the_last_poll_without_reading_the_clocks_again`, `test_with_a_cold_cache_it_reads_the_clocks_once_and_keeps_them`, `test_excluding_the_unit_takes_the_driver_out`; Vitest `use-hos-preview.test.ts` (una sola petición tras el debounce).
4. **Cruce de tenants** (unidad ajena en "siempre entran", mismo id externo de Samsara en dos tenants, panel/flota/API mostrando choferes ajenos). → Task 2 `test_units_must_belong_to_the_team_and_cannot_be_both_included_and_excluded` y `test_saving_never_touches_another_tenant`, Task 3 `test_the_preview_never_reads_another_tenant`, Task 5 `test_the_fleet_and_panel_never_show_another_tenant`.
5. **Panel que miente o no se refresca** (orden de urgencia equivocado, chofer parado con manejo en 0 marcado "en el límite", broadcast a otro canal o en cada ciclo vacío). → Task 5 `test_the_fleet_orders_violation_then_at_limit_then_lowest_remaining` y `HosUrgencyTest`, Task 6 `test_each_poll_tells_the_tenant_panels_to_refresh` y `test_a_poll_with_nobody_monitored_broadcasts_nothing`.

---

## File Structure

| Archivo | Responsabilidad |
|---|---|
| `app/Domains/Access/Actions/AuthorizeAction.php` (mod) | `isFeatureEnabled()` opt-in |
| `app/Domains/Drivers/Policies/HosMonitoringPolicy.php` | `viewAny` / `viewConfig` / `updateConfig` |
| `app/Domains/Drivers/DriversServiceProvider.php` (mod) | registra la policy |
| `app/Http/Middleware/HandleInertiaRequests.php` (mod), `resources/js/types/sam.ts` (mod) | `nav.hos`, `nav.hosConfig` |
| `app/Domains/Drivers/Support/HosMonitoringConfig.php` (mod) | `DRIVER_CHANNELS`, `CONFIGURABLE_SITUATIONS` |
| `app/Http/Requests/TenantConfig/UpdateHosMonitoringConfigRequest.php` | validación estricta |
| `app/Http/Requests/TenantConfig/PreviewHosEnrollmentRequest.php` | selección de la vista previa |
| `app/Domains/Drivers/Actions/SaveHosMonitoringConfig.php` | forma canónica + setting + auditoría + log |
| `app/Domains/Drivers/Actions/BuildHosConfigForm.php` | props de la sección |
| `app/Domains/Drivers/Support/HosProviderCache.php` | integraciones, tags y última lectura cacheados |
| `app/Domains/Drivers/Support/HosTagOptions.php` | árbol de etiquetas para el selector |
| `app/Domains/Drivers/Actions/ListHosTags.php`, `PreviewHosEnrollment.php` | tags y vista previa |
| `app/Http/Controllers/TenantConfig/HosMonitoringConfigController.php` | show/update/tags/preview (web + API) |
| `app/Http/Controllers/TenantConfig/TenantConfigPageController.php` (mod) | prop `hos` |
| `app/Http/Requests/TenantConfig/UpdateTenantSettingsRequest.php`, `UpdateTenantNotificationPoliciesRequest.php`, `app/Http/Controllers/Settings/NotificationPreferencesController.php`, `app/Http/Controllers/Notifications/NotificationPreferenceController.php` (mod) | seguimientos del PR 2 |
| `app/Domains/Drivers/Jobs/SyncHosClocksJob.php` (mod) | caché de la lectura + broadcast |
| `app/Domains/Drivers/Models/HosDriverState.php` (mod) | `clockSnapshot()` |
| `app/Domains/Drivers/Support/HosUrgency.php` | nivel y orden de urgencia |
| `app/Domains/Drivers/Actions/BuildHosDriverPanel.php`, `ListHosFleet.php` | datos del panel y de la flota |
| `app/Http/Controllers/Drivers/HosPanelController.php`, `DriverPageController.php` (mod) | página de flota, espejo API, prop `hos` del chofer |
| `app/Domains/Drivers/Events/HosClocksUpdatedBroadcast.php` | `hos.clocks_updated` |
| `routes/web.php`, `routes/api.php` (mod) | rutas |
| `docs/SAM/logging.md` (mod) | `hos.config.updated`, `hos.tags.listed`, `hos.preview.computed`, `broadcast` en `hos.poll.completed` |
| `resources/js/types/hos.ts`, `types/drivers.ts` (mod), `types/realtime.ts` (mod), `hooks/use-team-broadcasts.ts` (mod) | tipos y evento |
| `resources/js/lib/time.ts` (+ test) | `hoursMinutesLabel` |
| `resources/js/components/sam/hos/{copy.ts,config-lib.ts,config-lib.test.ts,use-hos-preview.ts,use-hos-preview.test.ts,use-hos-tags.ts,hos-tag-picker.tsx,hos-asset-picker.tsx,hos-ladder-editor.tsx}` | configuración |
| `resources/js/components/sam/hos/{lib.ts,lib.test.ts,hos-status-badge.tsx,hos-clock-bars.tsx,hos-episode-list.tsx,hos-driver-panel.tsx,hos-fleet-table.tsx}` | panel |
| `resources/js/components/sam/settings/tenant-config/{hos-section.tsx,types.ts,settings-catalog.ts}`, `components/sam/settings/use-settings-nav.ts`, `pages/settings/tenant-config.tsx` (mod) | sección `?seccion=hos` |
| `resources/js/pages/drivers/show.tsx` (mod), `pages/drivers/hos.tsx`, `components/sam/ops-sidebar.tsx` (mod) | pestaña, vista de flota, menú |
| `tests/Feature/Domains/Drivers/Hos/{HosMonitoringPolicyTest,HosMonitoringConfigTest,HosEnrollmentPreviewTest,HosPanelTest}.php`, `SyncHosClocksJobTest.php` (mod), `tests/Unit/Domains/Drivers/{HosTagOptionsTest,HosUrgencyTest}.php`, `tests/Feature/Domains/Notifications/DriverAppChannelNotOfferedTest.php`, `tests/Feature/Http/NavPermissionsSharedPropTest.php` (mod) | pruebas |

---

### Task 0: Bootstrap del worktree

- [ ] **Step 1:** Invocar la skill `worktree-bootstrap` (vendor, `.env`, Wayfinder). `composer install` **real** en el worktree (con `vendor` symlinkeado los tests cargan `App\` del checkout principal y RED/GREEN miente) y `npm install` si falta `node_modules`.
- [ ] **Step 2:** Línea base: `php artisan test --compact tests/Feature/Domains/Drivers tests/Unit/Domains/Drivers tests/Feature/Domains/TenantConfig tests/Feature/Http/NavPermissionsSharedPropTest.php tests/Feature/Architecture` → PASS. `npm test` → PASS.

---

### Task 1: Puerta de HOS atada a la feature

**Files:**
- Modify: `app/Domains/Access/Actions/AuthorizeAction.php`, `app/Domains/Drivers/DriversServiceProvider.php`, `app/Http/Middleware/HandleInertiaRequests.php`, `resources/js/types/sam.ts`
- Create: `app/Domains/Drivers/Policies/HosMonitoringPolicy.php`
- Test: `tests/Feature/Domains/Drivers/Hos/HosMonitoringPolicyTest.php`, `tests/Feature/Http/NavPermissionsSharedPropTest.php` (una aserción)

**Interfaces:**
- Produces: `AuthorizeAction::isFeatureEnabled(Team $team, string $featureKey): bool` (sin fila = `false`, memo por request; `forgetTeamAccess()` lo invalida). Gate: `viewAny` (panel, `drivers.view`), `viewConfig` (`config.view`), `updateConfig` (`config.manage`) sobre `HosDriverState::class`, todas exigen la feature. Prop compartida `nav.hos` / `nav.hosConfig` (`NavPermissions.hos`, `NavPermissions.hosConfig`).

- [ ] **Step 1: Test que falla.** `tests/Feature/Domains/Drivers/Hos/HosMonitoringPolicyTest.php`:

```php
<?php

namespace Tests\Feature\Domains\Drivers\Hos;

use App\Domains\Access\Actions\AuthorizeAction;
use App\Domains\Access\Enums\RoleScope;
use App\Domains\Access\Models\Permission;
use App\Domains\Access\Models\Role;
use App\Domains\Drivers\Models\HosDriverState;
use App\Domains\Drivers\Support\HosMonitoringConfig;
use App\Domains\Tenancy\Models\TenantFeature;
use App\Enums\TeamRole;
use App\Models\Team;
use App\Models\User;
use Database\Seeders\AccessSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Gate;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class HosMonitoringPolicyTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(AccessSeeder::class);
    }

    private function enable(Team $team, bool $enabled = true): void
    {
        TenantFeature::factory()->create(['team_id' => $team->id, 'feature_key' => HosMonitoringConfig::FEATURE_KEY, 'enabled' => $enabled]);
        app(AuthorizeAction::class)->forgetTeamAccess($team->id);
    }

    /**
     * @param  list<string>  $permissionCodes
     * @return array{0: User, 1: Team}
     */
    private function userWithRole(array $permissionCodes): array
    {
        $user = User::factory()->create();
        $team = $user->currentTeam;
        $role = Role::factory()->create(['code' => 'hos_role_'.$user->id, 'scope' => RoleScope::Tenant]);
        $role->permissions()->sync(array_map(fn (string $code): int => Permission::firstOrCreate(
            ['code' => $code],
            ['name' => $code, 'module' => explode('.', $code, 2)[0]],
        )->id, $permissionCodes));
        $team->members()->updateExistingPivot($user->id, ['role' => TeamRole::Member->value, 'role_id' => $role->id]);

        return [$user, $team];
    }

    public function test_without_the_feature_nobody_sees_hos_not_even_an_admin(): void
    {
        $owner = User::factory()->create();
        $this->actingAs($owner);

        $this->assertFalse(Gate::allows('viewAny', HosDriverState::class));
        $this->assertFalse(Gate::allows('viewConfig', HosDriverState::class));
        $this->assertFalse(Gate::allows('updateConfig', HosDriverState::class));

        // Una fila apagada tampoco cuenta.
        $this->enable($owner->currentTeam, enabled: false);

        $this->assertFalse(Gate::allows('viewAny', HosDriverState::class));
    }

    public function test_with_the_feature_each_ability_follows_its_permission(): void
    {
        [$user, $team] = $this->userWithRole(['drivers.view', 'config.view']);
        $this->enable($team);
        $this->actingAs($user);

        $this->assertTrue(Gate::allows('viewAny', HosDriverState::class));
        $this->assertTrue(Gate::allows('viewConfig', HosDriverState::class));
        $this->assertFalse(Gate::allows('updateConfig', HosDriverState::class));
    }

    public function test_the_feature_of_another_team_does_not_count(): void
    {
        $owner = User::factory()->create();
        $this->enable(Team::factory()->create());
        $this->actingAs($owner);

        $this->assertFalse(Gate::allows('viewAny', HosDriverState::class));
    }

    public function test_the_nav_map_carries_hos_only_with_the_feature(): void
    {
        $owner = User::factory()->create();
        $team = $owner->currentTeam;

        $this->actingAs($owner)
            ->get(route('dashboard', ['current_team' => $team->slug]))
            ->assertInertia(fn (Assert $page) => $page->where('nav.hos', false)->where('nav.hosConfig', false));

        $this->enable($team);

        $this->actingAs($owner)
            ->get(route('dashboard', ['current_team' => $team->slug]))
            ->assertInertia(fn (Assert $page) => $page->where('nav.hos', true)->where('nav.hosConfig', true));
    }
}
```

En `tests/Feature/Http/NavPermissionsSharedPropTest.php`, dentro de `test_nav_map_reflects_the_role_permissions`, agregar a la cadena `->where('nav.hos', false)` (rol con `drivers.view` pero sin la feature).

- [ ] **Step 2:** Run `php artisan test --compact --filter='HosMonitoringPolicyTest|NavPermissionsSharedPropTest'` → FAIL (no hay policy: `Gate::allows` sin policy devuelve false para todo, así que fallan `test_with_the_feature_*` y la aserción `nav.hos` no existe).

- [ ] **Step 3: `AuthorizeAction`.** Agregar después de `forgetTeamAccess()`:

```php
    /**
     * Opt-in modules (e.g. `hos_monitoring`): on ONLY with an explicit
     * enabled row. Unlike {@see checkFeatureAccess()}, a missing row means
     * OFF. Shares the per-request memo of the feature check.
     */
    public function isFeatureEnabled(Team $team, string $featureKey): bool
    {
        return $this->teamAccess($team)['features'][$featureKey] ?? false;
    }
```

- [ ] **Step 4: Policy** `app/Domains/Drivers/Policies/HosMonitoringPolicy.php`:

```php
<?php

namespace App\Domains\Drivers\Policies;

use App\Domains\Access\Actions\AuthorizeAction;
use App\Domains\Drivers\Support\HosMonitoringConfig;
use App\Models\User;

/**
 * Monitoreo HOS (EE. UU.): todo exige la feature `hos_monitoring`
 * encendida EXPLÍCITAMENTE para el team actual (la enciende el
 * super-admin) y, encima, el permiso existente de cada superficie:
 * panel = `drivers.view`, leer la configuración = `config.view`,
 * guardarla = `config.manage`. Registrada sobre `HosDriverState`.
 */
class HosMonitoringPolicy
{
    public function __construct(
        private readonly AuthorizeAction $authorizeAction,
    ) {}

    public function viewAny(User $user): bool
    {
        return $this->allowed($user, 'drivers.view');
    }

    public function viewConfig(User $user): bool
    {
        return $this->allowed($user, 'config.view');
    }

    public function updateConfig(User $user): bool
    {
        return $this->allowed($user, 'config.manage');
    }

    private function allowed(User $user, string $permission): bool
    {
        $team = currentTeam();

        return $team !== null
            && $this->authorizeAction->isFeatureEnabled($team, HosMonitoringConfig::FEATURE_KEY)
            && $this->authorizeAction->execute($user, $permission, $team);
    }
}
```

- [ ] **Step 5: Registro y nav.** En `DriversServiceProvider::boot()`:

```php
        Gate::policy(HosDriverState::class, HosMonitoringPolicy::class);
```

(con `use App\Domains\Drivers\Models\HosDriverState;` y `use App\Domains\Drivers\Policies\HosMonitoringPolicy;`). En `HandleInertiaRequests::navPermissions()` agregar (con `use App\Domains\Drivers\Models\HosDriverState;`):

```php
            'hos' => $gate->allows('viewAny', HosDriverState::class),
            'hosConfig' => $gate->allows('viewConfig', HosDriverState::class),
```

y el `@return` del método pasa a incluirlos (`array<string, bool>` ya los cubre). En `resources/js/types/sam.ts`, dentro de `NavPermissions`:

```ts
    /** Monitoreo HOS: feature `hos_monitoring` + `drivers.view`. */
    hos: boolean;
    /** Sección HOS de la configuración: feature + `config.view`. */
    hosConfig: boolean;
```

- [ ] **Step 6:** Run `php artisan test --compact --filter='HosMonitoringPolicyTest|NavPermissionsSharedPropTest'` → PASS. `npm run types:check` → PASS.
- [ ] **Step 7: Commit** `feat: permisos de hos atados a la feature hos_monitoring`

---

### Task 2: Guardar la configuración HOS (validación, auditoría, espejo API) y seguimientos del PR 2

**Files:**
- Modify: `app/Domains/Drivers/Support/HosMonitoringConfig.php`, `app/Http/Controllers/TenantConfig/TenantConfigPageController.php`, `app/Http/Requests/TenantConfig/UpdateTenantSettingsRequest.php`, `app/Http/Requests/TenantConfig/UpdateTenantNotificationPoliciesRequest.php`, `app/Http/Controllers/Settings/NotificationPreferencesController.php`, `app/Http/Controllers/Notifications/NotificationPreferenceController.php`, `routes/web.php`, `routes/api.php`, `docs/SAM/logging.md`
- Create: `app/Http/Requests/TenantConfig/UpdateHosMonitoringConfigRequest.php`, `app/Domains/Drivers/Actions/SaveHosMonitoringConfig.php`, `app/Domains/Drivers/Actions/BuildHosConfigForm.php`, `app/Http/Controllers/TenantConfig/HosMonitoringConfigController.php`
- Test: `tests/Feature/Domains/Drivers/Hos/HosMonitoringConfigTest.php`, `tests/Feature/Domains/Notifications/DriverAppChannelNotOfferedTest.php`

**Interfaces:**
- Consumes: `HosMonitoringPolicy` (Task 1), `UpdateTenantSetting`, `RecordAuditEntry`, `ResolveHosMonitoringConfig`, `EscalationLadder::MIN_GAP_MINUTES`, `NotificationChannel::usableByTeam()`.
- Produces: `HosMonitoringConfig::DRIVER_CHANNELS`, `HosMonitoringConfig::CONFIGURABLE_SITUATIONS`; `UpdateHosMonitoringConfigRequest::teamAssetRule(mixed $team): Exists`; `SaveHosMonitoringConfig::execute(int $teamId, array $validated, ?int $actorId, ?string $ip = null, ?string $userAgent = null): TenantSetting` y `::canonical(array): array`; `BuildHosConfigForm::execute(int $teamId, bool $canManage): array` con forma `{config, defaults, assets, channels, hasIntegration, canManage, minGapMinutes}` y `::present(HosMonitoringConfig): array`; rutas `tenant-config.hos.update` (PUT web), `api.tenant-config.hos.show` (GET), `api.tenant-config.hos.update` (PUT); prop Inertia `hos` en `settings/tenant-config` (null sin `viewConfig`).

- [ ] **Step 1: Tests que fallan.** `tests/Feature/Domains/Drivers/Hos/HosMonitoringConfigTest.php`:

```php
<?php

namespace Tests\Feature\Domains\Drivers\Hos;

use App\Domains\Access\Enums\RoleScope;
use App\Domains\Access\Models\Permission;
use App\Domains\Access\Models\Role;
use App\Domains\Assets\Models\Asset;
use App\Domains\Audit\Models\AuditLog;
use App\Domains\Drivers\Actions\ResolveHosMonitoringConfig;
use App\Domains\Drivers\Enums\HosSituation;
use App\Domains\Drivers\Support\HosMonitoringConfig;
use App\Domains\Notifications\Models\NotificationChannel;
use App\Domains\Tenancy\Models\TenantFeature;
use App\Domains\TenantConfig\Enums\SettingGroup;
use App\Domains\TenantConfig\Enums\SettingValueType;
use App\Domains\TenantConfig\Models\TenantConfigVersion;
use App\Domains\TenantConfig\Models\TenantSetting;
use App\Enums\TeamRole;
use App\Models\Team;
use App\Models\User;
use Database\Seeders\AccessSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Concerns\AssertsSystemLog;
use Tests\Concerns\AssertsTenantIsolation;
use Tests\TestCase;

class HosMonitoringConfigTest extends TestCase
{
    use AssertsSystemLog, AssertsTenantIsolation, RefreshDatabase;

    private User $user;

    private Team $team;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(AccessSeeder::class);
        $this->user = User::factory()->create();
        $this->team = $this->user->currentTeam;
        $this->enable($this->team);
    }

    private function enable(Team $team): void
    {
        TenantFeature::factory()->create(['team_id' => $team->id, 'feature_key' => HosMonitoringConfig::FEATURE_KEY, 'enabled' => true]);
    }

    /**
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    private function payload(array $overrides = []): array
    {
        return array_replace([
            'tag_ids' => ['4738197'],
            'included_asset_ids' => [],
            'excluded_asset_ids' => [],
            'situations' => ['break_due' => true, 'drive_limit' => true, 'shift_limit' => true, 'cycle_limit' => false, 'rest_complete' => true],
            'lead_minutes' => [45, 15, 0],
            'cycle_lead_hours' => [6, 2],
            'rest_complete_nudge_minutes' => [10, 25],
            'rest_complete_expire_minutes' => 30,
            'ladder' => [
                ['after_minutes' => 0, 'channels' => ['samsara_driver_app'], 'escalate' => null],
                ['after_minutes' => 4, 'channels' => ['samsara_driver_app', 'sms'], 'escalate' => null],
                ['after_minutes' => 8, 'channels' => [], 'escalate' => 'incident'],
            ],
        ], $overrides);
    }

    private function url(): string
    {
        return route('tenant-config.hos.update', ['current_team' => $this->team->slug]);
    }

    /**
     * @param  list<string>  $permissionCodes
     * @return array{0: User, 1: Team}
     */
    private function userWithRole(array $permissionCodes): array
    {
        $user = User::factory()->create();
        $team = $user->currentTeam;
        $role = Role::factory()->create(['code' => 'hos_cfg_'.$user->id, 'scope' => RoleScope::Tenant]);
        $role->permissions()->sync(array_map(fn (string $code): int => Permission::firstOrCreate(
            ['code' => $code],
            ['name' => $code, 'module' => explode('.', $code, 2)[0]],
        )->id, $permissionCodes));
        $team->members()->updateExistingPivot($user->id, ['role' => TeamRole::Member->value, 'role_id' => $role->id]);

        return [$user, $team];
    }

    public function test_the_settings_page_carries_the_hos_form_with_the_feature(): void
    {
        $asset = Asset::factory()->create(['team_id' => $this->team->id, 'name' => 'T-0321']);
        NotificationChannel::factory()->samsaraDriverApp()->create();

        $this->actingAs($this->user)
            ->get(route('tenant-config.show', ['current_team' => $this->team->slug, 'seccion' => 'hos']))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('settings/tenant-config')
                ->where('hos.canManage', true)
                ->where('hos.hasIntegration', false)
                ->where('hos.minGapMinutes', 2)
                ->where('hos.config.leadMinutes', [30, 15])
                ->where('hos.config.situations.cycle_limit', true)
                ->where('hos.config.ladder.3', ['afterMinutes' => 15, 'channels' => [], 'escalate' => true])
                ->where('hos.assets.0', ['id' => $asset->id, 'name' => 'T-0321', 'code' => $asset->code, 'monitored' => true])
                ->where('hos.channels', fn ($channels) => collect($channels)->firstWhere('value', 'samsara_driver_app')['available'] === true
                    && collect($channels)->firstWhere('value', 'voice')['available'] === false)
            );
    }

    public function test_without_the_feature_the_form_is_null_and_the_endpoints_answer_403(): void
    {
        TenantFeature::withoutGlobalScopes()->where('team_id', $this->team->id)->update(['enabled' => false]);

        $this->actingAs($this->user)
            ->get(route('tenant-config.show', ['current_team' => $this->team->slug, 'seccion' => 'hos']))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page->where('hos', null));

        $this->actingAs($this->user)->putJson($this->url(), $this->payload())->assertForbidden();
        $this->actingAs($this->user)->getJson("/api/{$this->team->slug}/settings/hos")->assertForbidden();
        $this->actingAs($this->user)->putJson("/api/{$this->team->slug}/settings/hos", $this->payload())->assertForbidden();
        $this->assertDatabaseMissing('tenant_settings', ['setting_key' => HosMonitoringConfig::SETTING_KEY]);
    }

    public function test_a_viewer_reads_the_form_but_cannot_save(): void
    {
        [$viewer, $team] = $this->userWithRole(['config.view']);
        $this->enable($team);

        $this->actingAs($viewer)
            ->get(route('tenant-config.show', ['current_team' => $team->slug, 'seccion' => 'hos']))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page->where('hos.canManage', false));

        $this->actingAs($viewer)
            ->putJson(route('tenant-config.hos.update', ['current_team' => $team->slug]), $this->payload())
            ->assertForbidden();
    }

    public function test_saving_stores_the_canonical_config_audits_and_logs_it(): void
    {
        $this->actingAs($this->user)
            ->putJson($this->url(), $this->payload(['lead_minutes' => ['15', 45, 0]]))
            ->assertOk()
            ->assertJsonPath('data.config.leadMinutes', [45, 15])
            ->assertJsonPath('data.config.situations.cycle_limit', false);

        $setting = TenantSetting::withoutGlobalScopes()
            ->where('team_id', $this->team->id)
            ->where('setting_key', HosMonitoringConfig::SETTING_KEY)
            ->sole();
        $this->assertSame(SettingGroup::Compliance, $setting->setting_group);
        $this->assertSame([45, 15, 0], $setting->value_json['lead_minutes']);
        $this->assertSame(['after_minutes' => 4, 'channels' => ['samsara_driver_app', 'sms']], $setting->value_json['ladder'][1]);
        $this->assertSame(['after_minutes' => 8, 'escalate' => 'incident'], $setting->value_json['ladder'][2]);
        $this->assertFalse($setting->value_json['situations']['cycle_limit']);

        // Lo que guarda la pantalla es lo que lee el sondeo.
        $config = app(ResolveHosMonitoringConfig::class)->execute($this->team->id);
        $this->assertSame(['4738197'], $config->tagIds);
        $this->assertSame(2700, $config->leadSeconds());
        $this->assertFalse($config->enabled(HosSituation::CycleLimit));

        $this->assertSame(1, AuditLog::withoutGlobalScopes()->where('team_id', $this->team->id)->where('action', 'hos.config.updated')->where('actor_id', $this->user->id)->count());
        $this->assertTrue(TenantConfigVersion::withoutGlobalScopes()->where('team_id', $this->team->id)->exists());

        $log = $this->assertSystemLogged('hos.config.updated');
        $this->assertSame(1, $log['calc']['tag_ids_count']);
        $this->assertSame(3, $log['calc']['ladder_steps_count']);
        $this->assertTrue($log['calc']['ladder_escalates']);
        $this->assertNoSensitiveDataLogged();
    }

    public function test_each_save_leaves_its_own_audit_entry(): void
    {
        $this->actingAs($this->user)->putJson($this->url(), $this->payload())->assertOk();
        $this->actingAs($this->user)->putJson($this->url(), $this->payload(['tag_ids' => []]))->assertOk();

        $this->assertSame(2, AuditLog::withoutGlobalScopes()->where('team_id', $this->team->id)->where('action', 'hos.config.updated')->count());
    }

    /**
     * @return array<string, array{0: array<string, mixed>, 1: string}>
     */
    public static function invalidPayloads(): array
    {
        $situations = ['break_due' => true, 'drive_limit' => true, 'shift_limit' => true, 'cycle_limit' => true, 'rest_complete' => true];

        return [
            'switch como texto' => [['situations' => ['break_due' => 'false'] + $situations], 'situations.break_due'],
            'situación desconocida' => [['situations' => $situations + ['violation' => false]], 'situations'],
            'minutos negativos' => [['lead_minutes' => [30, -5, 0]], 'lead_minutes.1'],
            'horas de ciclo en cero' => [['cycle_lead_hours' => [0]], 'cycle_lead_hours.0'],
            'etiqueta como número' => [['tag_ids' => [4738197]], 'tag_ids.0'],
            'canal que el chofer no tiene' => [['ladder' => [['after_minutes' => 0, 'channels' => ['email'], 'escalate' => null]]], 'ladder.0.channels.0'],
            'primer escalón fuera de cero' => [['ladder' => [['after_minutes' => 3, 'channels' => ['sms'], 'escalate' => null]]], 'ladder.0.after_minutes'],
            'escalones a menos de 2 min' => [['ladder' => [
                ['after_minutes' => 0, 'channels' => ['sms'], 'escalate' => null],
                ['after_minutes' => 1, 'channels' => ['voice'], 'escalate' => null],
            ]], 'ladder.1.after_minutes'],
            'escalones desordenados' => [['ladder' => [
                ['after_minutes' => 0, 'channels' => ['sms'], 'escalate' => null],
                ['after_minutes' => 10, 'channels' => ['voice'], 'escalate' => null],
                ['after_minutes' => 5, 'channels' => ['whatsapp'], 'escalate' => null],
            ]], 'ladder.2.after_minutes'],
            'incidente a la mitad' => [['ladder' => [
                ['after_minutes' => 0, 'channels' => ['sms'], 'escalate' => null],
                ['after_minutes' => 5, 'channels' => [], 'escalate' => 'incident'],
                ['after_minutes' => 10, 'channels' => ['voice'], 'escalate' => null],
            ]], 'ladder.1.escalate'],
            'dos incidentes' => [['ladder' => [
                ['after_minutes' => 0, 'channels' => ['sms'], 'escalate' => null],
                ['after_minutes' => 5, 'channels' => [], 'escalate' => 'incident'],
                ['after_minutes' => 10, 'channels' => [], 'escalate' => 'incident'],
            ]], 'ladder.1.escalate'],
            'incidente con canales' => [['ladder' => [
                ['after_minutes' => 0, 'channels' => ['sms'], 'escalate' => null],
                ['after_minutes' => 5, 'channels' => ['voice'], 'escalate' => 'incident'],
            ]], 'ladder.1.channels'],
            'escalón sin canales' => [['ladder' => [
                ['after_minutes' => 0, 'channels' => ['sms'], 'escalate' => null],
                ['after_minutes' => 5, 'channels' => [], 'escalate' => null],
            ]], 'ladder.1.channels'],
            'sólo incidente' => [['ladder' => [['after_minutes' => 0, 'channels' => [], 'escalate' => 'incident']]], 'ladder'],
            'llave extra en un escalón' => [['ladder' => [['after_minutes' => 0, 'channels' => ['sms'], 'escalate' => null, 'retry_minutes' => 3]]], 'ladder.0'],
            'fin de recordatorios antes del último' => [['rest_complete_nudge_minutes' => [15, 30], 'rest_complete_expire_minutes' => 30], 'rest_complete_expire_minutes'],
        ];
    }

    /**
     * @param  array<string, mixed>  $overrides
     */
    #[DataProvider('invalidPayloads')]
    public function test_invalid_values_are_rejected_without_saving(array $overrides, string $errorKey): void
    {
        $this->actingAs($this->user)
            ->putJson($this->url(), $this->payload($overrides))
            ->assertUnprocessable()
            ->assertJsonValidationErrors([$errorKey]);

        $this->assertDatabaseMissing('tenant_settings', ['setting_key' => HosMonitoringConfig::SETTING_KEY]);
    }

    public function test_units_must_belong_to_the_team_and_cannot_be_both_included_and_excluded(): void
    {
        $mine = Asset::factory()->create(['team_id' => $this->team->id]);
        $foreign = Asset::factory()->create();

        $this->actingAs($this->user)
            ->putJson($this->url(), $this->payload(['included_asset_ids' => [$foreign->id]]))
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['included_asset_ids.0']);

        $this->actingAs($this->user)
            ->putJson($this->url(), $this->payload(['included_asset_ids' => [$mine->id], 'excluded_asset_ids' => [$mine->id]]))
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['excluded_asset_ids']);
    }

    public function test_saving_never_touches_another_tenant(): void
    {
        $other = User::factory()->create()->currentTeam;
        TenantSetting::factory()->create([
            'team_id' => $other->id,
            'setting_key' => HosMonitoringConfig::SETTING_KEY,
            'setting_group' => SettingGroup::Compliance,
            'value_type' => SettingValueType::Json,
            'value_json' => ['tag_ids' => ['1']],
        ]);

        $this->assertNoTenantLeak($this->team, fn () => $this->actingAs($this->user)->putJson($this->url(), $this->payload())->assertOk());
    }

    public function test_the_api_mirrors_reading_and_saving(): void
    {
        $this->actingAs($this->user)
            ->getJson("/api/{$this->team->slug}/settings/hos")
            ->assertOk()
            ->assertJsonPath('data.config.leadMinutes', [30, 15]);

        $this->actingAs($this->user)
            ->putJson("/api/{$this->team->slug}/settings/hos", $this->payload())
            ->assertOk()
            ->assertJsonPath('data.config.tagIds', ['4738197']);
    }

    public function test_the_generic_settings_endpoint_cannot_write_the_hos_config(): void
    {
        $this->actingAs($this->user)
            ->putJson(route('tenant-config.settings.update', ['current_team' => $this->team->slug]), ['settings' => [[
                'setting_key' => HosMonitoringConfig::SETTING_KEY,
                'setting_group' => 'compliance',
                'value_type' => 'json',
                'value' => ['lead_minutes' => [-5]],
            ]]])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['settings.0.setting_key']);
    }
}
```

`tests/Feature/Domains/Notifications/DriverAppChannelNotOfferedTest.php`:

```php
<?php

namespace Tests\Feature\Domains\Notifications;

use App\Models\User;
use Database\Seeders\AccessSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * La app del chofer en Samsara sólo sirve a avisos HOS al chofer (PR 2):
 * ninguna política ni preferencia del equipo puede elegirla.
 */
class DriverAppChannelNotOfferedTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(AccessSeeder::class);
    }

    public function test_tenant_notification_policies_reject_the_driver_app(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)
            ->putJson(route('tenant-config.notifications.update', ['current_team' => $user->currentTeam->slug]), ['policies' => [[
                'policy_code' => 'default',
                'allowed_channels' => ['samsara_driver_app'],
                'fallback_channels' => ['samsara_driver_app'],
            ]]])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['policies.0.allowed_channels.0', 'policies.0.fallback_channels.0']);
    }

    public function test_personal_preferences_reject_the_driver_app(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)
            ->put(route('notification-preferences.update'), [
                'notification_type' => 'incident.created',
                'allowed_channels' => ['samsara_driver_app'],
            ])
            ->assertSessionHasErrors('allowed_channels.0');

        $this->actingAs($user)
            ->putJson("/api/{$user->currentTeam->slug}/notifications/preferences", [
                'notification_type' => 'incident.created',
                'allowed_channels' => ['samsara_driver_app'],
            ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['allowed_channels.0']);
    }
}
```

- [ ] **Step 2:** Run `php artisan test --compact --filter='HosMonitoringConfigTest|DriverAppChannelNotOfferedTest'` → FAIL (rutas `tenant-config.hos.update` y `settings/hos` inexistentes, prop `hos` ausente, canales aceptados).

- [ ] **Step 3: Constantes** en `HosMonitoringConfig` (debajo de `SETTING_KEY`):

```php
    /** Canales con los que SAM le habla al chofer: no tiene correo ni cuenta en SAM. */
    public const array DRIVER_CHANNELS = ['samsara_driver_app', 'whatsapp', 'sms', 'voice'];

    /** Situaciones que el tenant puede apagar: la infracción siempre se vigila. */
    public const array CONFIGURABLE_SITUATIONS = ['break_due', 'drive_limit', 'shift_limit', 'cycle_limit', 'rest_complete'];
```

- [ ] **Step 4: FormRequest** `app/Http/Requests/TenantConfig/UpdateHosMonitoringConfigRequest.php`:

```php
<?php

namespace App\Http\Requests\TenantConfig;

use App\Domains\Drivers\Models\HosDriverState;
use App\Domains\Drivers\Support\HosMonitoringConfig;
use App\Domains\Incidents\Support\EscalationLadder;
use App\Models\Team;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Exists;
use Illuminate\Validation\Validator;

/**
 * Configuración del monitoreo HOS (spec §3.2 y §3.12). El resolver
 * (`HosMonitoringConfig::fromArray`) tolera basura en silencio, así que la
 * validación estricta vive aquí: nada de "false" como texto ni negativos,
 * escalones crecientes con al menos {@see EscalationLadder::MIN_GAP_MINUTES}
 * min entre sí y un solo incidente, al final.
 */
class UpdateHosMonitoringConfigRequest extends FormRequest
{
    public const int MAX_LADDER_STEPS = 8;

    public function authorize(): bool
    {
        return $this->user()?->can('updateConfig', HosDriverState::class) ?? false;
    }

    /**
     * Una unidad del team de la ruta, no borrada.
     */
    public static function teamAssetRule(mixed $team): Exists
    {
        return Rule::exists('assets', 'id')
            ->where('team_id', $team instanceof Team ? $team->id : 0)
            ->whereNull('deleted_at');
    }

    /**
     * @return array<string, array<mixed>>
     */
    public function rules(): array
    {
        $asset = self::teamAssetRule($this->route('current_team'));

        $rules = [
            'tag_ids' => ['present', 'array', 'max:100'],
            'tag_ids.*' => ['required', 'string', 'max:64', 'distinct'],
            'included_asset_ids' => ['present', 'array', 'max:2000'],
            'included_asset_ids.*' => ['required', 'integer', 'distinct', $asset],
            'excluded_asset_ids' => ['present', 'array', 'max:2000'],
            'excluded_asset_ids.*' => ['required', 'integer', 'distinct', $asset],
            'situations' => ['required', 'array:'.implode(',', HosMonitoringConfig::CONFIGURABLE_SITUATIONS)],
            'lead_minutes' => ['required', 'array', 'min:1', 'max:5'],
            'lead_minutes.*' => ['required', 'integer', 'min:0', 'max:240', 'distinct'],
            'cycle_lead_hours' => ['required', 'array', 'min:1', 'max:4'],
            'cycle_lead_hours.*' => ['required', 'integer', 'min:1', 'max:69', 'distinct'],
            'rest_complete_nudge_minutes' => ['required', 'array', 'min:1', 'max:4'],
            'rest_complete_nudge_minutes.*' => ['required', 'integer', 'min:1', 'max:180', 'distinct'],
            'rest_complete_expire_minutes' => ['required', 'integer', 'min:2', 'max:240'],
            'ladder' => ['required', 'array', 'min:1', 'max:'.self::MAX_LADDER_STEPS],
            'ladder.*' => ['required', 'array:after_minutes,channels,escalate'],
            'ladder.*.after_minutes' => ['required', 'integer', 'min:0', 'max:240'],
            'ladder.*.channels' => ['present', 'array', 'max:4'],
            // Sin `distinct`: con dos comodines compara contra TODOS los escalones
            // (la app va en el escalón 0 y en el 1). Los repetidos dentro de un
            // escalón se quitan al guardar.
            'ladder.*.channels.*' => ['required', 'string', Rule::in(HosMonitoringConfig::DRIVER_CHANNELS)],
            'ladder.*.escalate' => ['nullable', 'string', Rule::in(['incident'])],
        ];

        foreach (HosMonitoringConfig::CONFIGURABLE_SITUATIONS as $situation) {
            // `boolean` acepta true/false/1/0/"1"/"0"; rechaza "false" y "true".
            $rules["situations.{$situation}"] = ['required', 'boolean'];
        }

        return $rules;
    }

    /**
     * @return array<int, callable(Validator): void>
     */
    public function after(): array
    {
        return [
            function (Validator $validator): void {
                // Las reglas cruzadas sólo tienen sentido sobre valores bien formados.
                if ($validator->errors()->isNotEmpty()) {
                    return;
                }

                $this->checkUnits($validator);
                $this->checkRestComplete($validator);
                $this->checkLadder($validator);
            },
        ];
    }

    private function checkUnits(Validator $validator): void
    {
        $included = array_map(self::int(...), (array) $this->input('included_asset_ids', []));
        $excluded = array_map(self::int(...), (array) $this->input('excluded_asset_ids', []));

        if (array_intersect($included, $excluded) !== []) {
            $validator->errors()->add('excluded_asset_ids', 'Una unidad no puede estar en "siempre entran" y en "nunca entran" a la vez.');
        }
    }

    private function checkRestComplete(Validator $validator): void
    {
        $nudges = array_map(self::int(...), (array) $this->input('rest_complete_nudge_minutes', []));
        $lastNudge = $nudges === [] ? 0 : max($nudges);

        if (self::int($this->input('rest_complete_expire_minutes')) <= $lastNudge) {
            $validator->errors()->add(
                'rest_complete_expire_minutes',
                "Debe ser mayor que el último recordatorio para retomar ({$lastNudge} min); si no, ese recordatorio nunca sale.",
            );
        }
    }

    private function checkLadder(Validator $validator): void
    {
        $ladder = array_values(array_filter((array) $this->input('ladder', []), 'is_array'));
        $last = count($ladder) - 1;
        $previous = null;
        $notifies = false;

        foreach ($ladder as $index => $step) {
            $after = self::int($step['after_minutes'] ?? null);
            $channels = (array) ($step['channels'] ?? []);
            $escalate = ($step['escalate'] ?? null) === 'incident';

            if ($index === 0 && $after !== 0) {
                $validator->errors()->add('ladder.0.after_minutes', 'El primer escalón sale al llegar al límite: debe ser 0 min.');
            }

            if ($previous !== null && $after - $previous < EscalationLadder::MIN_GAP_MINUTES) {
                $validator->errors()->add("ladder.{$index}.after_minutes", 'Deja al menos '.EscalationLadder::MIN_GAP_MINUTES.' min después del escalón anterior.');
            }

            if ($escalate && $index !== $last) {
                $validator->errors()->add("ladder.{$index}.escalate", 'El incidente sólo puede ser el último escalón.');
            }

            if ($escalate && $channels !== []) {
                $validator->errors()->add("ladder.{$index}.channels", 'El escalón del incidente avisa a tu equipo, no al chofer: quítale los canales.');
            }

            if (! $escalate && $channels === []) {
                $validator->errors()->add("ladder.{$index}.channels", 'Elige al menos un canal para este escalón.');
            }

            $notifies = $notifies || (! $escalate && $channels !== []);
            $previous = $after;
        }

        if (! $notifies) {
            $validator->errors()->add('ladder', 'La escalera necesita al menos un escalón que le avise al chofer.');
        }
    }

    private static function int(mixed $value): int
    {
        return is_numeric($value) ? (int) $value : 0;
    }
}
```

- [ ] **Step 5: Guardar** `app/Domains/Drivers/Actions/SaveHosMonitoringConfig.php`:

```php
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
```

- [ ] **Step 6: Props de la sección** `app/Domains/Drivers/Actions/BuildHosConfigForm.php`:

```php
<?php

namespace App\Domains\Drivers\Actions;

use App\Domains\Assets\Models\Asset;
use App\Domains\Drivers\Enums\HosSituation;
use App\Domains\Drivers\Support\HosMonitoringConfig;
use App\Domains\Incidents\Support\EscalationLadder;
use App\Domains\Integrations\Enums\TenantIntegrationStatus;
use App\Domains\Integrations\Models\TenantIntegration;
use App\Domains\Notifications\Enums\ChannelType;
use App\Domains\Notifications\Models\NotificationChannel;
use App\Support\TenantContext;
use Illuminate\Database\Eloquent\Builder;

/**
 * Lo que necesita la sección `?seccion=hos`: la configuración efectiva (la
 * guardada mezclada sobre `config('hos.defaults')`), los recomendados, las
 * unidades del team, los canales del chofer (con si SAM los entrega hoy a
 * este tenant) y si hay integración Samsara activa.
 */
class BuildHosConfigForm
{
    public function __construct(
        private readonly ResolveHosMonitoringConfig $resolveConfig,
    ) {}

    /**
     * @return array{config: array<string, mixed>, defaults: array<string, mixed>, assets: list<array{id: int, name: string, code: string|null, monitored: bool}>, channels: list<array{value: string, available: bool}>, hasIntegration: bool, canManage: bool, minGapMinutes: int}
     */
    public function execute(int $teamId, bool $canManage): array
    {
        return TenantContext::for($teamId, function () use ($teamId, $canManage): array {
            $defaults = HosMonitoringConfig::fromArray([], (array) config('hos.defaults'));
            $config = $this->resolveConfig->execute($teamId) ?? $defaults;

            $usable = NotificationChannel::query()
                ->usableByTeam($teamId)
                ->pluck('channel_type')
                ->map(fn (mixed $type): string => $type instanceof ChannelType ? $type->value : (string) $type)
                ->all();

            return [
                'config' => self::present($config),
                'defaults' => self::present($defaults),
                'assets' => array_values(Asset::query()
                    ->where('team_id', $teamId)
                    ->orderBy('name')
                    ->orderBy('id')
                    ->get(['id', 'team_id', 'name', 'code', 'monitoring_state'])
                    ->map(fn (Asset $asset): array => [
                        'id' => $asset->id,
                        'name' => $asset->name,
                        'code' => $asset->code,
                        'monitored' => $asset->isMonitored(),
                    ])
                    ->all()),
                'channels' => array_map(fn (string $channel): array => [
                    'value' => $channel,
                    'available' => in_array($channel, $usable, true),
                ], HosMonitoringConfig::DRIVER_CHANNELS),
                'hasIntegration' => TenantIntegration::query()
                    ->where('team_id', $teamId)
                    ->where('status', TenantIntegrationStatus::Active)
                    ->whereHas('provider', fn (Builder $query) => $query->where('code', 'samsara'))
                    ->exists(),
                'canManage' => $canManage,
                'minGapMinutes' => EscalationLadder::MIN_GAP_MINUTES,
            ];
        });
    }

    /**
     * @return array{tagIds: array<int, string>, includedAssetIds: array<int, int>, excludedAssetIds: array<int, int>, situations: array<string, bool>, leadMinutes: list<int>, cycleLeadHours: list<int>, restCompleteNudgeMinutes: list<int>, restCompleteExpireMinutes: int, ladder: list<array{afterMinutes: int, channels: list<string>, escalate: bool}>}
     */
    public static function present(HosMonitoringConfig $config): array
    {
        $situations = [];

        foreach (HosMonitoringConfig::CONFIGURABLE_SITUATIONS as $key) {
            $situations[$key] = $config->enabled(HosSituation::from($key));
        }

        return [
            'tagIds' => $config->tagIds,
            'includedAssetIds' => $config->includedAssetIds,
            'excludedAssetIds' => $config->excludedAssetIds,
            'situations' => $situations,
            // Sin el 0 del límite: la pantalla edita sólo los avisos previos.
            'leadMinutes' => $config->leadThresholdsMinutes(),
            'cycleLeadHours' => $config->cycleThresholdsHours(),
            'restCompleteNudgeMinutes' => $config->restNudgeMinutes(),
            'restCompleteExpireMinutes' => $config->restCompleteExpireMinutes,
            'ladder' => array_map(fn (array $step): array => [
                'afterMinutes' => $step['after_minutes'],
                'channels' => $step['channels'],
                'escalate' => $step['escalate'],
            ], $config->ladderSteps()),
        ];
    }
}
```

- [ ] **Step 7: Controlador** `app/Http/Controllers/TenantConfig/HosMonitoringConfigController.php` (web y API; JSON en ambos, como `TenantConfigController`):

```php
<?php

namespace App\Http\Controllers\TenantConfig;

use App\Domains\Drivers\Actions\BuildHosConfigForm;
use App\Domains\Drivers\Actions\SaveHosMonitoringConfig;
use App\Domains\Drivers\Models\HosDriverState;
use App\Http\Controllers\Controller;
use App\Http\Requests\TenantConfig\UpdateHosMonitoringConfigRequest;
use App\Models\Team;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Configuración del monitoreo HOS (sección `?seccion=hos`). La autorización
 * (feature `hos_monitoring` + permiso) es `HosMonitoringPolicy`; el guardado
 * la revisa en el FormRequest, antes de validar.
 */
class HosMonitoringConfigController extends Controller
{
    public function show(Request $request, Team $current_team, BuildHosConfigForm $form): JsonResponse
    {
        $this->authorize('viewConfig', HosDriverState::class);

        $canManage = $request->user()?->can('updateConfig', HosDriverState::class) ?? false;

        return response()->json(['data' => $form->execute($current_team->id, $canManage)]);
    }

    public function update(UpdateHosMonitoringConfigRequest $request, Team $current_team, SaveHosMonitoringConfig $save, BuildHosConfigForm $form): JsonResponse
    {
        /** @var array<string, mixed> $validated */
        $validated = $request->validated();

        $save->execute($current_team->id, $validated, $request->user()?->id, $request->ip(), $request->userAgent());

        return response()->json(['data' => $form->execute($current_team->id, true)]);
    }
}
```

- [ ] **Step 8: Rutas.** En `routes/web.php`, junto a `tenant-config.slas.*` (con `use App\Http\Controllers\TenantConfig\HosMonitoringConfigController;`):

```php
        // Monitoreo HOS (EE. UU.): sección `?seccion=hos`. HosMonitoringPolicy
        // exige la feature `hos_monitoring` además del permiso.
        Route::put('settings/tenant-config/hos', [HosMonitoringConfigController::class, 'update'])->name('tenant-config.hos.update');
```

En `routes/api.php`, junto a `settings/versions` (mismo `use`):

```php
        Route::get('settings/hos', [HosMonitoringConfigController::class, 'show'])->name('api.tenant-config.hos.show');
        Route::put('settings/hos', [HosMonitoringConfigController::class, 'update'])->name('api.tenant-config.hos.update');
```

- [ ] **Step 9: Prop `hos` de la página.** En `TenantConfigPageController::show()` cambiar la firma y agregar la prop (con `use App\Domains\Drivers\Actions\BuildHosConfigForm;`, `use App\Domains\Drivers\Models\HosDriverState;`, `use Illuminate\Http\Request;`):

```php
    public function show(Request $request, Team $current_team, ResolveTenantAIProfile $resolveAIProfile, BuildHosConfigForm $hosForm): Response
    {
        $this->authorize('viewAny', TenantSetting::class);

        $user = $request->user();
```

y dentro de `Inertia::render('settings/tenant-config', [...])`:

```php
            // Monitoreo HOS: null sin la feature `hos_monitoring` (la sección
            // tampoco se lista en el índice de Ajustes).
            'hos' => fn (): ?array => $user !== null && $user->can('viewConfig', HosDriverState::class)
                ? $hosForm->execute($current_team->id, $user->can('updateConfig', HosDriverState::class))
                : null,
```

- [ ] **Step 10: El genérico ya no escribe `hos.monitoring`.** En `UpdateTenantSettingsRequest::after()`, al inicio del `foreach` (después del `if (! is_array($setting))`), con `use App\Domains\Drivers\Support\HosMonitoringConfig;`:

```php
                    // La configuración HOS tiene validación propia
                    // (UpdateHosMonitoringConfigRequest): el resolver tolera
                    // basura y por aquí entraría sin revisar.
                    if (($setting['setting_key'] ?? null) === HosMonitoringConfig::SETTING_KEY) {
                        $validator->errors()->add(
                            "settings.{$index}.setting_key",
                            'La configuración de HOS se guarda desde su propia sección.',
                        );

                        continue;
                    }
```

- [ ] **Step 11: Seguimiento PR 2 — canal de la app sólo para choferes.** En `UpdateTenantNotificationPoliciesRequest::rules()` (con `use App\Domains\Notifications\Enums\ChannelType;` y `use Illuminate\Validation\Rule;`):

```php
            'policies.*.allowed_channels.*' => ['string', 'max:50', Rule::enum(ChannelType::class)->except([ChannelType::SamsaraDriverApp])],
            'policies.*.fallback_channels' => ['nullable', 'array'],
            'policies.*.fallback_channels.*' => ['string', 'max:50', Rule::enum(ChannelType::class)->except([ChannelType::SamsaraDriverApp])],
```

En `Settings/NotificationPreferencesController::update()`:

```php
            'allowed_channels.*' => ['string', Rule::enum(ChannelType::class)->except([ChannelType::SamsaraDriverApp])],
```

En `Notifications/NotificationPreferenceController::update()` (API; antes aceptaba cualquier texto), con `use App\Domains\Notifications\Enums\ChannelType;` y `use Illuminate\Validation\Rule;`:

```php
            'allowed_channels.*' => ['string', Rule::enum(ChannelType::class)->except([ChannelType::SamsaraDriverApp])],
```

- [ ] **Step 12: Logging.** En `docs/SAM/logging.md`, sección HOS, después de `hos.incident.settled`:

```markdown
| `hos.config.updated` | ok | — | `team_id`, `user_id`; calc `tag_ids_count`, `included_count`, `excluded_count`, `situations_on`, `lead_minutes`, `cycle_lead_hours`, `rest_complete_nudge_minutes`, `rest_complete_expire_minutes`, `ladder_steps_count`, `ladder_escalates`; result `setting_id`, `version`. Sólo conteos: nunca ids de etiquetas ni de unidades. Deja auditoría `hos.config.updated` (`audit_logs`, una por versión) y versión en el historial (grupo compliance) |
```

- [ ] **Step 13:** Run `php artisan wayfinder:generate --with-form` y `php artisan test --compact --filter='HosMonitoringConfigTest|DriverAppChannelNotOfferedTest|TenantConfigPageTest|TenantConfigApiTest|NotificationPreference|LoggingConventionsTest'` → PASS.
- [ ] **Step 14: Commit** `feat: guardar la configuracion hos con validacion y auditoria`

---

### Task 3: Etiquetas y vista previa del conjunto sin sondear por tecla

**Files:**
- Create: `app/Domains/Drivers/Support/HosProviderCache.php`, `app/Domains/Drivers/Support/HosTagOptions.php`, `app/Domains/Drivers/Actions/ListHosTags.php`, `app/Domains/Drivers/Actions/PreviewHosEnrollment.php`, `app/Http/Requests/TenantConfig/PreviewHosEnrollmentRequest.php`
- Modify: `app/Domains/Drivers/Jobs/SyncHosClocksJob.php`, `app/Http/Controllers/TenantConfig/HosMonitoringConfigController.php`, `routes/web.php`, `routes/api.php`, `docs/SAM/logging.md`
- Test: `tests/Unit/Domains/Drivers/HosTagOptionsTest.php`, `tests/Feature/Domains/Drivers/Hos/HosEnrollmentPreviewTest.php`, `tests/Feature/Domains/Drivers/Hos/SyncHosClocksJobTest.php` (un test)

**Interfaces:**
- Consumes: `ProviderAdapter::fetchTags()/fetchHosClocks()`, `HosClockReading::toArray()/fromArray()`, `ResolveHosEnrollment::execute()`, `UpdateHosMonitoringConfigRequest::teamAssetRule()`.
- Produces: `HosProviderCache::{tagsKey(int,int), readingsKey(int,int), integrations(int): Collection<TenantIntegration>, tags(TenantIntegration): array, putReadings(TenantIntegration, array), readings(TenantIntegration): array{0: list<HosClockReading>, 1: 'cache'|'provider'}}`, `READINGS_TTL_SECONDS = 180`; `HosTagOptions::present(array $tags): list<array{id, name, parentId, parentName, depth, kind, vehicleCount, driverCount}>`; `ListHosTags::execute(int $teamId): array{tags, failed, hasIntegration}`; `PreviewHosEnrollment::execute(int $teamId, HosMonitoringConfig $draft): array{trucks: int, drivers: int, skipped: array<string,int>, failed: bool, hasIntegration: bool}`; rutas `tenant-config.hos.tags` (GET), `tenant-config.hos.preview` (POST) + `api.tenant-config.hos.tags`, `api.tenant-config.hos.preview`. Respuesta de tags: `{data: HosTagOption[], meta: {failed, hasIntegration}}`; de vista previa: `{data: HosPreview}`.

- [ ] **Step 1: Tests que fallan.** `tests/Unit/Domains/Drivers/HosTagOptionsTest.php`:

```php
<?php

namespace Tests\Unit\Domains\Drivers;

use App\Domains\Drivers\Support\HosTagOptions;
use PHPUnit\Framework\TestCase;

class HosTagOptionsTest extends TestCase
{
    /**
     * @param  list<string>  $vehicles
     * @param  list<string>  $drivers
     * @return array{id: string, name: string, parent_id: string|null, vehicle_ids: list<string>, driver_ids: list<string>}
     */
    private static function tag(string $id, string $name, ?string $parent = null, array $vehicles = [], array $drivers = []): array
    {
        return ['id' => $id, 'name' => $name, 'parent_id' => $parent, 'vehicle_ids' => $vehicles, 'driver_ids' => $drivers];
    }

    public function test_children_follow_their_parent_with_its_name_and_kind(): void
    {
        $options = HosTagOptions::present([
            self::tag('3', 'LOCAL HT', '2', drivers: ['9']),
            self::tag('1', 'USA', drivers: ['7', '8']),
            self::tag('2', 'LOCAL JC', vehicles: ['281'], drivers: ['9']),
            self::tag('4', 'TRACTOS USA', vehicles: ['281', '282']),
            self::tag('5', 'Vacío'),
        ]);

        $this->assertSame(['2', '3', '4', '1', '5'], array_column($options, 'id'));
        $this->assertSame([
            'id' => '3', 'name' => 'LOCAL HT', 'parentId' => '2', 'parentName' => 'LOCAL JC',
            'depth' => 1, 'kind' => 'driver', 'vehicleCount' => 0, 'driverCount' => 1,
        ], $options[1]);
        $this->assertSame('both', $options[0]['kind']);
        $this->assertSame('vehicle', $options[2]['kind']);
        $this->assertSame(2, $options[3]['driverCount']);
        $this->assertSame('empty', $options[4]['kind']);
    }

    public function test_an_orphan_or_a_cycle_never_loses_a_tag_nor_loops(): void
    {
        $options = HosTagOptions::present([
            self::tag('1', 'A', '2'),
            self::tag('2', 'B', '1'),
            self::tag('3', 'C', '99'),
        ]);

        $this->assertSame(['3', '1', '2'], array_column($options, 'id'));
        $this->assertSame(0, $options[0]['depth']);
        $this->assertNull($options[0]['parentName']);
    }
}
```

`tests/Feature/Domains/Drivers/Hos/HosEnrollmentPreviewTest.php`:

```php
<?php

namespace Tests\Feature\Domains\Drivers\Hos;

use App\Domains\Assets\Models\Asset;
use App\Domains\Drivers\Support\HosMonitoringConfig;
use App\Domains\Drivers\Support\HosProviderCache;
use App\Domains\Integrations\Data\HosClockReading;
use App\Domains\Integrations\Models\TenantIntegration;
use App\Domains\Tenancy\Models\TenantFeature;
use App\Models\Team;
use App\Models\User;
use Database\Seeders\AccessSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Tests\Concerns\AssertsSystemLog;
use Tests\Concerns\AssertsTenantIsolation;
use Tests\TestCase;

class HosEnrollmentPreviewTest extends TestCase
{
    use AssertsSystemLog, AssertsTenantIsolation, HosTenantFixtures, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(AccessSeeder::class);
    }

    private function ownerOf(TenantIntegration $integration): User
    {
        /** @var User $owner */
        $owner = Team::query()->findOrFail($integration->team_id)->members()->firstOrFail();

        return $owner;
    }

    private function slugOf(TenantIntegration $integration): string
    {
        return Team::query()->findOrFail($integration->team_id)->slug;
    }

    private function reading(string $driver = '58072405', ?string $vehicle = '281'): HosClockReading
    {
        return new HosClockReading($driver, $vehicle, 'driving', 1500, 12000, 21000, 220000, 0);
    }

    /**
     * @param  array<string, mixed>  $selection
     */
    private function preview(TenantIntegration $integration, array $selection): \Illuminate\Testing\TestResponse
    {
        return $this->actingAs($this->ownerOf($integration))->postJson(
            route('tenant-config.hos.preview', ['current_team' => $this->slugOf($integration)]),
            $selection + ['tag_ids' => [], 'included_asset_ids' => [], 'excluded_asset_ids' => []],
        );
    }

    private function fakeTags(): void
    {
        Http::fake([
            'api.samsara.com/tags*' => Http::response(['data' => [
                ['id' => '4738197', 'name' => 'USA', 'vehicles' => [], 'drivers' => [['id' => '58072405']]],
            ], 'pagination' => ['endCursor' => '', 'hasNextPage' => false]]),
        ]);
    }

    public function test_the_preview_counts_with_the_last_poll_without_reading_the_clocks_again(): void
    {
        $integration = $this->hosIntegration();
        $this->hosDriver($integration);
        app(HosProviderCache::class)->putReadings($integration, [$this->reading(), $this->reading('99', null)]);
        $this->fakeTags();

        $this->preview($integration, ['tag_ids' => ['4738197']])
            ->assertOk()
            ->assertJsonPath('data.trucks', 1)
            ->assertJsonPath('data.drivers', 1)
            ->assertJsonPath('data.skipped.no_vehicle', 1)
            ->assertJsonPath('data.failed', false);

        Http::assertNotSent(fn ($request) => str_contains($request->url(), '/fleet/hos/clocks'));
        $log = $this->assertSystemLogged('hos.preview.computed');
        $this->assertSame('cache', $log['calc']['source']);
        $this->assertSame(1, $log['result']['trucks']);
        $this->assertNoSensitiveDataLogged();
    }

    public function test_excluding_the_unit_takes_the_driver_out(): void
    {
        $integration = $this->hosIntegration();
        [, $asset] = $this->hosDriver($integration);
        app(HosProviderCache::class)->putReadings($integration, [$this->reading()]);
        $this->fakeTags();

        $this->preview($integration, ['tag_ids' => ['4738197'], 'excluded_asset_ids' => [$asset->id]])
            ->assertOk()
            ->assertJsonPath('data.trucks', 0)
            ->assertJsonPath('data.drivers', 0)
            ->assertJsonPath('data.skipped.excluded', 1);
    }

    public function test_with_a_cold_cache_it_reads_the_clocks_once_and_keeps_them(): void
    {
        $integration = $this->hosIntegration();
        [, $asset] = $this->hosDriver($integration);
        Http::fake([
            'api.samsara.com/fleet/hos/clocks*' => Http::response(['data' => [[
                'driver' => ['id' => '58072405'],
                'currentVehicle' => ['id' => '281'],
                'currentDutyStatus' => ['hosStatusType' => 'driving'],
                'clocks' => ['break' => ['timeUntilBreakDurationMs' => 1500000]],
            ]], 'pagination' => ['endCursor' => '', 'hasNextPage' => false]]),
        ]);

        $this->preview($integration, ['included_asset_ids' => [$asset->id]])->assertOk()->assertJsonPath('data.trucks', 1);
        $this->preview($integration, ['included_asset_ids' => [$asset->id]])->assertOk()->assertJsonPath('data.drivers', 1);

        $this->assertCount(1, Http::recorded(fn ($request) => str_contains($request->url(), '/fleet/hos/clocks')));
        $this->assertSame('provider', $this->systemLogEntries('hos.preview.computed')[0]['context']['calc']['source']);
    }

    public function test_a_samsara_failure_answers_a_degraded_preview(): void
    {
        $integration = $this->hosIntegration();
        Http::fake(['api.samsara.com/fleet/hos/clocks*' => Http::response([], 503)]);

        $this->preview($integration, [])->assertOk()->assertJsonPath('data.failed', true);

        $this->assertSystemLogged('hos.preview.computed', fn (array $context): bool => ($context['reason'] ?? null) === 'provider_error');
        $this->assertNoSensitiveDataLogged();
    }

    public function test_without_a_samsara_integration_there_is_nothing_to_count(): void
    {
        $owner = User::factory()->create();
        TenantFeature::factory()->create(['team_id' => $owner->currentTeam->id, 'feature_key' => HosMonitoringConfig::FEATURE_KEY, 'enabled' => true]);

        $this->actingAs($owner)
            ->postJson(route('tenant-config.hos.preview', ['current_team' => $owner->currentTeam->slug]), ['tag_ids' => [], 'included_asset_ids' => [], 'excluded_asset_ids' => []])
            ->assertOk()
            ->assertJsonPath('data.hasIntegration', false);

        $this->assertSystemLogged('hos.preview.computed', fn (array $context): bool => ($context['reason'] ?? null) === 'no_integration');
    }

    public function test_without_the_feature_tags_and_preview_answer_403(): void
    {
        $integration = $this->hosIntegration(feature: false);

        $this->preview($integration, [])->assertForbidden();
        $this->actingAs($this->ownerOf($integration))
            ->getJson(route('tenant-config.hos.tags', ['current_team' => $this->slugOf($integration)]))
            ->assertForbidden();
    }

    public function test_tags_come_as_a_tree_and_are_cached_per_tenant(): void
    {
        $integration = $this->hosIntegration();
        Http::fake(['api.samsara.com/tags*' => Http::response(['data' => [
            ['id' => '1', 'name' => 'USA', 'drivers' => [['id' => '7']]],
            ['id' => '2', 'name' => 'TRACTOS USA', 'parentTagId' => '1', 'vehicles' => [['id' => '281']]],
        ], 'pagination' => ['endCursor' => '', 'hasNextPage' => false]])]);
        $url = route('tenant-config.hos.tags', ['current_team' => $this->slugOf($integration)]);
        $owner = $this->ownerOf($integration);

        $this->actingAs($owner)->getJson($url)
            ->assertOk()
            ->assertJsonPath('data.0.id', '1')
            ->assertJsonPath('data.1.parentName', 'USA')
            ->assertJsonPath('data.1.kind', 'vehicle')
            ->assertJsonPath('meta.failed', false)
            ->assertJsonPath('meta.hasIntegration', true);
        $this->actingAs($owner)->getJson($url)->assertOk();

        Http::assertSentCount(1);
        $this->assertTrue(Cache::has(HosProviderCache::tagsKey($integration->team_id, $integration->id)));
        $this->assertSystemLogged('hos.tags.listed');
        $this->assertNoSensitiveDataLogged();
    }

    public function test_the_preview_never_reads_another_tenant(): void
    {
        $mine = $this->hosIntegration();
        [, $asset] = $this->hosDriver($mine);
        $theirs = $this->hosIntegration();
        // Mismos ids de Samsara en el otro tenant.
        $this->hosDriver($theirs);
        app(HosProviderCache::class)->putReadings($mine, [$this->reading()]);
        app(HosProviderCache::class)->putReadings($theirs, [$this->reading(), $this->reading('77', '282')]);

        $response = $this->assertNoTenantLeak($mine->team_id, fn () => $this->preview($mine, ['included_asset_ids' => [$asset->id]]));

        $response->assertOk()->assertJsonPath('data.trucks', 1)->assertJsonPath('data.drivers', 1);

        $foreign = Asset::withoutGlobalScopes()->where('team_id', $theirs->team_id)->firstOrFail();
        $this->preview($mine, ['included_asset_ids' => [$foreign->id]])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['included_asset_ids.0']);
    }
}
```

En `SyncHosClocksJobTest.php` (agregar `use App\Domains\Drivers\Support\HosProviderCache;`):

```php
    public function test_a_successful_poll_leaves_its_reading_for_the_config_preview(): void
    {
        $integration = $this->tenant();
        $this->link($integration, '58072405', '281');
        $this->fakeSamsara();

        app()->call([new SyncHosClocksJob($integration), 'handle']);

        $cached = Cache::get(HosProviderCache::readingsKey($integration->team_id, $integration->id));
        $this->assertSame('58072405', $cached[0]['external_driver_id']);
        $this->assertSame('281', $cached[0]['external_vehicle_id']);
    }
```

- [ ] **Step 2:** Run `php artisan test --compact --filter='HosTagOptionsTest|HosEnrollmentPreviewTest|SyncHosClocksJobTest'` → FAIL (clases y rutas inexistentes; el job no deja la lectura).

- [ ] **Step 3: Caché del proveedor** `app/Domains/Drivers/Support/HosProviderCache.php`:

```php
<?php

namespace App\Domains\Drivers\Support;

use App\Domains\Drivers\Jobs\PollHosClocksJob;
use App\Domains\Drivers\Jobs\SyncHosClocksJob;
use App\Domains\Integrations\Contracts\ProviderAdapter;
use App\Domains\Integrations\Data\HosClockReading;
use App\Domains\Integrations\Enums\TenantIntegrationStatus;
use App\Domains\Integrations\Models\TenantIntegration;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\Cache;

/**
 * Lo que la configuración HOS lee de Samsara sin pegarle en cada tecla: los
 * tags (`GET /tags`, la MISMA caché que usa el sondeo) y la última lectura de
 * relojes que deja {@see SyncHosClocksJob}. Las llaves llevan team e
 * integración.
 */
final readonly class HosProviderCache
{
    /** El sondeo corre cada minuto: 3 min cubren un ciclo perdido. */
    public const int READINGS_TTL_SECONDS = 180;

    public function __construct(
        private ProviderAdapter $providerAdapter,
    ) {}

    public static function tagsKey(int $teamId, int $integrationId): string
    {
        return "hos:tags:{$teamId}:{$integrationId}";
    }

    public static function readingsKey(int $teamId, int $integrationId): string
    {
        return "hos:clocks:{$teamId}:{$integrationId}";
    }

    /**
     * Integraciones Samsara activas del team (las que sondea {@see PollHosClocksJob}).
     *
     * @return Collection<int, TenantIntegration>
     */
    public function integrations(int $teamId): Collection
    {
        return TenantIntegration::query()
            ->where('team_id', $teamId)
            ->where('status', TenantIntegrationStatus::Active)
            ->whereHas('provider', fn (Builder $query) => $query->where('code', 'samsara'))
            ->with('provider')
            ->orderBy('id')
            ->get();
    }

    /**
     * @return array<int, array{id: string, name: string, parent_id: string|null, vehicle_ids: array<int, string>, driver_ids: array<int, string>}>
     */
    public function tags(TenantIntegration $integration): array
    {
        /** @var array<int, array{id: string, name: string, parent_id: string|null, vehicle_ids: array<int, string>, driver_ids: array<int, string>}> $tags */
        $tags = Cache::remember(
            self::tagsKey($integration->team_id, $integration->id),
            (int) config('hos.tags_cache_seconds', 300),
            fn (): array => $this->providerAdapter->fetchTags($integration),
        );

        return $tags;
    }

    /**
     * @param  array<int, HosClockReading>  $readings
     */
    public function putReadings(TenantIntegration $integration, array $readings): void
    {
        Cache::put(
            self::readingsKey($integration->team_id, $integration->id),
            array_map(fn (HosClockReading $reading): array => $reading->toArray(), array_values($readings)),
            self::READINGS_TTL_SECONDS,
        );
    }

    /**
     * La última lectura del sondeo; sin ella (feature recién encendida,
     * sondeo caído) una lectura directa que queda guardada.
     *
     * @return array{0: list<HosClockReading>, 1: 'cache'|'provider'}
     */
    public function readings(TenantIntegration $integration): array
    {
        $cached = Cache::get(self::readingsKey($integration->team_id, $integration->id));

        if (is_array($cached)) {
            return [
                array_values(array_map(
                    fn (array $row): HosClockReading => HosClockReading::fromArray($row),
                    array_filter($cached, 'is_array'),
                )),
                'cache',
            ];
        }

        $readings = array_values($this->providerAdapter->fetchHosClocks($integration));
        $this->putReadings($integration, $readings);

        return [$readings, 'provider'];
    }
}
```

- [ ] **Step 4: Árbol de etiquetas** `app/Domains/Drivers/Support/HosTagOptions.php`:

```php
<?php

namespace App\Domains\Drivers\Support;

/**
 * Etiquetas de Samsara listas para el selector: cada hija debajo de su
 * madre (elegir la madre incluye a las hijas, como en ResolveHosEnrollment),
 * hermanas por nombre, con lo que agrupan (tractos, choferes o ambos). Una
 * hija cuya madre no existe es raíz; un ciclo no se pierde ni se repite.
 */
final class HosTagOptions
{
    /**
     * @param  array<int, array{id: string, name: string, parent_id: string|null, vehicle_ids: array<int, string>, driver_ids: array<int, string>}>  $tags
     * @return list<array{id: string, name: string, parentId: string|null, parentName: string|null, depth: int, kind: 'vehicle'|'driver'|'both'|'empty', vehicleCount: int, driverCount: int}>
     */
    public static function present(array $tags): array
    {
        $byId = [];

        foreach ($tags as $tag) {
            $byId[$tag['id']] ??= $tag;
        }

        $children = [];
        $roots = [];

        foreach ($byId as $tag) {
            $parent = $tag['parent_id'];

            if ($parent !== null && $parent !== $tag['id'] && isset($byId[$parent])) {
                $children[$parent][] = $tag['id'];
            } else {
                $roots[] = $tag['id'];
            }
        }

        $byName = fn (string $a, string $b): int => strcasecmp($byId[$a]['name'], $byId[$b]['name']) ?: strcmp($a, $b);
        usort($roots, $byName);

        $out = [];
        $seen = [];

        $walk = function (string $id, int $depth) use (&$walk, &$out, &$seen, $byId, $children, $byName): void {
            if (isset($seen[$id])) {
                return;
            }

            $seen[$id] = true;
            $tag = $byId[$id];
            $parent = $tag['parent_id'] !== null && isset($byId[$tag['parent_id']]) ? $byId[$tag['parent_id']] : null;
            $vehicles = count($tag['vehicle_ids']);
            $drivers = count($tag['driver_ids']);

            $out[] = [
                'id' => $tag['id'],
                'name' => $tag['name'],
                'parentId' => $depth === 0 ? null : $parent['id'] ?? null,
                'parentName' => $depth === 0 ? null : $parent['name'] ?? null,
                'depth' => $depth,
                'kind' => match (true) {
                    $vehicles > 0 && $drivers > 0 => 'both',
                    $vehicles > 0 => 'vehicle',
                    $drivers > 0 => 'driver',
                    default => 'empty',
                },
                'vehicleCount' => $vehicles,
                'driverCount' => $drivers,
            ];

            $kids = $children[$id] ?? [];
            usort($kids, $byName);

            foreach ($kids as $kid) {
                $walk($kid, $depth + 1);
            }
        };

        foreach ($roots as $root) {
            $walk($root, 0);
        }

        // Un ciclo (A→B→A) no tiene raíz: entra como raíz en el orden recibido.
        foreach ($byId as $tag) {
            $walk($tag['id'], 0);
        }

        return $out;
    }
}
```

> Nota: las llaves numéricas en `$byId`/`$children` se vuelven enteros en PHP; siempre se leen con el `id` string de la etiqueta (`isset($byId['2'])` funciona) y nunca con `array_keys()`, así `id` sale como string.

- [ ] **Step 5: Listar tags** `app/Domains/Drivers/Actions/ListHosTags.php`:

```php
<?php

namespace App\Domains\Drivers\Actions;

use App\Domains\Drivers\Support\HosProviderCache;
use App\Domains\Drivers\Support\HosTagOptions;
use App\Domains\Integrations\Exceptions\ProviderRequestFailed;
use App\Domains\Integrations\Exceptions\ProviderRequestFailedException;
use App\Support\SystemLog;
use App\Support\TenantContext;

/**
 * Etiquetas de Samsara del tenant para el selector de la sección HOS, de la
 * caché que comparte con el sondeo. Si Samsara falla, la sección sigue
 * usable (lo ya elegido se conserva) y se marca `failed`.
 */
class ListHosTags
{
    public function __construct(
        private readonly HosProviderCache $providerCache,
    ) {}

    /**
     * @return array{tags: list<array<string, mixed>>, failed: bool, hasIntegration: bool}
     */
    public function execute(int $teamId): array
    {
        return TenantContext::for($teamId, function () use ($teamId): array {
            $input = ['team_id' => $teamId];
            $integrations = $this->providerCache->integrations($teamId);

            if ($integrations->isEmpty()) {
                SystemLog::skipped('hos.tags.listed', reason: 'no_integration', input: $input);

                return ['tags' => [], 'failed' => false, 'hasIntegration' => false];
            }

            $tags = [];

            foreach ($integrations as $integration) {
                try {
                    foreach ($this->providerCache->tags($integration) as $tag) {
                        $tags[$tag['id']] ??= $tag;
                    }
                } catch (ProviderRequestFailed|ProviderRequestFailedException $e) {
                    SystemLog::degraded('hos.tags.listed', reason: 'provider_error', input: $input + [
                        'integration_id' => $integration->id,
                    ], error: $e);

                    return ['tags' => [], 'failed' => true, 'hasIntegration' => true];
                }
            }

            $options = HosTagOptions::present(array_values($tags));

            SystemLog::ok('hos.tags.listed', input: $input, calc: [
                'integrations_count' => $integrations->count(),
            ], result: ['tags_count' => count($options)], debug: true);

            return ['tags' => $options, 'failed' => false, 'hasIntegration' => true];
        });
    }
}
```

- [ ] **Step 6: Vista previa** `app/Domains/Drivers/Actions/PreviewHosEnrollment.php`:

```php
<?php

namespace App\Domains\Drivers\Actions;

use App\Domains\Drivers\Support\HosMonitoringConfig;
use App\Domains\Drivers\Support\HosProviderCache;
use App\Domains\Integrations\Exceptions\ProviderRequestFailed;
use App\Domains\Integrations\Exceptions\ProviderRequestFailedException;
use App\Support\SystemLog;
use App\Support\TenantContext;

/**
 * "13 tractos · 43 choferes entran ahora": las MISMAS reglas del sondeo
 * ({@see ResolveHosEnrollment}) con la selección en borrador, sobre la última
 * lectura de relojes del sondeo y los tags cacheados. Cuenta tractos y
 * choferes distintos y por qué quedan fuera los demás.
 */
class PreviewHosEnrollment
{
    public function __construct(
        private readonly HosProviderCache $providerCache,
        private readonly ResolveHosEnrollment $resolveEnrollment,
    ) {}

    /**
     * @return array{trucks: int, drivers: int, skipped: array<string, int>, failed: bool, hasIntegration: bool}
     */
    public function execute(int $teamId, HosMonitoringConfig $draft): array
    {
        return TenantContext::for($teamId, function () use ($teamId, $draft): array {
            $input = ['team_id' => $teamId];
            $empty = ['trucks' => 0, 'drivers' => 0, 'skipped' => []];
            $integrations = $this->providerCache->integrations($teamId);

            if ($integrations->isEmpty()) {
                SystemLog::skipped('hos.preview.computed', reason: 'no_integration', input: $input);

                return $empty + ['failed' => false, 'hasIntegration' => false];
            }

            $assets = [];
            $drivers = [];
            $skipped = [];
            $source = 'cache';

            foreach ($integrations as $integration) {
                try {
                    [$readings, $readFrom] = $this->providerCache->readings($integration);
                    $tags = $draft->tagIds === [] ? [] : $this->providerCache->tags($integration);
                } catch (ProviderRequestFailed|ProviderRequestFailedException $e) {
                    SystemLog::degraded('hos.preview.computed', reason: 'provider_error', input: $input + [
                        'integration_id' => $integration->id,
                    ], error: $e);

                    return $empty + ['failed' => true, 'hasIntegration' => true];
                }

                $source = $readFrom === 'provider' ? 'provider' : $source;
                $enrollment = $this->resolveEnrollment->execute($integration, $draft, $readings, $tags);

                foreach ($enrollment->enrolled as $row) {
                    $assets[$row['asset']->id] = true;
                    $drivers[$row['driver']->id] = true;
                }

                foreach ($enrollment->skippedByReason as $reason => $count) {
                    $skipped[$reason] = ($skipped[$reason] ?? 0) + $count;
                }
            }

            $result = ['trucks' => count($assets), 'drivers' => count($drivers)];
            $skippedLog = [];

            foreach ($skipped as $reason => $count) {
                $skippedLog["skipped_{$reason}"] = $count;
            }

            SystemLog::ok('hos.preview.computed', input: $input, calc: [
                'source' => $source,
                'tag_ids_count' => count($draft->tagIds),
                'included_count' => count($draft->includedAssetIds),
                'excluded_count' => count($draft->excludedAssetIds),
            ], result: $result + $skippedLog, debug: true);

            return $result + ['skipped' => $skipped, 'failed' => false, 'hasIntegration' => true];
        });
    }
}
```

- [ ] **Step 7: Request** `app/Http/Requests/TenantConfig/PreviewHosEnrollmentRequest.php`:

```php
<?php

namespace App\Http\Requests\TenantConfig;

use App\Domains\Drivers\Models\HosDriverState;
use Illuminate\Foundation\Http\FormRequest;

/**
 * Selección en borrador para la vista previa del conjunto HOS: sólo lo que
 * decide quién entra (etiquetas y unidades del team).
 */
class PreviewHosEnrollmentRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('viewConfig', HosDriverState::class) ?? false;
    }

    /**
     * @return array<string, array<mixed>>
     */
    public function rules(): array
    {
        $asset = UpdateHosMonitoringConfigRequest::teamAssetRule($this->route('current_team'));

        return [
            'tag_ids' => ['present', 'array', 'max:100'],
            'tag_ids.*' => ['required', 'string', 'max:64'],
            'included_asset_ids' => ['present', 'array', 'max:2000'],
            'included_asset_ids.*' => ['required', 'integer', $asset],
            'excluded_asset_ids' => ['present', 'array', 'max:2000'],
            'excluded_asset_ids.*' => ['required', 'integer', $asset],
        ];
    }
}
```

- [ ] **Step 8: Controlador.** En `HosMonitoringConfigController` agregar (con `use App\Domains\Drivers\Actions\ListHosTags;`, `use App\Domains\Drivers\Actions\PreviewHosEnrollment;`, `use App\Domains\Drivers\Support\HosMonitoringConfig;`, `use App\Http\Requests\TenantConfig\PreviewHosEnrollmentRequest;`):

```php
    public function tags(Team $current_team, ListHosTags $listTags): JsonResponse
    {
        $this->authorize('viewConfig', HosDriverState::class);

        $result = $listTags->execute($current_team->id);

        return response()->json([
            'data' => $result['tags'],
            'meta' => ['failed' => $result['failed'], 'hasIntegration' => $result['hasIntegration']],
        ]);
    }

    public function preview(PreviewHosEnrollmentRequest $request, Team $current_team, PreviewHosEnrollment $preview): JsonResponse
    {
        /** @var array<string, mixed> $selection */
        $selection = $request->validated();

        $draft = HosMonitoringConfig::fromArray($selection, (array) config('hos.defaults'));

        return response()->json(['data' => $preview->execute($current_team->id, $draft)]);
    }
```

Rutas web (junto a `tenant-config.hos.update`):

```php
        Route::get('settings/tenant-config/hos/tags', [HosMonitoringConfigController::class, 'tags'])->name('tenant-config.hos.tags');
        Route::post('settings/tenant-config/hos/preview', [HosMonitoringConfigController::class, 'preview'])->name('tenant-config.hos.preview');
```

Rutas API (junto a `api.tenant-config.hos.update`):

```php
        Route::get('settings/hos/tags', [HosMonitoringConfigController::class, 'tags'])->name('api.tenant-config.hos.tags');
        Route::post('settings/hos/preview', [HosMonitoringConfigController::class, 'preview'])->name('api.tenant-config.hos.preview');
```

- [ ] **Step 9: El sondeo deja su lectura.** En `SyncHosClocksJob`: agregar `use App\Domains\Drivers\Support\HosProviderCache;`, quitar `use Illuminate\Support\Facades\Cache;`, agregar el parámetro `HosProviderCache $providerCache` a `handle()` (después de `ProviderAdapter $providerAdapter`) y al `use (...)` del closure, y dentro:

```php
            try {
                $readings = $providerAdapter->fetchHosClocks($this->integration);
                $tags = $config->tagIds === [] ? [] : $providerCache->tags($this->integration);
            } catch (ProviderRequestFailed|ProviderRequestFailedException $e) {
                // (sin cambios)
            }

            // La vista previa de la configuración lee esta misma lectura en vez
            // de pegarle a Samsara en cada tecla.
            $providerCache->putReadings($this->integration, $readings);
```

(la llave de tags es la misma `hos:tags:{team}:{integración}` que usaba el `Cache::remember` en línea).

- [ ] **Step 10: Logging.** En `docs/SAM/logging.md`, después de `hos.config.updated`:

```markdown
| `hos.tags.listed` | ok (**debug**) / skipped / degraded | `no_integration` · `provider_error` | `team_id` (`integration_id` en degraded); calc `integrations_count`; result `tags_count`; `error` en degraded. Selector de etiquetas de la sección HOS: `GET /tags` por la caché `hos:tags:{team}:{integración}` que comparte con el sondeo (`hos.tags_cache_seconds`) |
| `hos.preview.computed` | ok (**debug**) / skipped / degraded | `no_integration` · `provider_error` | `team_id` (`integration_id` en degraded); calc `source` (`cache` = última lectura del sondeo, `hos:clocks:{team}:{integración}`, 180 s; `provider` = caché fría, una lectura directa que queda guardada), `tag_ids_count`, `included_count`, `excluded_count`; result `trucks`, `drivers` (distintos) y `skipped_{razón}` (las de `hos.poll.completed`). Mismas reglas que el sondeo (`ResolveHosEnrollment`) con la selección en borrador |
```

- [ ] **Step 11:** Run `php artisan wayfinder:generate --with-form` y `php artisan test --compact --filter='HosTagOptionsTest|HosEnrollmentPreviewTest|SyncHosClocksJobTest|LoggingConventionsTest'` → PASS.
- [ ] **Step 12: Commit** `feat: etiquetas y vista previa del conjunto hos sin sondear por tecla`

---

### Task 4: Sección `?seccion=hos` en la configuración de la empresa

**Files:**
- Create: `resources/js/types/hos.ts`, `resources/js/components/sam/hos/copy.ts`, `resources/js/components/sam/hos/config-lib.ts`, `resources/js/components/sam/hos/config-lib.test.ts`, `resources/js/components/sam/hos/use-hos-preview.ts`, `resources/js/components/sam/hos/use-hos-preview.test.ts`, `resources/js/components/sam/hos/use-hos-tags.ts`, `resources/js/components/sam/hos/hos-tag-picker.tsx`, `resources/js/components/sam/hos/hos-asset-picker.tsx`, `resources/js/components/sam/hos/hos-ladder-editor.tsx`, `resources/js/components/sam/settings/tenant-config/hos-section.tsx`
- Modify: `resources/js/components/sam/settings/use-settings-nav.ts`, `resources/js/pages/settings/tenant-config.tsx`, `resources/js/components/sam/settings/tenant-config/types.ts`, `resources/js/components/sam/settings/tenant-config/settings-catalog.ts`

**Interfaces:**
- Consumes: prop `hos: HosConfigForm | null` (Task 2), rutas Wayfinder `tenantConfigRoutes.hos.{update,tags,preview}` (Tasks 2–3), `nav.hosConfig` (Task 1).
- Produces: `types/hos.ts` (todos los tipos HOS, también los del panel que usan las Tasks 5–7); `config-lib.ts`: `toDraft`, `serializeDraft`, `validateDraft(draft, minGap): HosDraftErrors` (llaves iguales al backend), `parseNumberList`, `ladderDraft`, `addNoticeStep`, `setEscalation`, `previewSummary`, `skippedSummary`, `tagMembersLabel`; `useHosPreview(url, selection, delayMs = 600): HosPreviewState`, `selectionKey`; `useHosTags(url): HosTagsState`; `copy.ts` con todos los textos HOS.

- [ ] **Step 1: Tipos** `resources/js/types/hos.ts`:

```ts
/** Monitoreo HOS (EE. UU.): configuración (sección de Ajustes) y panel. */

export type HosSituationKey =
    | 'break_due'
    | 'drive_limit'
    | 'shift_limit'
    | 'cycle_limit'
    | 'rest_complete'
    | 'violation';

/** Situaciones que el tenant puede apagar: la infracción siempre se vigila. */
export type HosConfigurableSituation = Exclude<HosSituationKey, 'violation'>;

export type HosUrgencyLevel = 'violation' | 'at_limit' | 'warning' | 'ok';

export interface HosLadderStep {
    afterMinutes: number;
    channels: string[];
    escalate: boolean;
}

export interface HosConfigValues {
    tagIds: string[];
    includedAssetIds: number[];
    excludedAssetIds: number[];
    situations: Record<HosConfigurableSituation, boolean>;
    /** Avisos previos en minutos, mayor primero (sin el 0 del límite). */
    leadMinutes: number[];
    cycleLeadHours: number[];
    restCompleteNudgeMinutes: number[];
    restCompleteExpireMinutes: number;
    ladder: HosLadderStep[];
}

export interface HosAssetOption {
    id: number;
    name: string;
    code: string | null;
    monitored: boolean;
}

export interface HosChannelOption {
    value: string;
    /** Hay canal de plataforma activo y el tenant no lo apagó. */
    available: boolean;
}

export interface HosConfigForm {
    config: HosConfigValues;
    defaults: HosConfigValues;
    assets: HosAssetOption[];
    channels: HosChannelOption[];
    hasIntegration: boolean;
    canManage: boolean;
    minGapMinutes: number;
}

export type HosTagKind = 'vehicle' | 'driver' | 'both' | 'empty';

export interface HosTagOption {
    id: string;
    name: string;
    parentId: string | null;
    parentName: string | null;
    depth: number;
    kind: HosTagKind;
    vehicleCount: number;
    driverCount: number;
}

export interface HosPreview {
    trucks: number;
    drivers: number;
    skipped: Record<string, number>;
    failed: boolean;
    hasIntegration: boolean;
}

export interface HosClocks {
    break: number | null;
    drive: number | null;
    shift: number | null;
    cycle: number | null;
}

export interface HosAssetSummary {
    id: number;
    name: string;
    code: string | null;
}

export interface HosStateSnapshot {
    dutyStatus: string | null;
    statusSince: string | null;
    appDisconnectedSince: string | null;
    observedAt: string;
    stale: boolean;
    asset: HosAssetSummary | null;
    clocks: HosClocks;
    violationSeconds: number;
}

export interface HosNudgeDelivery {
    channel: string | null;
    status: string;
}

export interface HosNudge {
    id: number;
    step: number | null;
    notice: string | null;
    createdAt: string | null;
    deliveries: HosNudgeDelivery[];
}

export interface HosEpisodeEntry {
    id: number;
    situation: HosSituationKey;
    openedAt: string;
    resolvedAt: string | null;
    resolution: string | null;
    ladderStep: number;
    nextNudgeAt: string | null;
    escalatedAt: string | null;
    incident: { id: number; reference: string } | null;
    nudges: HosNudge[];
}

export interface HosDriverPanelData {
    state: HosStateSnapshot | null;
    openEpisodes: HosEpisodeEntry[];
    history: HosEpisodeEntry[];
}

export interface HosFleetEpisode {
    id: number;
    situation: HosSituationKey;
    ladderStep: number;
    escalated: boolean;
    incidentId: number | null;
}

export interface HosFleetRow {
    driver: { id: number; fullName: string };
    asset: HosAssetSummary | null;
    dutyStatus: string | null;
    appDisconnected: boolean;
    observedAt: string;
    stale: boolean;
    clocks: HosClocks;
    violationSeconds: number;
    urgency: HosUrgencyLevel;
    minRemainingSeconds: number | null;
    openEpisodes: HosFleetEpisode[];
}

export type HosFleetSummary = Record<HosUrgencyLevel, number> & {
    total: number;
};

export interface HosFleetData {
    rows: HosFleetRow[];
    summary: HosFleetSummary;
}

export interface HosFleetPageProps {
    fleet: HosFleetData;
}
```

- [ ] **Step 2: Textos** `resources/js/components/sam/hos/copy.ts`:

```ts
/** Textos y catálogos del monitoreo HOS (mismo vocabulario que HosNoticeCopy). */
import type { ToneLabel } from '@/lib/tone';
import type {
    HosConfigurableSituation,
    HosSituationKey,
    HosUrgencyLevel,
} from '@/types/hos';

/** Situaciones que el tenant puede apagar, en el orden de la pantalla. */
export const HOS_SITUATIONS: {
    key: HosConfigurableSituation;
    label: string;
    help: string;
}[] = [
    {
        key: 'break_due',
        label: 'Descanso de 30 min',
        help: 'Avisa antes de que se le acabe el tiempo para tomar su descanso obligatorio de media hora.',
    },
    {
        key: 'drive_limit',
        label: '11 h de manejo',
        help: 'Avisa antes de que se le acaben sus 11 h de manejo del día.',
    },
    {
        key: 'shift_limit',
        label: 'Turno de 14 h',
        help: 'Avisa antes de que termine su turno de 14 h; después ya no puede manejar.',
    },
    {
        key: 'cycle_limit',
        label: 'Ciclo de 70 h',
        help: 'Avisa cuando le quedan pocas horas en su ciclo de 70 h en 8 días.',
    },
    {
        key: 'rest_complete',
        label: 'Descanso cumplido',
        help: 'Le avisa que ya cumplió su descanso y puede retomar su ruta.',
    },
];

export const HOS_SITUATION_LABELS: Record<HosSituationKey, string> = {
    break_due: 'Descanso de 30 min',
    drive_limit: '11 h de manejo',
    shift_limit: 'Turno de 14 h',
    cycle_limit: 'Ciclo de 70 h',
    rest_complete: 'Descanso cumplido',
    violation: 'Infracción',
};

/** Estado del chofer en Samsara (`hosStatusType`). */
export const HOS_DUTY_STATUS: Record<string, ToneLabel> = {
    driving: { label: 'Manejando', tone: 'info' },
    onDuty: { label: 'En turno, sin manejar', tone: 'neutral' },
    yardMove: { label: 'Movimiento en patio', tone: 'neutral' },
    personalConveyance: { label: 'Uso personal', tone: 'neutral' },
    offDuty: { label: 'Fuera de turno', tone: 'ok' },
    sleeperBed: { label: 'En litera', tone: 'ok' },
};

export const HOS_APP_DISCONNECTED: ToneLabel = {
    label: 'App desconectada',
    tone: 'warn',
};

export const HOS_UNKNOWN_STATUS: ToneLabel = {
    label: 'Sin estado',
    tone: 'neutral',
};

export const HOS_URGENCY: Record<HosUrgencyLevel, ToneLabel> = {
    violation: { label: 'Infracción', tone: 'critical' },
    at_limit: { label: 'En el límite', tone: 'high' },
    warning: { label: 'Por llegar al límite', tone: 'warn' },
    ok: { label: 'En regla', tone: 'ok' },
};

export const HOS_RESOLUTION: Record<string, ToneLabel> = {
    corrected: { label: 'Corregido', tone: 'ok' },
    expired: { label: 'Venció sin arrancar', tone: 'neutral' },
    unenrolled: { label: 'Salió del monitoreo', tone: 'neutral' },
};

export const HOS_CLOCK_LABELS = {
    break: 'Para el descanso de 30 min',
    drive: 'Manejo (11 h)',
    shift: 'Turno (14 h)',
    cycle: 'Ciclo (70 h)',
} as const;

/** Qué le dijo SAM (`HosNotice`), para el historial de avisos. */
export const HOS_NOTICE_LABELS: Record<string, string> = {
    break_lead: 'Aviso previo del descanso',
    break_limit: 'Ya le toca su descanso',
    break_insist: 'Insistencia: descanso',
    drive_lead: 'Aviso previo del manejo',
    drive_limit: 'Se acabaron sus 11 h de manejo',
    drive_insist: 'Insistencia: manejo',
    shift_lead: 'Aviso previo del turno',
    shift_limit: 'Se acabó su turno de 14 h',
    shift_insist: 'Insistencia: turno',
    cycle_lead: 'Aviso del ciclo de 70 h',
    rest_complete: 'Ya puede retomar',
    violation: 'Infracción',
};

/** Por qué un chofer de la lectura no entra (ResolveHosEnrollment). */
export const HOS_SKIPPED_LABELS: Record<string, string> = {
    no_vehicle: 'sin tracto asignado',
    driver_unresolved: 'chofer sin registrar en SAM',
    vehicle_unresolved: 'tracto no vigilado o sin registrar',
    excluded: 'excluidos',
    no_match: 'sin etiqueta ni selección',
};
```

- [ ] **Step 3: Tests de la lógica (fallan).** `resources/js/components/sam/hos/config-lib.test.ts`:

```ts
import { describe, expect, it } from 'vitest';
import type { HosConfigValues } from '@/types/hos';
import {
    addNoticeStep,
    ladderDraft,
    parseNumberList,
    previewSummary,
    serializeDraft,
    setEscalation,
    skippedSummary,
    tagMembersLabel,
    toDraft,
    validateDraft,
} from './config-lib';

const DEFAULTS: HosConfigValues = {
    tagIds: [],
    includedAssetIds: [],
    excludedAssetIds: [],
    situations: {
        break_due: true,
        drive_limit: true,
        shift_limit: true,
        cycle_limit: true,
        rest_complete: true,
    },
    leadMinutes: [30, 15],
    cycleLeadHours: [5, 1],
    restCompleteNudgeMinutes: [15, 30],
    restCompleteExpireMinutes: 35,
    ladder: [
        { afterMinutes: 0, channels: ['samsara_driver_app'], escalate: false },
        {
            afterMinutes: 5,
            channels: ['samsara_driver_app', 'whatsapp'],
            escalate: false,
        },
        { afterMinutes: 10, channels: ['voice'], escalate: false },
        { afterMinutes: 15, channels: [], escalate: true },
    ],
};

const step = (afterMinutes: number, channels: string[], escalate = false) =>
    ladderDraft({ afterMinutes, channels, escalate });

describe('toDraft / serializeDraft', () => {
    it('ida y vuelta de lo recomendado con la forma que valida el backend', () => {
        expect(serializeDraft(toDraft(DEFAULTS))).toEqual({
            tag_ids: [],
            included_asset_ids: [],
            excluded_asset_ids: [],
            situations: DEFAULTS.situations,
            lead_minutes: [30, 15, 0],
            cycle_lead_hours: [5, 1],
            rest_complete_nudge_minutes: [15, 30],
            rest_complete_expire_minutes: 35,
            ladder: [
                {
                    after_minutes: 0,
                    channels: ['samsara_driver_app'],
                    escalate: null,
                },
                {
                    after_minutes: 5,
                    channels: ['samsara_driver_app', 'whatsapp'],
                    escalate: null,
                },
                { after_minutes: 10, channels: ['voice'], escalate: null },
                { after_minutes: 15, channels: [], escalate: 'incident' },
            ],
        });
    });

    it('ordena los umbrales como los guarda el backend', () => {
        const payload = serializeDraft({
            ...toDraft(DEFAULTS),
            leadMinutes: '15 ,45',
            cycleLeadHours: '1, 6',
            restCompleteNudgeMinutes: '30, 10',
        });

        expect(payload.lead_minutes).toEqual([45, 15, 0]);
        expect(payload.cycle_lead_hours).toEqual([6, 1]);
        expect(payload.rest_complete_nudge_minutes).toEqual([10, 30]);
    });
});

describe('parseNumberList', () => {
    it.each([
        ['30, 15', [30, 15]],
        ['', []],
        ['  7 ', [7]],
        ['30, -5', null],
        ['1.5', null],
        ['treinta', null],
    ])('"%s"', (text, expected) => {
        expect(parseNumberList(text)).toEqual(expected);
    });
});

describe('validateDraft', () => {
    const base = () => toDraft(DEFAULTS);

    it('lo recomendado es válido', () => {
        expect(validateDraft(base(), 2)).toEqual({});
    });

    it('rechaza umbrales negativos, en cero o repetidos con la llave del backend', () => {
        expect(
            validateDraft({ ...base(), leadMinutes: '30, -5' }, 2).lead_minutes,
        ).toBeDefined();
        expect(
            validateDraft({ ...base(), cycleLeadHours: '0' }, 2)
                .cycle_lead_hours,
        ).toBeDefined();
        expect(
            validateDraft({ ...base(), restCompleteNudgeMinutes: '15, 15' }, 2)
                .rest_complete_nudge_minutes,
        ).toBeDefined();
    });

    it('deja de recordar después del último recordatorio', () => {
        expect(
            validateDraft({ ...base(), restCompleteExpireMinutes: '30' }, 2)
                .rest_complete_expire_minutes,
        ).toBe('Debe ser mayor que el último recordatorio para retomar (30 min).');
    });

    it('una unidad no puede estar en las dos listas', () => {
        expect(
            validateDraft(
                { ...base(), includedAssetIds: [4], excludedAssetIds: [4] },
                2,
            ).excluded_asset_ids,
        ).toBeDefined();
    });

    it('la escalera arranca en 0, crece al menos el mínimo y el incidente va al final', () => {
        expect(
            validateDraft({ ...base(), ladder: [step(3, ['sms'])] }, 2)[
                'ladder.0.after_minutes'
            ],
        ).toBe('El primer escalón sale al llegar al límite: debe ser 0 min.');

        expect(
            validateDraft(
                { ...base(), ladder: [step(0, ['sms']), step(1, ['voice'])] },
                2,
            )['ladder.1.after_minutes'],
        ).toBe('Deja al menos 2 min después del escalón anterior.');

        const incidentInTheMiddle = validateDraft(
            {
                ...base(),
                ladder: [
                    step(0, ['sms']),
                    step(5, [], true),
                    step(10, ['voice']),
                ],
            },
            2,
        );
        expect(incidentInTheMiddle['ladder.1.escalate']).toBe(
            'El incidente sólo puede ser el último escalón.',
        );

        expect(
            validateDraft(
                { ...base(), ladder: [step(0, ['sms']), step(5, [])] },
                2,
            )['ladder.1.channels'],
        ).toBe('Elige al menos un canal para este escalón.');

        expect(
            validateDraft({ ...base(), ladder: [step(0, [], true)] }, 2)
                .ladder,
        ).toBe('La escalera necesita al menos un escalón que le avise al chofer.');
    });
});

describe('addNoticeStep / setEscalation', () => {
    it('agrega el aviso antes del incidente y recorre el incidente', () => {
        const ladder = addNoticeStep(toDraft(DEFAULTS).ladder, ['sms']);

        expect(
            ladder.map((s) => [s.afterMinutes, s.channels, s.escalate]),
        ).toEqual([
            ['0', ['samsara_driver_app'], false],
            ['5', ['samsara_driver_app', 'whatsapp'], false],
            ['10', ['voice'], false],
            ['15', ['sms'], false],
            ['20', [], true],
        ]);
    });

    it('apaga y vuelve a prender el incidente a +5 del último aviso', () => {
        const off = setEscalation(toDraft(DEFAULTS).ladder, false);
        expect(off.some((s) => s.escalate)).toBe(false);

        const on = setEscalation(off, true);
        expect(on.at(-1)).toMatchObject({
            afterMinutes: '15',
            channels: [],
            escalate: true,
        });
    });
});

describe('textos de la vista previa', () => {
    it('cuenta tractos y choferes con singular y plural', () => {
        const base = { skipped: {}, failed: false, hasIntegration: true };

        expect(previewSummary({ ...base, trucks: 13, drivers: 43 })).toBe(
            '13 tractos · 43 choferes entran ahora',
        );
        expect(previewSummary({ ...base, trucks: 1, drivers: 1 })).toBe(
            '1 tracto · 1 chofer entra ahora',
        );
    });

    it('dice quién queda fuera y por qué', () => {
        expect(
            skippedSummary({ no_vehicle: 6, excluded: 2, no_match: 0 }),
        ).toBe('Fuera: 6 sin tracto asignado · 2 excluidos');
        expect(skippedSummary({})).toBeNull();
    });

    it('resume lo que agrupa una etiqueta', () => {
        const tag = {
            id: '1',
            name: 'USA',
            parentId: null,
            parentName: null,
            depth: 0,
            kind: 'both' as const,
            vehicleCount: 1,
            driverCount: 43,
        };

        expect(tagMembersLabel(tag)).toBe('1 tracto · 43 choferes');
        expect(tagMembersLabel({ ...tag, vehicleCount: 0, driverCount: 0 })).toBe(
            'Sin miembros',
        );
    });
});
```

`resources/js/components/sam/hos/use-hos-preview.test.ts`:

```ts
import { act, renderHook } from '@testing-library/react';
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import type { HosPreview } from '@/types/hos';
import { selectionKey, useHosPreview } from './use-hos-preview';
import type { HosPreviewSelection } from './use-hos-preview';

const fetchMock = vi.fn<typeof fetch>();

const PREVIEW: HosPreview = {
    trucks: 13,
    drivers: 43,
    skipped: { no_vehicle: 6 },
    failed: false,
    hasIntegration: true,
};

const SELECTION: HosPreviewSelection = {
    tagIds: ['4738197'],
    includedAssetIds: [],
    excludedAssetIds: [],
};

function respond(body: unknown, status = 200): Response {
    return new Response(JSON.stringify(body), {
        status,
        headers: { 'Content-Type': 'application/json' },
    });
}

async function settle(ms: number): Promise<void> {
    await act(async () => {
        await vi.advanceTimersByTimeAsync(ms);
    });
}

beforeEach(() => {
    vi.useFakeTimers();
    fetchMock.mockImplementation(async () => respond({ data: PREVIEW }));
    vi.stubGlobal('fetch', fetchMock);
});

afterEach(() => {
    vi.useRealTimers();
    vi.unstubAllGlobals();
    fetchMock.mockReset();
});

describe('useHosPreview', () => {
    it('espera a que dejen de cambiar la selección y pide una sola vez', async () => {
        const { result, rerender } = renderHook(
            ({ selection }) => useHosPreview('/preview', selection, 600),
            { initialProps: { selection: SELECTION } },
        );

        expect(result.current).toEqual({ status: 'loading', last: null });

        await settle(300);
        rerender({ selection: { ...SELECTION, includedAssetIds: [5] } });
        await settle(300);
        expect(fetchMock).not.toHaveBeenCalled();

        await settle(300);
        await settle(0);

        expect(fetchMock).toHaveBeenCalledTimes(1);
        expect(JSON.parse(String(fetchMock.mock.calls[0]?.[1]?.body))).toEqual({
            tag_ids: ['4738197'],
            included_asset_ids: [5],
            excluded_asset_ids: [],
        });
        expect(result.current).toEqual({ status: 'ready', preview: PREVIEW });
    });

    it('mientras recalcula conserva el último resultado', async () => {
        const { result, rerender } = renderHook(
            ({ selection }) => useHosPreview('/preview', selection, 600),
            { initialProps: { selection: SELECTION } },
        );
        await settle(600);
        await settle(0);

        rerender({ selection: { ...SELECTION, tagIds: [] } });

        expect(result.current).toEqual({ status: 'loading', last: PREVIEW });
    });

    it('un error del servidor se ve como error', async () => {
        fetchMock.mockImplementation(async () => respond({ message: 'x' }, 500));
        const { result } = renderHook(() =>
            useHosPreview('/preview', SELECTION, 600),
        );

        await settle(600);
        await settle(0);

        expect(result.current).toEqual({ status: 'error' });
    });

    it('sin url no pide nada', async () => {
        const { result } = renderHook(() =>
            useHosPreview(null, SELECTION, 600),
        );

        await settle(1000);

        expect(fetchMock).not.toHaveBeenCalled();
        expect(result.current).toEqual({ status: 'idle' });
    });

    it('la llave no depende del orden de la selección', () => {
        expect(
            selectionKey({
                tagIds: ['2', '1'],
                includedAssetIds: [9, 3],
                excludedAssetIds: [],
            }),
        ).toBe(
            selectionKey({
                tagIds: ['1', '2'],
                includedAssetIds: [3, 9],
                excludedAssetIds: [],
            }),
        );
    });
});
```

- [ ] **Step 4:** Run `npm test -- components/sam/hos` → FAIL (módulos inexistentes).

- [ ] **Step 5: Lógica** `resources/js/components/sam/hos/config-lib.ts`:

```ts
import { formatNumber } from '@/lib/format';
import type {
    HosConfigurableSituation,
    HosConfigValues,
    HosLadderStep,
    HosPreview,
    HosTagOption,
} from '@/types/hos';
import { HOS_SKIPPED_LABELS } from './copy';

/** Tope de escalones (UpdateHosMonitoringConfigRequest::MAX_LADDER_STEPS). */
export const HOS_MAX_LADDER_STEPS = 8;

export interface HosLadderDraft {
    /** Llave estable de React; no viaja al servidor. */
    id: number;
    afterMinutes: string;
    channels: string[];
    escalate: boolean;
}

export interface HosConfigDraft {
    tagIds: string[];
    includedAssetIds: number[];
    excludedAssetIds: number[];
    situations: Record<HosConfigurableSituation, boolean>;
    /** Listas editadas como texto: "30, 15". */
    leadMinutes: string;
    cycleLeadHours: string;
    restCompleteNudgeMinutes: string;
    restCompleteExpireMinutes: string;
    ladder: HosLadderDraft[];
}

/** Lo que recibe UpdateHosMonitoringConfigRequest. */
export type HosConfigPayload = {
    tag_ids: string[];
    included_asset_ids: number[];
    excluded_asset_ids: number[];
    situations: Record<HosConfigurableSituation, boolean>;
    lead_minutes: number[];
    cycle_lead_hours: number[];
    rest_complete_nudge_minutes: number[];
    rest_complete_expire_minutes: number;
    ladder: {
        after_minutes: number;
        channels: string[];
        escalate: 'incident' | null;
    }[];
};

/** Errores por campo con las MISMAS llaves que el backend. */
export type HosDraftErrors = Record<string, string>;

let ladderDraftId = 0;

export function ladderDraft(step: HosLadderStep): HosLadderDraft {
    ladderDraftId += 1;

    return {
        id: ladderDraftId,
        afterMinutes: String(step.afterMinutes),
        channels: [...step.channels],
        escalate: step.escalate,
    };
}

export function toDraft(config: HosConfigValues): HosConfigDraft {
    return {
        tagIds: [...config.tagIds],
        includedAssetIds: [...config.includedAssetIds],
        excludedAssetIds: [...config.excludedAssetIds],
        situations: { ...config.situations },
        leadMinutes: config.leadMinutes.join(', '),
        cycleLeadHours: config.cycleLeadHours.join(', '),
        restCompleteNudgeMinutes: config.restCompleteNudgeMinutes.join(', '),
        restCompleteExpireMinutes: String(config.restCompleteExpireMinutes),
        ladder: config.ladder.map(ladderDraft),
    };
}

/** "30, 15" → [30, 15]; vacío → []; null si algo no es un entero ≥ 0. */
export function parseNumberList(text: string): number[] | null {
    const parts = text.split(/[\s,]+/).filter((part) => part !== '');

    if (parts.some((part) => !/^\d+$/.test(part))) {
        return null;
    }

    return parts.map(Number);
}

function parseInteger(text: string): number | null {
    const trimmed = text.trim();

    return /^\d+$/.test(trimmed) ? Number(trimmed) : null;
}

function inRange(list: number[] | null, min: number, max: number): boolean {
    return (
        list !== null &&
        list.every((n) => n >= min && n <= max) &&
        new Set(list).size === list.length
    );
}

/** Las reglas de UpdateHosMonitoringConfigRequest, antes de mandar. */
export function validateDraft(
    draft: HosConfigDraft,
    minGapMinutes: number,
): HosDraftErrors {
    const errors: HosDraftErrors = {};
    const leads = parseNumberList(draft.leadMinutes);
    const cycle = parseNumberList(draft.cycleLeadHours);
    const nudges = parseNumberList(draft.restCompleteNudgeMinutes);
    const expire = parseInteger(draft.restCompleteExpireMinutes);

    if (!inRange(leads, 1, 240) || (leads ?? []).length > 4) {
        errors.lead_minutes =
            'Escribe minutos entre 1 y 240, sin repetir, separados por comas.';
    }

    if (!inRange(cycle, 1, 69) || (cycle ?? []).length === 0) {
        errors.cycle_lead_hours =
            'Escribe horas entre 1 y 69, sin repetir, separadas por comas.';
    }

    const nudgesOk = inRange(nudges, 1, 180) && (nudges ?? []).length > 0;

    if (!nudgesOk) {
        errors.rest_complete_nudge_minutes =
            'Escribe minutos entre 1 y 180, sin repetir, separados por comas.';
    }

    if (expire === null || expire < 2 || expire > 240) {
        errors.rest_complete_expire_minutes = 'Escribe minutos entre 2 y 240.';
    } else if (nudgesOk && nudges !== null && expire <= Math.max(...nudges)) {
        errors.rest_complete_expire_minutes = `Debe ser mayor que el último recordatorio para retomar (${Math.max(...nudges)} min).`;
    }

    if (
        draft.includedAssetIds.some((id) => draft.excludedAssetIds.includes(id))
    ) {
        errors.excluded_asset_ids =
            'Una unidad no puede estar en "siempre entran" y en "nunca entran" a la vez.';
    }

    let previous: number | null = null;
    const last = draft.ladder.length - 1;

    draft.ladder.forEach((step, index) => {
        const key = `ladder.${index}`;
        const after = parseInteger(step.afterMinutes);

        if (after === null || after > 240) {
            errors[`${key}.after_minutes`] =
                'Escribe minutos enteros entre 0 y 240.';
        } else if (index === 0 && after !== 0) {
            errors[`${key}.after_minutes`] =
                'El primer escalón sale al llegar al límite: debe ser 0 min.';
        } else if (previous !== null && after - previous < minGapMinutes) {
            errors[`${key}.after_minutes`] =
                `Deja al menos ${minGapMinutes} min después del escalón anterior.`;
        }

        if (step.escalate && index !== last) {
            errors[`${key}.escalate`] =
                'El incidente sólo puede ser el último escalón.';
        }

        if (!step.escalate && step.channels.length === 0) {
            errors[`${key}.channels`] =
                'Elige al menos un canal para este escalón.';
        }

        previous = after ?? previous;
    });

    if (draft.ladder.length > HOS_MAX_LADDER_STEPS) {
        errors.ladder = `La escalera admite hasta ${HOS_MAX_LADDER_STEPS} escalones.`;
    } else if (
        !draft.ladder.some((step) => !step.escalate && step.channels.length > 0)
    ) {
        errors.ladder =
            'La escalera necesita al menos un escalón que le avise al chofer.';
    }

    return errors;
}

/** Sólo después de `validateDraft` sin errores. */
export function serializeDraft(draft: HosConfigDraft): HosConfigPayload {
    const positive = (text: string) =>
        (parseNumberList(text) ?? []).filter((n) => n > 0);

    return {
        tag_ids: draft.tagIds,
        included_asset_ids: draft.includedAssetIds,
        excluded_asset_ids: draft.excludedAssetIds,
        situations: draft.situations,
        // El 0 (llegar al límite) siempre va: es el que arranca la escalera.
        lead_minutes: [...positive(draft.leadMinutes).sort((a, b) => b - a), 0],
        cycle_lead_hours: positive(draft.cycleLeadHours).sort((a, b) => b - a),
        rest_complete_nudge_minutes: positive(
            draft.restCompleteNudgeMinutes,
        ).sort((a, b) => a - b),
        rest_complete_expire_minutes: Number(
            draft.restCompleteExpireMinutes.trim(),
        ),
        ladder: draft.ladder.map((step) => ({
            after_minutes: Number(step.afterMinutes.trim()),
            channels: step.escalate ? [] : step.channels,
            escalate: step.escalate ? 'incident' : null,
        })),
    };
}

function minutesOf(step: HosLadderDraft | undefined): number {
    return step === undefined ? 0 : Number(step.afterMinutes) || 0;
}

/** Agrega un aviso a +5 min del último, antes del incidente (que se recorre). */
export function addNoticeStep(
    ladder: HosLadderDraft[],
    channels: string[],
): HosLadderDraft[] {
    const notices = ladder.filter((step) => !step.escalate);
    const escalation = ladder.find((step) => step.escalate);
    const after = notices.length === 0 ? 0 : minutesOf(notices.at(-1)) + 5;
    const added = ladderDraft({ afterMinutes: after, channels, escalate: false });

    if (escalation === undefined) {
        return [...notices, added];
    }

    return [
        ...notices,
        added,
        {
            ...escalation,
            afterMinutes: String(Math.max(minutesOf(escalation), after + 5)),
        },
    ];
}

/** Prende (a +5 min del último aviso) o apaga el escalón final de incidente. */
export function setEscalation(
    ladder: HosLadderDraft[],
    on: boolean,
): HosLadderDraft[] {
    const notices = ladder.filter((step) => !step.escalate);

    if (!on) {
        return notices;
    }

    if (ladder.some((step) => step.escalate)) {
        return ladder;
    }

    return [
        ...notices,
        ladderDraft({
            afterMinutes: minutesOf(notices.at(-1)) + 5,
            channels: [],
            escalate: true,
        }),
    ];
}

/** "13 tractos · 43 choferes entran ahora". */
export function previewSummary(preview: HosPreview): string {
    const trucks = `${formatNumber(preview.trucks)} ${preview.trucks === 1 ? 'tracto' : 'tractos'}`;
    const drivers = `${formatNumber(preview.drivers)} ${preview.drivers === 1 ? 'chofer entra' : 'choferes entran'}`;

    return `${trucks} · ${drivers} ahora`;
}

/** "Fuera: 6 sin tracto asignado · 2 excluidos", o null si nadie queda fuera. */
export function skippedSummary(skipped: Record<string, number>): string | null {
    const parts = Object.keys(HOS_SKIPPED_LABELS)
        .filter((reason) => (skipped[reason] ?? 0) > 0)
        .map(
            (reason) =>
                `${formatNumber(skipped[reason] ?? 0)} ${HOS_SKIPPED_LABELS[reason]}`,
        );

    return parts.length === 0 ? null : `Fuera: ${parts.join(' · ')}`;
}

/** "1 tracto · 43 choferes" para el selector de etiquetas. */
export function tagMembersLabel(tag: HosTagOption): string {
    const parts = [
        tag.vehicleCount > 0
            ? `${formatNumber(tag.vehicleCount)} ${tag.vehicleCount === 1 ? 'tracto' : 'tractos'}`
            : null,
        tag.driverCount > 0
            ? `${formatNumber(tag.driverCount)} ${tag.driverCount === 1 ? 'chofer' : 'choferes'}`
            : null,
    ].filter((part): part is string => part !== null);

    return parts.length === 0 ? 'Sin miembros' : parts.join(' · ');
}
```

- [ ] **Step 6: Hooks.** `resources/js/components/sam/hos/use-hos-preview.ts`:

```ts
import { useEffect, useState } from 'react';
import { postJson } from '@/lib/sam-fetch';
import type { HosPreview } from '@/types/hos';

export interface HosPreviewSelection {
    tagIds: string[];
    includedAssetIds: number[];
    excludedAssetIds: number[];
}

export type HosPreviewState =
    | { status: 'idle' }
    | { status: 'loading'; last: HosPreview | null }
    | { status: 'ready'; preview: HosPreview }
    | { status: 'error' };

/** Cuerpo de la petición, ordenado: la misma selección da la misma llave. */
export function selectionKey(selection: HosPreviewSelection): string {
    return JSON.stringify({
        tag_ids: [...selection.tagIds].sort(),
        included_asset_ids: [...selection.includedAssetIds].sort((a, b) => a - b),
        excluded_asset_ids: [...selection.excludedAssetIds].sort((a, b) => a - b),
    });
}

/** Fuera del hook: el compilador no admite try/catch con await dentro. */
async function fetchHosPreview(
    url: string,
    body: Record<string, unknown>,
    signal: AbortSignal,
): Promise<HosPreview | null> {
    try {
        const response = await postJson(url, body, signal);

        if (!response.ok) {
            return null;
        }

        const json = (await response.json()) as { data: HosPreview };

        return json.data;
    } catch {
        return null;
    }
}

/**
 * "N tractos · M choferes entran ahora" de la selección en borrador. Pide
 * `delayMs` después del último cambio y cancela la petición anterior; el
 * servidor calcula sobre la última lectura del sondeo (sin ir a Samsara).
 */
export function useHosPreview(
    url: string | null,
    selection: HosPreviewSelection,
    delayMs = 600,
): HosPreviewState {
    const key = selectionKey(selection);
    const [settled, setSettled] = useState<{
        key: string;
        preview: HosPreview | null;
    } | null>(null);

    useEffect(() => {
        if (url === null) {
            return;
        }

        const controller = new AbortController();
        const timer = window.setTimeout(() => {
            void fetchHosPreview(
                url,
                JSON.parse(key) as Record<string, unknown>,
                controller.signal,
            ).then((preview) => {
                if (!controller.signal.aborted) {
                    setSettled({ key, preview });
                }
            });
        }, delayMs);

        return () => {
            window.clearTimeout(timer);
            controller.abort();
        };
    }, [url, key, delayMs]);

    if (url === null) {
        return { status: 'idle' };
    }

    if (settled === null || settled.key !== key) {
        return { status: 'loading', last: settled?.preview ?? null };
    }

    return settled.preview === null
        ? { status: 'error' }
        : { status: 'ready', preview: settled.preview };
}
```

`resources/js/components/sam/hos/use-hos-tags.ts`:

```ts
import { useEffect, useState } from 'react';
import { getJson } from '@/lib/sam-fetch';
import type { HosTagOption } from '@/types/hos';

export type HosTagsState =
    | { status: 'loading' }
    | { status: 'error' }
    | {
          status: 'ready';
          tags: HosTagOption[];
          failed: boolean;
          hasIntegration: boolean;
      };

async function fetchHosTags(
    url: string,
    signal: AbortSignal,
): Promise<HosTagsState> {
    try {
        const response = await getJson(url, signal);

        if (!response.ok) {
            return { status: 'error' };
        }

        const body = (await response.json()) as {
            data: HosTagOption[];
            meta: { failed: boolean; hasIntegration: boolean };
        };

        return {
            status: 'ready',
            tags: body.data,
            failed: body.meta.failed,
            hasIntegration: body.meta.hasIntegration,
        };
    } catch {
        return { status: 'error' };
    }
}

/** Etiquetas de Samsara del tenant (caché del servidor compartida con el sondeo). */
export function useHosTags(url: string | null): HosTagsState {
    const [settled, setSettled] = useState<{
        url: string;
        state: HosTagsState;
    } | null>(null);

    useEffect(() => {
        if (url === null) {
            return;
        }

        const controller = new AbortController();

        void fetchHosTags(url, controller.signal).then((state) => {
            if (!controller.signal.aborted) {
                setSettled({ url, state });
            }
        });

        return () => controller.abort();
    }, [url]);

    if (url === null) {
        return { status: 'error' };
    }

    return settled?.url === url ? settled.state : { status: 'loading' };
}
```

- [ ] **Step 7:** Run `npm test -- components/sam/hos` → PASS.

- [ ] **Step 8: Selectores y escalera.** `resources/js/components/sam/hos/hos-tag-picker.tsx`:

```tsx
import { useState } from 'react';
import { Checkbox } from '@/components/ui/checkbox';
import { Input } from '@/components/ui/input';
import { Skeleton } from '@/components/ui/skeleton';
import { cn } from '@/lib/utils';
import type { HosTagOption } from '@/types/hos';
import { tagMembersLabel } from './config-lib';
import type { HosTagsState } from './use-hos-tags';

const DEPTH_INDENT = ['pl-3', 'pl-8', 'pl-12'];

export interface HosTagPickerProps {
    tags: HosTagsState;
    selected: string[];
    disabled: boolean;
    onChange: (next: string[]) => void;
}

/** Etiquetas de Samsara con casillas; elegir una madre incluye a sus hijas. */
export function HosTagPicker({
    tags,
    selected,
    disabled,
    onChange,
}: HosTagPickerProps) {
    const [query, setQuery] = useState('');

    if (tags.status === 'loading') {
        return (
            <div
                className="flex flex-col gap-2"
                aria-busy="true"
                aria-label="Cargando etiquetas"
            >
                <Skeleton className="h-8 w-full" />
                <Skeleton className="h-32 w-full rounded-md" />
            </div>
        );
    }

    if (tags.status === 'error' || tags.failed) {
        return (
            <p className="text-xs text-fg-3">
                No pudimos leer las etiquetas de Samsara. Vuelve a intentarlo
                en unos minutos; lo que ya elegiste se conserva.
            </p>
        );
    }

    if (!tags.hasIntegration) {
        return (
            <p className="text-xs text-fg-3">
                Conecta tu integración con Samsara para elegir etiquetas.
            </p>
        );
    }

    const known = new Set(tags.tags.map((tag) => tag.id));
    const missing = selected.filter((id) => !known.has(id));
    const needle = query.trim().toLowerCase();
    const visible =
        needle === ''
            ? tags.tags
            : tags.tags.filter((tag) => tag.name.toLowerCase().includes(needle));
    const toggle = (id: string) =>
        onChange(
            selected.includes(id)
                ? selected.filter((value) => value !== id)
                : [...selected, id],
        );

    return (
        <div className="flex flex-col gap-2">
            <Input
                type="search"
                value={query}
                onChange={(event) => setQuery(event.target.value)}
                placeholder="Buscar etiqueta…"
                aria-label="Buscar etiqueta"
                className="h-8"
            />
            <ul className="max-h-72 overflow-y-auto rounded-md border border-border bg-surface-2">
                {missing.map((id) => (
                    <MissingTagRow
                        key={id}
                        id={id}
                        disabled={disabled}
                        onToggle={() => toggle(id)}
                    />
                ))}
                {visible.map((tag) => (
                    <TagRow
                        key={tag.id}
                        tag={tag}
                        checked={selected.includes(tag.id)}
                        disabled={disabled}
                        onToggle={() => toggle(tag.id)}
                    />
                ))}
                {visible.length === 0 && missing.length === 0 ? (
                    <li className="px-3 py-4 text-center text-xs text-fg-3">
                        Sin etiquetas que coincidan.
                    </li>
                ) : null}
            </ul>
            <p className="text-2xs text-fg-3">
                Elegir una etiqueta incluye también sus subetiquetas.
            </p>
        </div>
    );
}

function TagRow({
    tag,
    checked,
    disabled,
    onToggle,
}: {
    tag: HosTagOption;
    checked: boolean;
    disabled: boolean;
    onToggle: () => void;
}) {
    const id = `hos-tag-${tag.id}`;

    return (
        <li
            className={cn(
                'flex items-center gap-2 border-b border-border py-2 pr-3 last:border-b-0',
                DEPTH_INDENT[Math.min(tag.depth, DEPTH_INDENT.length - 1)],
            )}
        >
            <Checkbox
                id={id}
                checked={checked}
                disabled={disabled}
                onCheckedChange={onToggle}
            />
            <label
                htmlFor={id}
                className="flex min-w-0 flex-1 items-center justify-between gap-2 text-sm text-fg-1"
            >
                <span className="truncate">{tag.name}</span>
                <span className="shrink-0 text-2xs text-fg-3">
                    {tagMembersLabel(tag)}
                </span>
            </label>
        </li>
    );
}

function MissingTagRow({
    id,
    disabled,
    onToggle,
}: {
    id: string;
    disabled: boolean;
    onToggle: () => void;
}) {
    const inputId = `hos-tag-missing-${id}`;

    return (
        <li className="flex items-center gap-2 border-b border-border py-2 pr-3 pl-3">
            <Checkbox
                id={inputId}
                checked
                disabled={disabled}
                onCheckedChange={onToggle}
            />
            <label htmlFor={inputId} className="text-sm text-fg-1">
                Etiqueta {id}{' '}
                <span className="text-fg-3">(ya no existe en Samsara)</span>
            </label>
        </li>
    );
}
```

`resources/js/components/sam/hos/hos-asset-picker.tsx`:

```tsx
import { X } from 'lucide-react';
import { Button } from '@/components/ui/button';
import { Combobox } from '@/components/ui/combobox';
import type { HosAssetOption } from '@/types/hos';

export interface HosAssetPickerProps {
    id: string;
    assets: HosAssetOption[];
    selected: number[];
    /** Unidades ya elegidas en la otra lista: no se ofrecen aquí. */
    taken: number[];
    disabled: boolean;
    placeholder: string;
    /** Aviso por unidad elegida (p. ej. "no vigilada: no entra"). */
    noteFor?: (asset: HosAssetOption) => string | null;
    onChange: (next: number[]) => void;
}

/** Buscador de unidades del team + las elegidas como fichas quitables. */
export function HosAssetPicker({
    id,
    assets,
    selected,
    taken,
    disabled,
    placeholder,
    noteFor,
    onChange,
}: HosAssetPickerProps) {
    const byId = new Map(assets.map((asset) => [asset.id, asset]));
    const blocked = new Set([...selected, ...taken]);
    const options = assets
        .filter((asset) => !blocked.has(asset.id))
        .map((asset) => ({
            value: String(asset.id),
            label: asset.name,
            description: asset.code ?? undefined,
        }));

    return (
        <div className="flex flex-col gap-2">
            <Combobox
                id={id}
                options={options}
                value={null}
                onChange={(value) => {
                    if (value !== null) {
                        onChange([...selected, Number(value)]);
                    }
                }}
                placeholder={placeholder}
                emptyText="No hay más unidades"
                disabled={disabled}
            />
            {selected.length > 0 ? (
                <ul className="flex flex-wrap gap-1.5">
                    {selected.map((assetId) => {
                        const asset = byId.get(assetId) ?? null;

                        return (
                            <AssetChip
                                key={assetId}
                                name={asset?.name ?? `Unidad ${assetId}`}
                                note={asset && noteFor ? noteFor(asset) : null}
                                disabled={disabled}
                                onRemove={() =>
                                    onChange(
                                        selected.filter(
                                            (value) => value !== assetId,
                                        ),
                                    )
                                }
                            />
                        );
                    })}
                </ul>
            ) : null}
        </div>
    );
}

function AssetChip({
    name,
    note,
    disabled,
    onRemove,
}: {
    name: string;
    note: string | null;
    disabled: boolean;
    onRemove: () => void;
}) {
    return (
        <li className="inline-flex items-center gap-1 rounded-md border border-border bg-surface-2 py-0.5 pr-0.5 pl-2 text-xs text-fg-1">
            <span>{name}</span>
            {note ? <span className="text-fg-3">· {note}</span> : null}
            <Button
                type="button"
                variant="ghost"
                size="icon"
                className="size-6"
                aria-label={`Quitar ${name}`}
                disabled={disabled}
                onClick={onRemove}
            >
                <X className="size-3.5" />
            </Button>
        </li>
    );
}
```

`resources/js/components/sam/hos/hos-ladder-editor.tsx`:

```tsx
import { Plus, Trash2 } from 'lucide-react';
import InputError from '@/components/input-error';
import { ChipToggle } from '@/components/sam/settings/controls';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Switch } from '@/components/ui/switch';
import { channelLabel } from '@/lib/labels';
import type { HosChannelOption } from '@/types/hos';
import {
    addNoticeStep,
    HOS_MAX_LADDER_STEPS,
    setEscalation,
} from './config-lib';
import type { HosDraftErrors, HosLadderDraft } from './config-lib';

export interface HosLadderEditorProps {
    ladder: HosLadderDraft[];
    channels: HosChannelOption[];
    errors: HosDraftErrors;
    disabled: boolean;
    onChange: (ladder: HosLadderDraft[]) => void;
}

/** Escalones de insistencia al chofer y, al final, el incidente al equipo. */
export function HosLadderEditor({
    ladder,
    channels,
    errors,
    disabled,
    onChange,
}: HosLadderEditorProps) {
    const escalates = ladder.some((step) => step.escalate);
    const notices = ladder.filter((step) => !step.escalate).length;
    const defaultChannels = channels
        .filter((channel) => channel.available)
        .slice(0, 1)
        .map((channel) => channel.value);

    return (
        <div className="flex flex-col gap-3">
            <ol className="flex flex-col gap-3">
                {ladder.map((step, index) => (
                    <LadderStepRow
                        key={step.id}
                        step={step}
                        index={index}
                        channels={channels}
                        errors={errors}
                        disabled={disabled}
                        canRemove={!step.escalate && notices > 1}
                        onChange={(next) =>
                            onChange(
                                ladder.map((current, i) =>
                                    i === index ? next : current,
                                ),
                            )
                        }
                        onRemove={() =>
                            onChange(ladder.filter((_, i) => i !== index))
                        }
                    />
                ))}
            </ol>
            <InputError message={errors.ladder} />
            <div className="flex flex-wrap items-center justify-between gap-3">
                <Button
                    type="button"
                    variant="outline"
                    size="sm"
                    disabled={disabled || ladder.length >= HOS_MAX_LADDER_STEPS}
                    onClick={() =>
                        onChange(addNoticeStep(ladder, defaultChannels))
                    }
                >
                    <Plus className="size-3.5" />
                    Agregar escalón
                </Button>
                <label
                    htmlFor="hos-ladder-escalate"
                    className="flex items-center gap-2.5 text-sm text-fg-2"
                >
                    <Switch
                        id="hos-ladder-escalate"
                        checked={escalates}
                        disabled={disabled}
                        onCheckedChange={(on) =>
                            onChange(setEscalation(ladder, on))
                        }
                    />
                    Si no corrige, abrir un incidente para tu equipo
                </label>
            </div>
        </div>
    );
}

function LadderStepRow({
    step,
    index,
    channels,
    errors,
    disabled,
    canRemove,
    onChange,
    onRemove,
}: {
    step: HosLadderDraft;
    index: number;
    channels: HosChannelOption[];
    errors: HosDraftErrors;
    disabled: boolean;
    canRemove: boolean;
    onChange: (step: HosLadderDraft) => void;
    onRemove: () => void;
}) {
    const key = `ladder.${index}`;
    const inputId = `hos-ladder-${step.id}-after`;
    const toggle = (value: string) =>
        onChange({
            ...step,
            channels: step.channels.includes(value)
                ? step.channels.filter((channel) => channel !== value)
                : [...step.channels, value],
        });

    return (
        <li className="flex gap-3 rounded-md border border-border bg-surface-2 p-3">
            <span className="grid size-6 shrink-0 place-items-center rounded-full bg-primary/15 text-2xs font-semibold text-primary tabular-nums">
                {index + 1}
            </span>
            <div className="flex min-w-0 flex-1 flex-col gap-2">
                <label
                    htmlFor={inputId}
                    className="flex flex-wrap items-center gap-2 text-xs text-fg-2"
                >
                    {index === 0
                        ? 'Al llegar al límite'
                        : 'Minutos después del límite'}
                    <Input
                        id={inputId}
                        type="number"
                        min="0"
                        value={step.afterMinutes}
                        disabled={disabled || index === 0}
                        aria-invalid={
                            errors[`${key}.after_minutes`] !== undefined
                        }
                        onChange={(event) =>
                            onChange({
                                ...step,
                                afterMinutes: event.target.value,
                            })
                        }
                        className="h-8 w-20 tabular-nums"
                    />
                    <span className="text-fg-3">min</span>
                </label>
                <InputError message={errors[`${key}.after_minutes`]} />
                {step.escalate ? (
                    <p className="text-xs text-fg-2">
                        Abre un incidente de HOS para tu equipo de monitoreo
                        (sigue su escalamiento de siempre).
                    </p>
                ) : (
                    <div
                        className="flex flex-wrap gap-1.5"
                        role="group"
                        aria-label={`Canales del escalón ${index + 1}`}
                    >
                        {channels.map((channel) => {
                            const active = step.channels.includes(
                                channel.value,
                            );

                            return (
                                <ChipToggle
                                    key={channel.value}
                                    active={active}
                                    disabled={
                                        disabled ||
                                        (!channel.available && !active)
                                    }
                                    label={
                                        channel.available
                                            ? channelLabel(channel.value)
                                            : `${channelLabel(channel.value)} (no disponible)`
                                    }
                                    onToggle={() => toggle(channel.value)}
                                >
                                    {channelLabel(channel.value)}
                                </ChipToggle>
                            );
                        })}
                    </div>
                )}
                <InputError
                    message={
                        errors[`${key}.channels`] ?? errors[`${key}.escalate`]
                    }
                />
            </div>
            {canRemove ? (
                <Button
                    type="button"
                    variant="ghost"
                    size="icon"
                    className="size-8"
                    aria-label={`Quitar escalón ${index + 1}`}
                    disabled={disabled}
                    onClick={onRemove}
                >
                    <Trash2 className="size-3.5" />
                </Button>
            ) : null}
        </li>
    );
}
```

- [ ] **Step 9: Sección** `resources/js/components/sam/settings/tenant-config/hos-section.tsx`:

```tsx
import { usePage } from '@inertiajs/react';
import { useState } from 'react';
import { Field, FormCard } from '@/components/sam/field';
import {
    previewSummary,
    serializeDraft,
    skippedSummary,
    toDraft,
    validateDraft,
} from '@/components/sam/hos/config-lib';
import type {
    HosConfigDraft,
    HosDraftErrors,
} from '@/components/sam/hos/config-lib';
import { HOS_SITUATIONS } from '@/components/sam/hos/copy';
import { HosAssetPicker } from '@/components/sam/hos/hos-asset-picker';
import { HosLadderEditor } from '@/components/sam/hos/hos-ladder-editor';
import { HosTagPicker } from '@/components/sam/hos/hos-tag-picker';
import { useHosPreview } from '@/components/sam/hos/use-hos-preview';
import type { HosPreviewState } from '@/components/sam/hos/use-hos-preview';
import { useHosTags } from '@/components/sam/hos/use-hos-tags';
import {
    FormActions,
    SettingsSection,
} from '@/components/sam/settings/settings-page';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Spinner } from '@/components/ui/spinner';
import { Switch } from '@/components/ui/switch';
import { putJson } from '@/lib/sam-fetch';
import { submit } from '@/lib/submit';
import { cn } from '@/lib/utils';
import tenantConfigRoutes from '@/routes/tenant-config';
import type { HosConfigForm } from '@/types/hos';
import { CONFIG_SUBMIT } from './shared';

/**
 * Monitoreo HOS (EE. UU.): quién entra (con vista previa), qué se vigila,
 * cuándo se avisa y cómo se le insiste al chofer. Se guarda completo en
 * `hos.monitoring` por su propio endpoint (UpdateHosMonitoringConfigRequest).
 */
export function HosSection({ form }: { form: HosConfigForm }) {
    const teamSlug = usePage().props.currentTeam?.slug ?? null;
    const [draft, setDraft] = useState<HosConfigDraft>(() =>
        toDraft(form.config),
    );
    const [errors, setErrors] = useState<HosDraftErrors>({});
    const [saving, setSaving] = useState(false);
    const disabled = !form.canManage || saving;

    const tags = useHosTags(
        teamSlug === null ? null : tenantConfigRoutes.hos.tags.url(teamSlug),
    );
    const preview = useHosPreview(
        teamSlug === null || !form.hasIntegration
            ? null
            : tenantConfigRoutes.hos.preview.url(teamSlug),
        {
            tagIds: draft.tagIds,
            includedAssetIds: draft.includedAssetIds,
            excludedAssetIds: draft.excludedAssetIds,
        },
    );

    const update = (patch: Partial<HosConfigDraft>) =>
        setDraft((current) => ({ ...current, ...patch }));

    const useRecommended = () => {
        // Sólo avisos y escalera: quién entra se queda como está.
        setDraft((current) => ({
            ...toDraft(form.defaults),
            tagIds: current.tagIds,
            includedAssetIds: current.includedAssetIds,
            excludedAssetIds: current.excludedAssetIds,
        }));
        setErrors({});
    };

    const save = async () => {
        if (teamSlug === null || saving) {
            return;
        }

        const found = validateDraft(draft, form.minGapMinutes);
        setErrors(found);

        if (Object.keys(found).length > 0) {
            return;
        }

        setSaving(true);
        const result = await submit(
            putJson(
                tenantConfigRoutes.hos.update.url(teamSlug),
                serializeDraft(draft),
            ),
            'Monitoreo HOS guardado.',
            { ...CONFIG_SUBMIT, only: ['hos', 'versions'] },
        );
        setErrors(result.fieldErrors);
        setSaving(false);
    };

    return (
        <>
            <SettingsSection
                title="Quién entra al monitoreo"
                description="Sólo choferes que van en un tracto vigilado. Elige por etiquetas de Samsara y agrega o quita unidades a mano."
            >
                <FormCard>
                    <Field
                        label="Etiquetas de Samsara"
                        help="Entran los choferes con la etiqueta o que manejan un tracto que la tiene."
                        error={errors.tag_ids}
                    >
                        <HosTagPicker
                            tags={tags}
                            selected={draft.tagIds}
                            disabled={disabled}
                            onChange={(tagIds) => update({ tagIds })}
                        />
                    </Field>
                    <Field
                        label="Unidades que siempre entran"
                        help="Aunque no tengan la etiqueta."
                        htmlFor="hos-included"
                        error={errors.included_asset_ids}
                    >
                        <HosAssetPicker
                            id="hos-included"
                            assets={form.assets}
                            selected={draft.includedAssetIds}
                            taken={draft.excludedAssetIds}
                            disabled={disabled}
                            placeholder="Agregar unidad…"
                            noteFor={(asset) =>
                                asset.monitored ? null : 'no vigilada: no entra'
                            }
                            onChange={(includedAssetIds) =>
                                update({ includedAssetIds })
                            }
                        />
                    </Field>
                    <Field
                        label="Unidades que nunca entran"
                        help="Ganan sobre las etiquetas y las unidades agregadas."
                        htmlFor="hos-excluded"
                        error={errors.excluded_asset_ids}
                    >
                        <HosAssetPicker
                            id="hos-excluded"
                            assets={form.assets}
                            selected={draft.excludedAssetIds}
                            taken={draft.includedAssetIds}
                            disabled={disabled}
                            placeholder="Agregar unidad…"
                            onChange={(excludedAssetIds) =>
                                update({ excludedAssetIds })
                            }
                        />
                    </Field>
                    <PreviewLine
                        preview={preview}
                        hasIntegration={form.hasIntegration}
                    />
                </FormCard>
            </SettingsSection>

            <SettingsSection
                title="Qué vigila SAM"
                description="Las infracciones que ya marca Samsara siempre se vigilan y abren incidente."
            >
                <FormCard>
                    {HOS_SITUATIONS.map((situation) => (
                        <Field
                            key={situation.key}
                            label={situation.label}
                            help={situation.help}
                            htmlFor={`hos-situation-${situation.key}`}
                        >
                            <div className="flex items-center gap-2.5">
                                <Switch
                                    id={`hos-situation-${situation.key}`}
                                    checked={draft.situations[situation.key]}
                                    disabled={disabled}
                                    onCheckedChange={(on) =>
                                        update({
                                            situations: {
                                                ...draft.situations,
                                                [situation.key]: on,
                                            },
                                        })
                                    }
                                />
                                <span className="text-sm text-fg-2">
                                    {draft.situations[situation.key]
                                        ? 'Activado'
                                        : 'Desactivado'}
                                </span>
                            </div>
                        </Field>
                    ))}
                </FormCard>
            </SettingsSection>

            <SettingsSection
                title="Cuándo avisa"
                description="Los avisos previos sólo informan; al llegar al límite empieza la escalera de insistencia."
            >
                <FormCard>
                    <Field
                        label="Avisos antes del límite"
                        help="Minutos antes de que se acabe el descanso, el manejo o el turno. Separa con comas."
                        htmlFor="hos-lead"
                        error={errors.lead_minutes}
                    >
                        <Input
                            id="hos-lead"
                            inputMode="numeric"
                            value={draft.leadMinutes}
                            disabled={disabled}
                            onChange={(event) =>
                                update({ leadMinutes: event.target.value })
                            }
                            className="h-8 w-40"
                        />
                    </Field>
                    <Field
                        label="Avisos del ciclo de 70 h"
                        help="Horas que le quedan en el ciclo. Separa con comas."
                        htmlFor="hos-cycle"
                        error={errors.cycle_lead_hours}
                    >
                        <Input
                            id="hos-cycle"
                            inputMode="numeric"
                            value={draft.cycleLeadHours}
                            disabled={disabled}
                            onChange={(event) =>
                                update({ cycleLeadHours: event.target.value })
                            }
                            className="h-8 w-40"
                        />
                    </Field>
                    <Field
                        label="Recordatorios para retomar"
                        help="Minutos después de cumplir su descanso, si todavía no arranca. Separa con comas."
                        htmlFor="hos-rest"
                        error={errors.rest_complete_nudge_minutes}
                    >
                        <Input
                            id="hos-rest"
                            inputMode="numeric"
                            value={draft.restCompleteNudgeMinutes}
                            disabled={disabled}
                            onChange={(event) =>
                                update({
                                    restCompleteNudgeMinutes:
                                        event.target.value,
                                })
                            }
                            className="h-8 w-40"
                        />
                    </Field>
                    <Field
                        label="Dejar de recordar a los"
                        help="Minutos después de cumplir su descanso. Debe ser mayor que el último recordatorio."
                        htmlFor="hos-rest-expire"
                        error={errors.rest_complete_expire_minutes}
                    >
                        <span className="flex items-center gap-2">
                            <Input
                                id="hos-rest-expire"
                                type="number"
                                min="2"
                                value={draft.restCompleteExpireMinutes}
                                disabled={disabled}
                                onChange={(event) =>
                                    update({
                                        restCompleteExpireMinutes:
                                            event.target.value,
                                    })
                                }
                                className="h-8 w-20 tabular-nums"
                            />
                            <span className="text-xs text-fg-3">min</span>
                        </span>
                    </Field>
                </FormCard>
            </SettingsSection>

            <SettingsSection
                title="Escalera de insistencia"
                description="Qué hace SAM, y por dónde, mientras el chofer no corrige. Se pausa en cuanto se detiene."
            >
                <FormCard>
                    <HosLadderEditor
                        ladder={draft.ladder}
                        channels={form.channels}
                        errors={errors}
                        disabled={disabled}
                        onChange={(ladder) => update({ ladder })}
                    />
                    {form.canManage ? (
                        <FormActions hint="Los cambios aplican desde el siguiente minuto.">
                            <Button
                                type="button"
                                variant="ghost"
                                size="sm"
                                disabled={saving}
                                onClick={useRecommended}
                            >
                                Avisos y escalera recomendados
                            </Button>
                            <Button
                                type="button"
                                size="sm"
                                disabled={saving}
                                onClick={() => void save()}
                            >
                                {saving ? (
                                    <Spinner className="size-3.5" />
                                ) : null}
                                Guardar
                            </Button>
                        </FormActions>
                    ) : null}
                </FormCard>
            </SettingsSection>
        </>
    );
}

function PreviewLine({
    preview,
    hasIntegration,
}: {
    preview: HosPreviewState;
    hasIntegration: boolean;
}) {
    if (!hasIntegration) {
        return (
            <p className="text-xs text-fg-3">
                Conecta tu integración con Samsara para ver quién entra.
            </p>
        );
    }

    if (preview.status === 'error') {
        return (
            <p className="text-xs text-fg-3">
                No pudimos calcular quién entra ahora. Tu configuración se
                puede guardar igual.
            </p>
        );
    }

    const current =
        preview.status === 'ready'
            ? preview.preview
            : preview.status === 'loading'
              ? preview.last
              : null;

    if (current === null) {
        return (
            <p
                className="flex items-center gap-2 text-xs text-fg-3"
                aria-live="polite"
            >
                <Spinner className="size-3.5" />
                Calculando quién entra…
            </p>
        );
    }

    if (current.failed) {
        return (
            <p className="text-xs text-fg-3" aria-live="polite">
                Samsara no respondió; vuelve a intentarlo en un minuto.
            </p>
        );
    }

    const skipped = skippedSummary(current.skipped);

    return (
        <div
            aria-live="polite"
            className={cn(
                'flex flex-col gap-0.5 rounded-md border border-border bg-surface-2 px-3 py-2',
                preview.status === 'loading' && 'opacity-60',
            )}
        >
            <span className="text-sm font-semibold text-fg-1 tabular-nums">
                {previewSummary(current)}
            </span>
            {skipped ? (
                <span className="text-2xs text-fg-3">{skipped}</span>
            ) : null}
        </div>
    );
}
```

- [ ] **Step 10: Índice de Ajustes, página y tipos.**

En `use-settings-nav.ts`: importar `Hourglass` de `lucide-react` y agregar a `COMPANY_SECTIONS`, después de `guardias`:

```ts
    {
        key: 'hos',
        title: 'HOS (EE. UU.)',
        description:
            'Horas de servicio de tus choferes en Estados Unidos: quién entra, cuándo se les avisa y cómo se les insiste.',
        icon: Hourglass,
    },
```

y en `useSettingsNav()`, dentro del `for (const section of COMPANY_SECTIONS)`, después del `if (section.key === 'avanzado')`:

```ts
            // Sólo con la feature `hos_monitoring` (sin ella la sección no aplica).
            if (section.key === 'hos' && !nav.hosConfig) {
                continue;
            }
```

En `components/sam/settings/tenant-config/types.ts`: `import type { HosConfigForm } from '@/types/hos';` y en `TenantConfigProps`:

```ts
    /** Monitoreo HOS: null sin la feature `hos_monitoring` o sin `config.view`. */
    hos: HosConfigForm | null;
```

En `settings-catalog.ts`:

```ts
/** Se edita en su propia sección (HOS); "Avanzado" no la lista. */
export const HOS_MONITORING_KEY = 'hos.monitoring';
```

y agregarla a `DEDICATED_KEYS`.

En `pages/settings/tenant-config.tsx` (con `import { Hourglass } from 'lucide-react';`, `import { HosSection } from '@/components/sam/settings/tenant-config/hos-section';`, `import { EmptyState } from '@/components/ui/empty-state';`):

```tsx
    const editable =
        sectionKey === 'avisos'
            ? props.canManage || props.canManageChannels
            : sectionKey === 'hos'
              ? props.hos === null || props.hos.canManage
              : props.canManage;
```

y, después del bloque de `guardias`:

```tsx
                {sectionKey === 'hos' &&
                    (props.hos ? (
                        <HosSection form={props.hos} />
                    ) : (
                        <EmptyState
                            className="py-8"
                            icon={Hourglass}
                            title="El monitoreo HOS no está activo"
                            description="Lo activa el equipo de SAM para empresas con choferes en Estados Unidos. Escríbenos si lo necesitas."
                        />
                    ))}
```

- [ ] **Step 11:** Run `npm run types:check && npm run lint:check && npm run format:check && npm test` → PASS (correr `npm run format` si `format:check` marca los archivos nuevos).
- [ ] **Step 12: Commit** `feat: seccion hos en la configuracion de la empresa`

---

### Task 5: Datos del panel HOS por chofer y por flota

**Files:**
- Create: `app/Domains/Drivers/Support/HosUrgency.php`, `app/Domains/Drivers/Actions/BuildHosDriverPanel.php`, `app/Domains/Drivers/Actions/ListHosFleet.php`, `app/Http/Controllers/Drivers/HosPanelController.php`
- Modify: `app/Domains/Drivers/Models/HosDriverState.php`, `app/Http/Controllers/Drivers/DriverPageController.php`, `routes/web.php`, `routes/api.php`
- Test: `tests/Unit/Domains/Drivers/HosUrgencyTest.php`, `tests/Feature/Domains/Drivers/Hos/HosPanelTest.php`

**Interfaces:**
- Consumes: `HosMonitoringPolicy::viewAny` (Task 1), `HosEpisode::open()`, notificaciones `source_type = hos_episode` con `payload_json.hos.{step,notice}` (PR 2).
- Produces: `HosDriverState::clockSnapshot(): array{break,drive,shift,cycle}`; `HosUrgency::{VIOLATION, AT_LIMIT, WARNING, OK, level(?HosDriverState, list<HosSituation>, bool $escalated): string, minRemaining(?HosDriverState): ?int, sortSeconds(?HosDriverState): ?int, rank(string): int}`; `BuildHosDriverPanel::execute(Driver, CarbonInterface): array` (forma `HosDriverPanelData`); `ListHosFleet::execute(int $teamId, CarbonInterface): array{rows, summary}` (forma `HosFleetData`), `RECENT_SECONDS = 1800`, `STALE_SECONDS = 180`; prop `hos` de `drivers/show` (null sin `viewAny`); rutas `drivers.hos.index` (web, Inertia `drivers/hos` con prop `fleet`), `api.drivers.hos.index`, `api.drivers.hos.show`.

- [ ] **Step 1: Tests que fallan.** `tests/Unit/Domains/Drivers/HosUrgencyTest.php`:

```php
<?php

namespace Tests\Unit\Domains\Drivers;

use App\Domains\Drivers\Enums\HosDutyStatus;
use App\Domains\Drivers\Enums\HosSituation;
use App\Domains\Drivers\Models\HosDriverState;
use App\Domains\Drivers\Support\HosUrgency;
use Tests\TestCase;

/** Tests\TestCase (no PHPUnit pelón): instancia modelos Eloquent con casts. */
class HosUrgencyTest extends TestCase
{
    /**
     * @param  array<string, mixed>  $attributes
     */
    private function state(array $attributes = []): HosDriverState
    {
        return new HosDriverState($attributes + [
            'duty_status' => HosDutyStatus::Driving,
            'break_remaining_s' => 28800,
            'drive_remaining_s' => 39600,
            'shift_remaining_s' => 50400,
            'cycle_remaining_s' => 252000,
            'violation_s' => 0,
        ]);
    }

    public function test_a_violation_wins_over_everything(): void
    {
        $this->assertSame(HosUrgency::VIOLATION, HosUrgency::level($this->state(['violation_s' => 60, 'duty_status' => HosDutyStatus::OffDuty]), [], false));
        $this->assertSame(HosUrgency::VIOLATION, HosUrgency::level($this->state(), [HosSituation::Violation], false));
    }

    public function test_at_the_limit_only_while_working_or_once_escalated(): void
    {
        $this->assertSame(HosUrgency::AT_LIMIT, HosUrgency::level($this->state(['break_remaining_s' => 0]), [HosSituation::BreakDue], false));
        // Parado con el manejo en 0: está descansando, no en el límite.
        $this->assertSame(HosUrgency::OK, HosUrgency::level($this->state(['duty_status' => HosDutyStatus::OffDuty, 'drive_remaining_s' => 0]), [], false));
        $this->assertSame(HosUrgency::AT_LIMIT, HosUrgency::level($this->state(['duty_status' => HosDutyStatus::OffDuty]), [HosSituation::DriveLimit], true));
    }

    public function test_an_open_warning_but_not_a_rest_complete(): void
    {
        $this->assertSame(HosUrgency::WARNING, HosUrgency::level($this->state(['break_remaining_s' => 900]), [HosSituation::BreakDue], false));
        $this->assertSame(HosUrgency::OK, HosUrgency::level($this->state(['duty_status' => HosDutyStatus::OffDuty]), [HosSituation::RestComplete], false));
    }

    public function test_remaining_and_sort_seconds(): void
    {
        $this->assertSame(900, HosUrgency::minRemaining($this->state(['break_remaining_s' => 900])));
        $this->assertNull(HosUrgency::minRemaining(null));
        $this->assertSame(900, HosUrgency::sortSeconds($this->state(['break_remaining_s' => 900])));
        $this->assertNull(HosUrgency::sortSeconds($this->state(['duty_status' => HosDutyStatus::OffDuty, 'drive_remaining_s' => 0])));
        $this->assertLessThan(HosUrgency::rank(HosUrgency::OK), HosUrgency::rank(HosUrgency::VIOLATION));
    }
}
```

`tests/Feature/Domains/Drivers/Hos/HosPanelTest.php`:

```php
<?php

namespace Tests\Feature\Domains\Drivers\Hos;

use App\Domains\Access\Enums\RoleScope;
use App\Domains\Access\Models\Permission;
use App\Domains\Access\Models\Role;
use App\Domains\Assets\Models\Asset;
use App\Domains\Drivers\Enums\HosDutyStatus;
use App\Domains\Drivers\Enums\HosEpisodeResolution;
use App\Domains\Drivers\Enums\HosSituation;
use App\Domains\Drivers\Models\Driver;
use App\Domains\Drivers\Models\HosDriverState;
use App\Domains\Drivers\Models\HosEpisode;
use App\Domains\Drivers\Support\HosMonitoringConfig;
use App\Domains\Incidents\Models\Incident;
use App\Domains\Notifications\Enums\NotificationSourceType;
use App\Domains\Notifications\Models\Notification;
use App\Domains\Notifications\Models\NotificationChannel;
use App\Domains\Notifications\Models\NotificationDelivery;
use App\Domains\Tenancy\Models\TenantFeature;
use App\Enums\TeamRole;
use App\Models\Team;
use App\Models\User;
use Database\Seeders\AccessSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\Concerns\AssertsTenantIsolation;
use Tests\TestCase;

class HosPanelTest extends TestCase
{
    use AssertsTenantIsolation, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(AccessSeeder::class);
    }

    private function enable(Team $team): void
    {
        TenantFeature::factory()->create(['team_id' => $team->id, 'feature_key' => HosMonitoringConfig::FEATURE_KEY, 'enabled' => true]);
    }

    /**
     * @param  array<string, mixed>  $state
     */
    private function monitored(Team $team, string $name, array $state = []): Driver
    {
        $driver = Driver::factory()->create(['team_id' => $team->id, 'full_name' => $name]);
        HosDriverState::factory()->create(['driver_id' => $driver->id] + $state);

        return $driver;
    }

    public function test_the_driver_page_carries_the_hos_panel_with_episodes_nudges_and_incident(): void
    {
        $owner = User::factory()->create();
        $team = $owner->currentTeam;
        $this->enable($team);
        $asset = Asset::factory()->create(['team_id' => $team->id, 'name' => 'T-0321']);
        $driver = $this->monitored($team, 'Chofer Uno', ['asset_id' => $asset->id, 'duty_status' => HosDutyStatus::Driving, 'break_remaining_s' => 600]);
        $incident = Incident::factory()->create(['team_id' => $team->id]);
        $open = HosEpisode::factory()->create([
            'driver_id' => $driver->id, 'asset_id' => $asset->id, 'situation' => HosSituation::BreakDue,
            'ladder_step' => 2, 'escalated_at' => now(), 'incident_id' => $incident->id,
        ]);
        HosEpisode::factory()->create([
            'driver_id' => $driver->id, 'situation' => HosSituation::DriveLimit,
            'opened_at' => now()->subDay(), 'resolved_at' => now()->subDay()->addHour(), 'resolution' => HosEpisodeResolution::Corrected,
        ]);
        $channel = NotificationChannel::factory()->samsaraDriverApp()->create();
        $notification = Notification::factory()->create([
            'team_id' => $team->id,
            'source_type' => NotificationSourceType::HosEpisode,
            'source_reference_id' => (string) $open->id,
            'payload_json' => ['hos' => ['episode_id' => $open->id, 'step' => 1, 'notice' => 'break_insist']],
        ]);
        NotificationDelivery::factory()->delivered()->create(['notification_id' => $notification->id, 'channel_id' => $channel->id]);

        $this->actingAs($owner)
            ->get(route('drivers.show', ['current_team' => $team->slug, 'driver' => $driver->id]))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('drivers/show')
                ->where('hos.state.dutyStatus', 'driving')
                ->where('hos.state.clocks.break', 600)
                ->where('hos.state.asset.name', 'T-0321')
                ->where('hos.state.stale', false)
                ->where('hos.openEpisodes.0.situation', 'break_due')
                ->where('hos.openEpisodes.0.ladderStep', 2)
                ->where('hos.openEpisodes.0.incident.id', $incident->id)
                ->where('hos.openEpisodes.0.nudges.0.notice', 'break_insist')
                ->where('hos.openEpisodes.0.nudges.0.step', 1)
                ->where('hos.openEpisodes.0.nudges.0.deliveries.0', ['channel' => 'samsara_driver_app', 'status' => 'delivered'])
                ->where('hos.history.0.resolution', 'corrected')
            );
    }

    public function test_without_the_feature_the_driver_page_has_no_hos_panel(): void
    {
        $owner = User::factory()->create();
        $driver = $this->monitored($owner->currentTeam, 'Chofer Uno');

        $this->actingAs($owner)
            ->get(route('drivers.show', ['current_team' => $owner->currentTeam->slug, 'driver' => $driver->id]))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page->where('hos', null));
    }

    public function test_the_fleet_orders_violation_then_at_limit_then_lowest_remaining(): void
    {
        $owner = User::factory()->create();
        $team = $owner->currentTeam;
        $this->enable($team);

        $this->monitored($team, 'E Descansando', ['duty_status' => HosDutyStatus::OffDuty, 'drive_remaining_s' => 0]);
        $this->monitored($team, 'D Holgado', ['duty_status' => HosDutyStatus::Driving, 'break_remaining_s' => 7200]);
        $warning = $this->monitored($team, 'C Aviso', ['duty_status' => HosDutyStatus::Driving, 'break_remaining_s' => 900]);
        HosEpisode::factory()->create(['driver_id' => $warning->id, 'situation' => HosSituation::BreakDue]);
        $this->monitored($team, 'B Límite', ['duty_status' => HosDutyStatus::Driving, 'break_remaining_s' => 0]);
        $this->monitored($team, 'A Infracción', ['duty_status' => HosDutyStatus::OffDuty, 'violation_s' => 120]);
        $this->monitored($team, 'F Viejo', ['observed_at' => now()->subHour()]);

        $this->actingAs($owner)
            ->getJson("/api/{$team->slug}/drivers/hos")
            ->assertOk()
            ->assertJsonPath('data.rows.*.driver.fullName', ['A Infracción', 'B Límite', 'C Aviso', 'D Holgado', 'E Descansando'])
            ->assertJsonPath('data.rows.*.urgency', ['violation', 'at_limit', 'warning', 'ok', 'ok'])
            ->assertJsonPath('data.rows.2.openEpisodes.0.situation', 'break_due')
            ->assertJsonPath('data.summary', ['total' => 5, 'violation' => 1, 'at_limit' => 1, 'warning' => 1, 'ok' => 2]);
    }

    public function test_the_api_mirrors_the_driver_panel(): void
    {
        $owner = User::factory()->create();
        $team = $owner->currentTeam;
        $this->enable($team);
        $driver = $this->monitored($team, 'Chofer Uno', ['duty_status' => HosDutyStatus::SleeperBed]);

        $this->actingAs($owner)
            ->getJson("/api/{$team->slug}/drivers/{$driver->id}/hos")
            ->assertOk()
            ->assertJsonPath('data.state.dutyStatus', 'sleeperBed')
            ->assertJsonPath('data.openEpisodes', []);
    }

    public function test_the_api_answers_403_without_the_feature(): void
    {
        $owner = User::factory()->create();
        $team = $owner->currentTeam;
        $driver = $this->monitored($team, 'Chofer Uno');

        $this->actingAs($owner)->getJson("/api/{$team->slug}/drivers/hos")->assertForbidden();
        $this->actingAs($owner)->getJson("/api/{$team->slug}/drivers/{$driver->id}/hos")->assertForbidden();
    }

    public function test_a_role_without_drivers_view_gets_403(): void
    {
        $user = User::factory()->create();
        $team = $user->currentTeam;
        $role = Role::factory()->create(['code' => 'only_config', 'scope' => RoleScope::Tenant]);
        $role->permissions()->sync([Permission::firstOrCreate(['code' => 'config.view'], ['name' => 'config.view', 'module' => 'config'])->id]);
        $team->members()->updateExistingPivot($user->id, ['role' => TeamRole::Member->value, 'role_id' => $role->id]);
        $this->enable($team);

        $this->actingAs($user)->getJson("/api/{$team->slug}/drivers/hos")->assertForbidden();
    }

    public function test_the_fleet_and_panel_never_show_another_tenant(): void
    {
        $owner = User::factory()->create();
        $team = $owner->currentTeam;
        $this->enable($team);
        $this->monitored($team, 'Mío');

        $other = User::factory()->create()->currentTeam;
        $this->enable($other);
        $foreign = $this->monitored($other, 'Ajeno', ['violation_s' => 300]);
        HosEpisode::factory()->create(['driver_id' => $foreign->id, 'situation' => HosSituation::Violation]);

        $response = $this->assertNoTenantLeak($team, fn () => $this->actingAs($owner)->getJson("/api/{$team->slug}/drivers/hos"));

        $response->assertOk()->assertJsonPath('data.rows.*.driver.fullName', ['Mío']);
        $this->actingAs($owner)->getJson("/api/{$team->slug}/drivers/{$foreign->id}/hos")->assertNotFound();
    }
}
```

- [ ] **Step 2:** Run `php artisan test --compact --filter='HosUrgencyTest|HosPanelTest'` → FAIL.

- [ ] **Step 3: Relojes del estado.** En `HosDriverState` agregar:

```php
    /**
     * Lo que queda en cada reloj, en segundos (null = Samsara no lo mandó).
     *
     * @return array{break: int|null, drive: int|null, shift: int|null, cycle: int|null}
     */
    public function clockSnapshot(): array
    {
        return [
            'break' => $this->break_remaining_s,
            'drive' => $this->drive_remaining_s,
            'shift' => $this->shift_remaining_s,
            'cycle' => $this->cycle_remaining_s,
        ];
    }
```

- [ ] **Step 4: Urgencia** `app/Domains/Drivers/Support/HosUrgency.php`:

```php
<?php

namespace App\Domains\Drivers\Support;

use App\Domains\Drivers\Enums\HosSituation;
use App\Domains\Drivers\Models\HosDriverState;

/**
 * Qué tan urgente es un chofer vigilado para la vista de flota (spec §3.12):
 * infracción > en el límite (trabajando con un reloj agotado, o escalera ya
 * escalada) > con un aviso abierto > en regla. Dentro de cada nivel va
 * primero quien trabaja con menos tiempo en algún reloj; quien está parado
 * no compite por tiempo (un manejo en 0 mientras descansa es lo normal).
 */
final class HosUrgency
{
    public const string VIOLATION = 'violation';

    public const string AT_LIMIT = 'at_limit';

    public const string WARNING = 'warning';

    public const string OK = 'ok';

    /** @var array<string, int> */
    private const array RANK = [self::VIOLATION => 0, self::AT_LIMIT => 1, self::WARNING => 2, self::OK => 3];

    /**
     * @param  list<HosSituation>  $openSituations
     */
    public static function level(?HosDriverState $state, array $openSituations, bool $escalated): string
    {
        if (in_array(HosSituation::Violation, $openSituations, true) || ($state !== null && $state->violation_s > 0)) {
            return self::VIOLATION;
        }

        $remaining = self::sortSeconds($state);

        if ($escalated || ($remaining !== null && $remaining <= 0)) {
            return self::AT_LIMIT;
        }

        $warnings = array_filter($openSituations, fn (HosSituation $situation): bool => $situation !== HosSituation::RestComplete);

        return $warnings === [] ? self::OK : self::WARNING;
    }

    /** El reloj más corto (descanso, manejo, turno o ciclo); null sin datos. */
    public static function minRemaining(?HosDriverState $state): ?int
    {
        if ($state === null) {
            return null;
        }

        $clocks = array_filter($state->clockSnapshot(), fn (?int $seconds): bool => $seconds !== null);

        return $clocks === [] ? null : min($clocks);
    }

    /** El reloj más corto sólo si está trabajando con la app conectada. */
    public static function sortSeconds(?HosDriverState $state): ?int
    {
        if ($state === null || $state->app_disconnected_since !== null || $state->duty_status?->isWorking() !== true) {
            return null;
        }

        return self::minRemaining($state);
    }

    public static function rank(string $level): int
    {
        return self::RANK[$level] ?? count(self::RANK);
    }
}
```

- [ ] **Step 5: Panel del chofer** `app/Domains/Drivers/Actions/BuildHosDriverPanel.php`:

```php
<?php

namespace App\Domains\Drivers\Actions;

use App\Domains\Drivers\Models\Driver;
use App\Domains\Drivers\Models\HosDriverState;
use App\Domains\Drivers\Models\HosEpisode;
use App\Domains\Notifications\Enums\NotificationSourceType;
use App\Domains\Notifications\Models\Notification;
use App\Domains\Notifications\Models\NotificationDelivery;
use App\Support\TenantContext;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Collection;

/**
 * Pestaña HOS del chofer: su último estado (relojes), los episodios abiertos
 * y los últimos cerrados, cada uno con los avisos que SAM le mandó (fuente
 * `hos_episode`) con canal y resultado de cada entrega, y su incidente.
 */
class BuildHosDriverPanel
{
    public const int HISTORY_LIMIT = 20;

    /**
     * @return array{state: array<string, mixed>|null, openEpisodes: list<array<string, mixed>>, history: list<array<string, mixed>>}
     */
    public function execute(Driver $driver, CarbonInterface $now): array
    {
        return TenantContext::for($driver->team_id, function () use ($driver, $now): array {
            $teamId = $driver->team_id;

            $state = HosDriverState::query()
                ->where('team_id', $teamId)
                ->where('driver_id', $driver->id)
                ->with('asset')
                ->first();

            $open = HosEpisode::query()
                ->where('team_id', $teamId)
                ->where('driver_id', $driver->id)
                ->open()
                ->with('incident')
                ->orderByDesc('opened_at')
                ->orderByDesc('id')
                ->get();

            $history = HosEpisode::query()
                ->where('team_id', $teamId)
                ->where('driver_id', $driver->id)
                ->whereNotNull('resolved_at')
                ->with('incident')
                ->orderByDesc('opened_at')
                ->orderByDesc('id')
                ->limit(self::HISTORY_LIMIT)
                ->get();

            $nudges = $this->nudges($teamId, $open->concat($history));
            $present = fn (HosEpisode $episode): array => $this->episode($episode, $nudges[(string) $episode->id] ?? []);

            return [
                'state' => $state === null ? null : [
                    'dutyStatus' => $state->duty_status?->value,
                    'statusSince' => $state->status_since?->toIso8601String(),
                    'appDisconnectedSince' => $state->app_disconnected_since?->toIso8601String(),
                    'observedAt' => $state->observed_at->toIso8601String(),
                    'stale' => $state->observed_at->lt($now->toImmutable()->subSeconds(ListHosFleet::STALE_SECONDS)),
                    'asset' => $state->asset !== null ? [
                        'id' => $state->asset->id,
                        'name' => $state->asset->name,
                        'code' => $state->asset->code,
                    ] : null,
                    'clocks' => $state->clockSnapshot(),
                    'violationSeconds' => $state->violation_s,
                ],
                'openEpisodes' => array_values($open->map($present)->all()),
                'history' => array_values($history->map($present)->all()),
            ];
        });
    }

    /**
     * @param  list<array<string, mixed>>  $nudges
     * @return array<string, mixed>
     */
    private function episode(HosEpisode $episode, array $nudges): array
    {
        $incident = $episode->incident;

        return [
            'id' => $episode->id,
            'situation' => $episode->situation->value,
            'openedAt' => $episode->opened_at->toIso8601String(),
            'resolvedAt' => $episode->resolved_at?->toIso8601String(),
            'resolution' => $episode->resolution?->value,
            'ladderStep' => $episode->ladder_step,
            'nextNudgeAt' => $episode->next_nudge_at?->toIso8601String(),
            'escalatedAt' => $episode->escalated_at?->toIso8601String(),
            // attachIncident ya valida el team; se vuelve a mirar al leer.
            'incident' => $incident !== null && $incident->team_id === $episode->team_id
                ? ['id' => $incident->id, 'reference' => $incident->reference()]
                : null,
            'nudges' => $nudges,
        ];
    }

    /**
     * Avisos HOS de esos episodios, del más viejo al más nuevo.
     *
     * @param  Collection<int, HosEpisode>  $episodes
     * @return array<string, list<array<string, mixed>>>
     */
    private function nudges(int $teamId, Collection $episodes): array
    {
        if ($episodes->isEmpty()) {
            return [];
        }

        $notifications = Notification::query()
            ->where('team_id', $teamId)
            ->where('source_type', NotificationSourceType::HosEpisode)
            ->whereIn('source_reference_id', $episodes->map(fn (HosEpisode $episode): string => (string) $episode->id)->all())
            ->with([
                'deliveries' => fn (HasMany $query) => $query->where('team_id', $teamId)->orderBy('id'),
                'deliveries.channel',
            ])
            ->orderBy('created_at')
            ->orderBy('id')
            ->get();

        $grouped = [];

        foreach ($notifications as $notification) {
            $payload = is_array($notification->payload_json) ? $notification->payload_json : [];
            $hos = isset($payload['hos']) && is_array($payload['hos']) ? $payload['hos'] : [];

            $grouped[(string) $notification->source_reference_id][] = [
                'id' => $notification->id,
                'step' => isset($hos['step']) && is_numeric($hos['step']) ? (int) $hos['step'] : null,
                'notice' => isset($hos['notice']) && is_string($hos['notice']) ? $hos['notice'] : null,
                'createdAt' => $notification->created_at?->toIso8601String(),
                'deliveries' => array_values($notification->deliveries->map(fn (NotificationDelivery $delivery): array => [
                    'channel' => $delivery->channel?->channel_type?->value,
                    'status' => $delivery->status->value,
                ])->all()),
            ];
        }

        return $grouped;
    }
}
```

- [ ] **Step 6: Flota** `app/Domains/Drivers/Actions/ListHosFleet.php`:

```php
<?php

namespace App\Domains\Drivers\Actions;

use App\Domains\Drivers\Models\HosDriverState;
use App\Domains\Drivers\Models\HosEpisode;
use App\Domains\Drivers\Support\HosUrgency;
use App\Support\TenantContext;
use Carbon\CarbonInterface;

/**
 * Vista "HOS (EE. UU.)": los choferes con lectura reciente, del más urgente
 * al más holgado ({@see HosUrgency}), con un resumen por nivel. El estado de
 * quien sale del conjunto no se borra: deja de aparecer pasados
 * RECENT_SECONDS sin lectura y se marca `stale` pasados STALE_SECONDS.
 */
class ListHosFleet
{
    public const int RECENT_SECONDS = 1800;

    public const int STALE_SECONDS = 180;

    /**
     * @return array{rows: list<array<string, mixed>>, summary: array{total: int, violation: int, at_limit: int, warning: int, ok: int}}
     */
    public function execute(int $teamId, CarbonInterface $now): array
    {
        return TenantContext::for($teamId, function () use ($teamId, $now): array {
            $states = HosDriverState::query()
                ->where('team_id', $teamId)
                ->where('observed_at', '>=', $now->toImmutable()->subSeconds(self::RECENT_SECONDS))
                ->with(['driver', 'asset'])
                ->get();

            $episodes = HosEpisode::query()
                ->where('team_id', $teamId)
                ->open()
                ->whereIn('driver_id', $states->pluck('driver_id')->all())
                ->orderBy('opened_at')
                ->get()
                ->groupBy('driver_id');

            $summary = ['total' => 0, HosUrgency::VIOLATION => 0, HosUrgency::AT_LIMIT => 0, HosUrgency::WARNING => 0, HosUrgency::OK => 0];
            $items = [];

            foreach ($states as $state) {
                $open = $episodes->get($state->driver_id, collect());
                $urgency = HosUrgency::level(
                    $state,
                    array_values($open->map(fn (HosEpisode $episode) => $episode->situation)->all()),
                    $open->contains(fn (HosEpisode $episode): bool => $episode->escalated_at !== null),
                );
                $name = $state->driver !== null ? $state->driver->full_name : 'Chofer sin nombre';

                $summary['total']++;
                $summary[$urgency]++;

                $items[] = [
                    'sort' => [HosUrgency::rank($urgency), HosUrgency::sortSeconds($state) ?? PHP_INT_MAX, mb_strtolower($name), $state->driver_id],
                    'row' => [
                        'driver' => ['id' => $state->driver_id, 'fullName' => $name],
                        'asset' => $state->asset !== null ? [
                            'id' => $state->asset->id,
                            'name' => $state->asset->name,
                            'code' => $state->asset->code,
                        ] : null,
                        'dutyStatus' => $state->duty_status?->value,
                        'appDisconnected' => $state->app_disconnected_since !== null,
                        'observedAt' => $state->observed_at->toIso8601String(),
                        'stale' => $state->observed_at->lt($now->toImmutable()->subSeconds(self::STALE_SECONDS)),
                        'clocks' => $state->clockSnapshot(),
                        'violationSeconds' => $state->violation_s,
                        'urgency' => $urgency,
                        'minRemainingSeconds' => HosUrgency::minRemaining($state),
                        'openEpisodes' => array_values($open->map(fn (HosEpisode $episode): array => [
                            'id' => $episode->id,
                            'situation' => $episode->situation->value,
                            'ladderStep' => $episode->ladder_step,
                            'escalated' => $episode->escalated_at !== null,
                            'incidentId' => $episode->incident_id,
                        ])->all()),
                    ],
                ];
            }

            usort($items, fn (array $a, array $b): int => $a['sort'] <=> $b['sort']);

            return [
                'rows' => array_map(fn (array $item): array => $item['row'], $items),
                'summary' => $summary,
            ];
        });
    }
}
```

> El factory de `HosEpisode` no fija `ladder_step` (default 0 en la base); el panel lo lee de la base, así que llega como entero.

- [ ] **Step 7: Controlador** `app/Http/Controllers/Drivers/HosPanelController.php`:

```php
<?php

namespace App\Http\Controllers\Drivers;

use App\Domains\Drivers\Actions\BuildHosDriverPanel;
use App\Domains\Drivers\Actions\ListHosFleet;
use App\Domains\Drivers\Models\Driver;
use App\Domains\Drivers\Models\HosDriverState;
use App\Http\Controllers\Controller;
use App\Models\Team;
use Illuminate\Http\JsonResponse;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Panel HOS (EE. UU.): la vista de flota (Inertia) y su espejo API, más el
 * panel de un chofer por API. HosMonitoringPolicy exige la feature
 * `hos_monitoring` y `drivers.view`.
 */
class HosPanelController extends Controller
{
    public function index(Team $current_team, ListHosFleet $listFleet): Response
    {
        $this->authorize('viewAny', HosDriverState::class);

        return Inertia::render('drivers/hos', [
            'fleet' => fn (): array => $listFleet->execute($current_team->id, now()),
        ]);
    }

    public function fleet(Team $current_team, ListHosFleet $listFleet): JsonResponse
    {
        $this->authorize('viewAny', HosDriverState::class);

        return response()->json(['data' => $listFleet->execute($current_team->id, now())]);
    }

    public function driver(Team $current_team, Driver $driver, BuildHosDriverPanel $panel): JsonResponse
    {
        abort_if($driver->team_id !== $current_team->id, 404);

        $this->authorize('view', $driver);
        $this->authorize('viewAny', HosDriverState::class);

        return response()->json(['data' => $panel->execute($driver, now())]);
    }
}
```

- [ ] **Step 8: Rutas.** En `routes/web.php`, **antes** de `drivers/{driver}` (con `use App\Http\Controllers\Drivers\HosPanelController;`):

```php
        // Monitoreo HOS (EE. UU.) de la flota: segmento literal antes del binding.
        Route::get('drivers/hos', [HosPanelController::class, 'index'])->name('drivers.hos.index');
```

En `routes/api.php`, **antes** de `drivers/{driver}` (mismo `use`):

```php
        Route::get('drivers/hos', [HosPanelController::class, 'fleet'])->name('api.drivers.hos.index');
```

y después de `api.drivers.risk-profile`:

```php
        Route::get('drivers/{driver}/hos', [HosPanelController::class, 'driver'])->name('api.drivers.hos.show');
```

- [ ] **Step 9: Prop `hos` del chofer.** En `DriverPageController::show()` (con `use App\Domains\Drivers\Actions\BuildHosDriverPanel;`, `use App\Domains\Drivers\Models\HosDriverState;`; `Request` ya está importado):

```php
    public function show(Request $request, Team $current_team, Driver $driver, BuildHosDriverPanel $hosPanel): Response
```

y en el `Inertia::render('drivers/show', [...])`:

```php
            // Pestaña HOS: null sin la feature `hos_monitoring` (no se muestra).
            'hos' => fn (): ?array => $request->user()?->can('viewAny', HosDriverState::class) === true
                ? $hosPanel->execute($driver, now())
                : null,
```

- [ ] **Step 10:** Run `php artisan wayfinder:generate --with-form` y `php artisan test --compact --filter='HosUrgencyTest|HosPanelTest|DriverShowPageTest|CrossTenantRouteSweepTest'` → PASS. (`CrossTenantRouteSweepTest` recorre solo las rutas nuevas bajo `{current_team}`.)
- [ ] **Step 11: Commit** `feat: datos del panel hos por chofer y por flota`

---

### Task 6: Aviso en tiempo real al cerrar cada sondeo

**Files:**
- Create: `app/Domains/Drivers/Events/HosClocksUpdatedBroadcast.php`
- Modify: `app/Domains/Drivers/Jobs/SyncHosClocksJob.php`, `resources/js/types/realtime.ts`, `resources/js/hooks/use-team-broadcasts.ts`, `docs/SAM/logging.md`
- Test: `tests/Feature/Domains/Drivers/Hos/SyncHosClocksJobTest.php`

**Interfaces:**
- Produces: evento `hos.clocks_updated` en `private-accounts.{teamId}` con payload `{monitored: int, observed_at: string}` (`HosClocksUpdatedPayload`); `TEAM_EVENTS` lo escucha; `hos.poll.completed` agrega `result.broadcast` (bool).

- [ ] **Step 1: Tests que fallan.** En `SyncHosClocksJobTest.php` (con `use App\Domains\Drivers\Events\HosClocksUpdatedBroadcast;` y `use Illuminate\Support\Facades\Event;`):

```php
    public function test_each_poll_tells_the_tenant_panels_to_refresh(): void
    {
        Event::fake([HosClocksUpdatedBroadcast::class]);
        $integration = $this->tenant();
        $this->link($integration, '58072405', '281');
        $this->fakeSamsara();

        app()->call([new SyncHosClocksJob($integration), 'handle']);

        Event::assertDispatched(HosClocksUpdatedBroadcast::class, function (HosClocksUpdatedBroadcast $event) use ($integration): bool {
            return $event->teamId === $integration->team_id
                && $event->broadcastOn()[0]->name === "private-accounts.{$integration->team_id}"
                && $event->broadcastAs() === 'hos.clocks_updated'
                && $event->broadcastWith() === ['monitored' => 1, 'observed_at' => $event->observedAt];
        });
        $this->assertTrue($this->assertSystemLogged('hos.poll.completed')['result']['broadcast']);
    }

    public function test_a_poll_with_nobody_monitored_broadcasts_nothing(): void
    {
        Event::fake([HosClocksUpdatedBroadcast::class]);
        // Sin chofer vinculado: la lectura no se resuelve, nadie queda vigilado.
        $integration = $this->tenant();
        $this->fakeSamsara();

        app()->call([new SyncHosClocksJob($integration), 'handle']);

        Event::assertNotDispatched(HosClocksUpdatedBroadcast::class);
        $this->assertFalse($this->assertSystemLogged('hos.poll.completed')['result']['broadcast']);
    }

    public function test_a_failed_poll_broadcasts_nothing(): void
    {
        Event::fake([HosClocksUpdatedBroadcast::class]);
        $integration = $this->tenant();
        $this->link($integration, '58072405', '281');
        Http::fake(['api.samsara.com/fleet/hos/clocks*' => Http::response([], 503)]);

        app()->call([new SyncHosClocksJob($integration), 'handle']);

        Event::assertNotDispatched(HosClocksUpdatedBroadcast::class);
    }
```

- [ ] **Step 2:** Run `php artisan test --compact --filter=SyncHosClocksJobTest` → FAIL (clase inexistente).

- [ ] **Step 3: Evento** `app/Domains/Drivers/Events/HosClocksUpdatedBroadcast.php`:

```php
<?php

namespace App\Domains\Drivers\Events;

use App\Support\Broadcasting\QueuesRealtimeBroadcast;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;
use Illuminate\Contracts\Broadcasting\ShouldRescue;
use Illuminate\Queue\SerializesModels;

/**
 * Un sondeo HOS del tenant terminó: el panel del chofer y la vista de flota
 * recargan sus props. Payload mínimo (sin choferes ni relojes): la página
 * pide lo suyo con su propia autorización.
 */
class HosClocksUpdatedBroadcast implements ShouldBroadcast, ShouldRescue
{
    use QueuesRealtimeBroadcast, SerializesModels;

    public function __construct(
        public readonly int $teamId,
        public readonly int $monitored,
        public readonly string $observedAt,
    ) {}

    /**
     * @return array<int, PrivateChannel>
     */
    public function broadcastOn(): array
    {
        return [
            new PrivateChannel("accounts.{$this->teamId}"),
        ];
    }

    public function broadcastAs(): string
    {
        return 'hos.clocks_updated';
    }

    /**
     * @return array{monitored: int, observed_at: string}
     */
    public function broadcastWith(): array
    {
        return [
            'monitored' => $this->monitored,
            'observed_at' => $this->observedAt,
        ];
    }
}
```

- [ ] **Step 4: El sondeo lo emite.** En `SyncHosClocksJob` (con `use App\Domains\Drivers\Events\HosClocksUpdatedBroadcast;`), reemplazar el `SystemLog::ok('hos.poll.completed', ...)` final por:

```php
            // Sin nadie vigilado ni nadie que haya salido no hay nada que repintar.
            $broadcast = $counts['monitored'] + $counts['unenrolled'] > 0;

            SystemLog::ok('hos.poll.completed', input: $input, calc: [
                'readings_count' => count($readings),
                'tags_count' => count($tags),
                'tag_ids_count' => count($config->tagIds),
                'included_count' => count($config->includedAssetIds),
                'excluded_count' => count($config->excludedAssetIds),
            ], result: $counts + $skipped + ['broadcast' => $broadcast]);

            if ($broadcast) {
                broadcast(new HosClocksUpdatedBroadcast($teamId, $counts['monitored'], $now->toIso8601String()));
            }
```

- [ ] **Step 5: Frontend.** En `resources/js/types/realtime.ts`:

```ts
/** Un sondeo HOS del tenant terminó (cada ~1 min con alguien vigilado). */
export type HosClocksUpdatedPayload = {
    monitored: number;
    observed_at: string;
};
```

y en `TeamBroadcastEventMap`: `'hos.clocks_updated': HosClocksUpdatedPayload;`. En `hooks/use-team-broadcasts.ts`, agregar `'hos.clocks_updated'` al final de `TEAM_EVENTS`.

- [ ] **Step 6: Logging.** En `docs/SAM/logging.md`, en la fila `hos.poll.completed`, agregar al final de `result`: `y `broadcast` (true = se emitió `hos.clocks_updated` en `private-accounts.{team}` para que el panel y la vista de flota recarguen; false si nadie quedó vigilado ni salió)`.
- [ ] **Step 7:** Run `php artisan test --compact --filter='SyncHosClocksJobTest|QueueTopologyTest'` y `npm run types:check && npm test -- hooks` → PASS.
- [ ] **Step 8: Commit** `feat: aviso en tiempo real al cerrar cada sondeo hos`

---

### Task 7: Pestaña HOS del chofer y vista HOS (EE. UU.) de la flota

**Files:**
- Create: `resources/js/components/sam/hos/lib.ts`, `resources/js/components/sam/hos/lib.test.ts`, `resources/js/components/sam/hos/hos-status-badge.tsx`, `resources/js/components/sam/hos/hos-clock-bars.tsx`, `resources/js/components/sam/hos/hos-episode-list.tsx`, `resources/js/components/sam/hos/hos-driver-panel.tsx`, `resources/js/components/sam/hos/hos-fleet-table.tsx`, `resources/js/pages/drivers/hos.tsx`
- Modify: `resources/js/lib/time.ts`, `resources/js/lib/time.test.ts`, `resources/js/types/drivers.ts`, `resources/js/pages/drivers/show.tsx`, `resources/js/components/sam/ops-sidebar.tsx`
- Test: `tests/Feature/Domains/Drivers/Hos/HosPanelTest.php` (2 tests de la página)

**Interfaces:**
- Consumes: props `hos` (`drivers/show`) y `fleet` (`drivers/hos`) de la Task 5, `hos.clocks_updated` de la Task 6, `nav.hos` de la Task 1, `driverRoutes.hos.index` (Wayfinder).
- Produces: `hoursMinutesLabel(seconds)` en `lib/time.ts`; `lib.ts`: `HOS_CLOCK_KEYS`, `HOS_CLOCK_FULL_SECONDS`, `clockBars(clocks): HosClockBar[]`, `clockTone`, `nearestLabel(row)`, `stepsLabel(n)`, `nudgeTitle(nudge)`, `deliveryLines(nudge)`, `driverTabFromUrl(url)`.

- [ ] **Step 1: Tests que fallan.** En `resources/js/lib/time.test.ts` (agregar `hoursMinutesLabel` al import):

```ts
describe('hoursMinutesLabel', () => {
    it.each([
        [0, '0 min'],
        [59, '0 min'],
        [2700, '45 min'],
        [3600, '1 h'],
        [3900, '1 h 05 min'],
        [252000, '70 h'],
        [-30, '0 min'],
    ])('%i s → %s', (seconds, expected) => {
        expect(hoursMinutesLabel(seconds)).toBe(expected);
    });
});
```

`resources/js/components/sam/hos/lib.test.ts`:

```ts
import { describe, expect, it } from 'vitest';
import type { HosFleetRow, HosNudge } from '@/types/hos';
import {
    clockBars,
    deliveryLines,
    driverTabFromUrl,
    nearestLabel,
    nudgeTitle,
    stepsLabel,
} from './lib';

describe('clockBars', () => {
    it('pinta cada reloj con su tono y su tiempo', () => {
        const bars = clockBars({
            break: 900,
            drive: 0,
            shift: 30000,
            cycle: null,
        });

        expect(bars.map((bar) => [bar.key, bar.tone, bar.valueLabel])).toEqual(
            [
                ['break', 'warn', '15 min'],
                ['drive', 'critical', 'Agotado'],
                ['shift', 'ok', '8 h 20 min'],
                ['cycle', 'neutral', 'Sin dato'],
            ],
        );
        expect(bars[0]?.max).toBe(28800);
    });
});

describe('textos del panel', () => {
    it('lo más cercano de una fila', () => {
        const row = { minRemainingSeconds: 900 } as Pick<
            HosFleetRow,
            'minRemainingSeconds'
        >;

        expect(nearestLabel(row)).toBe('15 min');
        expect(nearestLabel({ minRemainingSeconds: 0 })).toBe('Agotado');
        expect(nearestLabel({ minRemainingSeconds: null })).toBe('—');
    });

    it('escalones enviados', () => {
        expect(stepsLabel(0)).toBe('Sin escalones enviados');
        expect(stepsLabel(1)).toBe('1 escalón enviado');
        expect(stepsLabel(3)).toBe('3 escalones enviados');
    });

    it('cada entrega con su canal y su resultado', () => {
        const nudge: HosNudge = {
            id: 1,
            step: 1,
            notice: 'break_insist',
            createdAt: '2026-10-04T18:00:00Z',
            deliveries: [
                { channel: 'samsara_driver_app', status: 'delivered' },
                { channel: 'voice', status: 'failed' },
            ],
        };

        expect(nudgeTitle(nudge)).toBe('Insistencia: descanso');
        expect(
            deliveryLines(nudge).map((line) => [line.text, line.tone]),
        ).toEqual([
            ['App de Samsara: entregado', 'ok'],
            ['Llamada de voz: falló', 'critical'],
        ]);
        expect(nudgeTitle({ ...nudge, notice: null })).toBe('Aviso');
    });

    it('la pestaña sale de ?pestana=', () => {
        expect(driverTabFromUrl('/acme/drivers/7?pestana=hos')).toBe('hos');
        expect(driverTabFromUrl('/acme/drivers/7')).toBe('resumen');
        expect(driverTabFromUrl('/acme/drivers/7?pestana=otra')).toBe(
            'resumen',
        );
    });
});
```

En `HosPanelTest.php`:

```php
    public function test_the_fleet_page_renders_for_whoever_sees_drivers_with_the_feature(): void
    {
        $owner = User::factory()->create();
        $team = $owner->currentTeam;
        $this->enable($team);
        $this->monitored($team, 'Chofer Uno', ['duty_status' => HosDutyStatus::Driving, 'break_remaining_s' => 900]);

        $this->actingAs($owner)
            ->get(route('drivers.hos.index', ['current_team' => $team->slug]))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('drivers/hos')
                ->has('fleet.rows', 1)
                ->where('fleet.rows.0.driver.fullName', 'Chofer Uno')
                ->where('fleet.summary.total', 1)
            );
    }

    public function test_the_fleet_page_answers_403_without_the_feature(): void
    {
        $owner = User::factory()->create();

        $this->actingAs($owner)
            ->get(route('drivers.hos.index', ['current_team' => $owner->currentTeam->slug]))
            ->assertForbidden();
    }
```

- [ ] **Step 2:** Run `npm test -- lib/time components/sam/hos` y `php artisan test --compact --filter=HosPanelTest` → FAIL (funciones y página inexistentes).

- [ ] **Step 3: `lib/time.ts`.** Agregar después de `durationLabel`:

```ts
/** Tiempo que queda en un reloj: "1 h 05 min", "45 min", "0 min". */
export function hoursMinutesLabel(seconds: number): string {
    const totalMinutes = Math.max(0, Math.floor(seconds / 60));
    const hours = Math.floor(totalMinutes / 60);
    const minutes = totalMinutes % 60;

    if (hours === 0) {
        return `${minutes} min`;
    }

    return minutes === 0
        ? `${hours} h`
        : `${hours} h ${String(minutes).padStart(2, '0')} min`;
}
```

- [ ] **Step 4: Lógica del panel** `resources/js/components/sam/hos/lib.ts`:

```ts
import { CHANNEL_DELIVERY } from '@/components/sam/notifications/copy';
import { channelLabel } from '@/lib/labels';
import { hoursMinutesLabel } from '@/lib/time';
import type { Tone } from '@/lib/tone';
import type { HosClocks, HosFleetRow, HosNudge } from '@/types/hos';
import { HOS_CLOCK_LABELS, HOS_NOTICE_LABELS } from './copy';

export const HOS_CLOCK_KEYS = ['break', 'drive', 'shift', 'cycle'] as const;

/** Valor de cada reloj en reposo (US Interstate Property, 70 h / 8 días). */
export const HOS_CLOCK_FULL_SECONDS: Record<keyof HosClocks, number> = {
    break: 8 * 3600,
    drive: 11 * 3600,
    shift: 14 * 3600,
    cycle: 70 * 3600,
};

/** Bajo esto el reloj se pinta en advertencia (primer aviso recomendado). */
export const HOS_WARNING_SECONDS: Record<keyof HosClocks, number> = {
    break: 30 * 60,
    drive: 30 * 60,
    shift: 30 * 60,
    cycle: 5 * 3600,
};

export interface HosClockBar {
    key: keyof HosClocks;
    label: string;
    remaining: number | null;
    max: number;
    tone: Tone;
    valueLabel: string;
}

export function clockTone(
    remaining: number | null,
    warningSeconds: number,
): Tone {
    if (remaining === null) {
        return 'neutral';
    }

    if (remaining <= 0) {
        return 'critical';
    }

    return remaining <= warningSeconds ? 'warn' : 'ok';
}

function remainingLabel(seconds: number | null): string {
    if (seconds === null) {
        return 'Sin dato';
    }

    return seconds <= 0 ? 'Agotado' : hoursMinutesLabel(seconds);
}

export function clockBars(clocks: HosClocks): HosClockBar[] {
    return HOS_CLOCK_KEYS.map((key) => ({
        key,
        label: HOS_CLOCK_LABELS[key],
        remaining: clocks[key],
        max: HOS_CLOCK_FULL_SECONDS[key],
        tone: clockTone(clocks[key], HOS_WARNING_SECONDS[key]),
        valueLabel: remainingLabel(clocks[key]),
    }));
}

/** El reloj más corto de una fila de la flota. */
export function nearestLabel(
    row: Pick<HosFleetRow, 'minRemainingSeconds'>,
): string {
    return row.minRemainingSeconds === null
        ? '—'
        : remainingLabel(row.minRemainingSeconds);
}

export function stepsLabel(steps: number): string {
    if (steps === 0) {
        return 'Sin escalones enviados';
    }

    return steps === 1 ? '1 escalón enviado' : `${steps} escalones enviados`;
}

export function nudgeTitle(nudge: HosNudge): string {
    return nudge.notice === null
        ? 'Aviso'
        : (HOS_NOTICE_LABELS[nudge.notice] ?? 'Aviso');
}

/** "App de Samsara: entregado" por cada entrega del aviso. */
export function deliveryLines(
    nudge: HosNudge,
): { key: string; text: string; tone: Tone }[] {
    return nudge.deliveries.map((delivery, index) => {
        const state = CHANNEL_DELIVERY[delivery.status];

        return {
            key: `${delivery.channel ?? 'canal'}-${index}`,
            text: `${channelLabel(delivery.channel)}: ${state?.label ?? delivery.status}`,
            tone: state?.tone ?? 'neutral',
        };
    });
}

/** Pestaña inicial del detalle del chofer (`?pestana=hos` desde la flota). */
export function driverTabFromUrl(url: string): 'resumen' | 'hos' {
    const query = url.includes('?') ? url.slice(url.indexOf('?') + 1) : '';

    return new URLSearchParams(query).get('pestana') === 'hos'
        ? 'hos'
        : 'resumen';
}
```

- [ ] **Step 5: Componentes.** `resources/js/components/sam/hos/hos-status-badge.tsx`:

```tsx
import { StatusBadge } from '@/components/sam/status-badge';
import {
    HOS_APP_DISCONNECTED,
    HOS_DUTY_STATUS,
    HOS_UNKNOWN_STATUS,
} from './copy';

export interface HosStatusBadgeProps {
    dutyStatus: string | null;
    appDisconnected: boolean;
}

/** Estado del chofer en Samsara; la app desconectada gana. */
export function HosStatusBadge({
    dutyStatus,
    appDisconnected,
}: HosStatusBadgeProps) {
    const status = appDisconnected
        ? HOS_APP_DISCONNECTED
        : ((dutyStatus === null ? undefined : HOS_DUTY_STATUS[dutyStatus]) ??
          HOS_UNKNOWN_STATUS);

    return <StatusBadge size="sm" dot {...status} />;
}
```

`resources/js/components/sam/hos/hos-clock-bars.tsx`:

```tsx
import { Meter } from '@/components/sam/meter';
import { TONE_DOT, TONE_TEXT } from '@/lib/tone';
import { cn } from '@/lib/utils';
import type { HosClocks } from '@/types/hos';
import { clockBars } from './lib';

export interface HosClockBarsProps {
    clocks: HosClocks;
}

/** Descanso, manejo, turno y ciclo: lo que queda en cada reloj. */
export function HosClockBars({ clocks }: HosClockBarsProps) {
    return (
        <dl className="grid grid-cols-1 gap-3 sm:grid-cols-2">
            {clockBars(clocks).map((bar) => (
                <div key={bar.key} className="flex flex-col gap-1.5">
                    <dt className="text-xs text-fg-2">{bar.label}</dt>
                    <dd className="flex flex-col gap-1.5">
                        <span
                            className={cn(
                                'text-sm font-semibold tabular-nums',
                                TONE_TEXT[bar.tone],
                            )}
                        >
                            {bar.valueLabel}
                        </span>
                        <Meter
                            value={Math.max(0, bar.remaining ?? 0)}
                            max={bar.max}
                            label={`${bar.label}: ${bar.valueLabel}`}
                            toneClassName={TONE_DOT[bar.tone]}
                            minPercent={bar.remaining === null ? 0 : 2}
                        />
                    </dd>
                </div>
            ))}
        </dl>
    );
}
```

`resources/js/components/sam/hos/hos-episode-list.tsx`:

```tsx
import { Link } from '@inertiajs/react';
import { StatusBadge } from '@/components/sam/status-badge';
import { formatDateTime } from '@/lib/format';
import { formatClock, relativeLabel } from '@/lib/time';
import incidentRoutes from '@/routes/incidents';
import type { HosEpisodeEntry, HosNudge } from '@/types/hos';
import { HOS_RESOLUTION, HOS_SITUATION_LABELS } from './copy';
import { deliveryLines, nudgeTitle, stepsLabel } from './lib';

export interface HosEpisodeListProps {
    episodes: HosEpisodeEntry[];
    teamSlug: string | null;
    empty: string;
}

/** Situaciones HOS con los avisos que SAM mandó y su resultado. */
export function HosEpisodeList({
    episodes,
    teamSlug,
    empty,
}: HosEpisodeListProps) {
    if (episodes.length === 0) {
        return (
            <p className="px-4 py-6 text-center text-xs text-fg-3">{empty}</p>
        );
    }

    return (
        <ul className="flex flex-col divide-y divide-border">
            {episodes.map((episode) => (
                <EpisodeItem
                    key={episode.id}
                    episode={episode}
                    teamSlug={teamSlug}
                />
            ))}
        </ul>
    );
}

function EpisodeItem({
    episode,
    teamSlug,
}: {
    episode: HosEpisodeEntry;
    teamSlug: string | null;
}) {
    const resolution =
        episode.resolution === null
            ? null
            : (HOS_RESOLUTION[episode.resolution] ?? null);

    return (
        <li className="flex flex-col gap-2 px-4 py-3">
            <div className="flex flex-wrap items-center justify-between gap-2">
                <span className="text-sm font-semibold text-fg-1">
                    {HOS_SITUATION_LABELS[episode.situation]}
                </span>
                <div className="flex flex-wrap items-center gap-1.5">
                    {episode.escalatedAt ? (
                        <StatusBadge
                            size="sm"
                            tone="high"
                            label="Escalado a tu equipo"
                        />
                    ) : null}
                    {resolution ? (
                        <StatusBadge size="sm" dot {...resolution} />
                    ) : episode.resolvedAt === null ? (
                        <StatusBadge size="sm" dot pulse tone="warn" label="Abierto" />
                    ) : null}
                </div>
            </div>
            <p className="text-xs text-fg-3">
                Desde {formatDateTime(episode.openedAt)}
                {episode.resolvedAt
                    ? ` · cerró ${relativeLabel(episode.resolvedAt)}`
                    : ''}
                {` · ${stepsLabel(episode.ladderStep)}`}
                {episode.incident && teamSlug !== null ? (
                    <>
                        {' · '}
                        <Link
                            href={incidentRoutes.show.url([
                                teamSlug,
                                episode.incident.id,
                            ])}
                            className="text-primary hover:underline"
                        >
                            Incidente {episode.incident.reference}
                        </Link>
                    </>
                ) : null}
            </p>
            {episode.nudges.length > 0 ? (
                <ol className="flex flex-col gap-1">
                    {episode.nudges.map((nudge) => (
                        <NudgeItem key={nudge.id} nudge={nudge} />
                    ))}
                </ol>
            ) : null}
        </li>
    );
}

function NudgeItem({ nudge }: { nudge: HosNudge }) {
    return (
        <li className="flex flex-wrap items-center gap-x-2 gap-y-1 text-xs">
            <span className="text-fg-3 tabular-nums">
                {formatClock(nudge.createdAt)}
            </span>
            <span className="text-fg-2">{nudgeTitle(nudge)}</span>
            {deliveryLines(nudge).map((line) => (
                <StatusBadge
                    key={line.key}
                    size="sm"
                    tone={line.tone}
                    label={line.text}
                />
            ))}
        </li>
    );
}
```

`resources/js/components/sam/hos/hos-driver-panel.tsx`:

```tsx
import { Panel } from '@/components/sam/panel';
import { hoursMinutesLabel, relativeLabel } from '@/lib/time';
import { TONE_TEXT } from '@/lib/tone';
import { cn } from '@/lib/utils';
import type { HosDriverPanelData } from '@/types/hos';
import { HosClockBars } from './hos-clock-bars';
import { HosEpisodeList } from './hos-episode-list';
import { HosStatusBadge } from './hos-status-badge';

export interface HosDriverPanelProps {
    hos: HosDriverPanelData;
    teamSlug: string | null;
}

/** Pestaña HOS del detalle del chofer. */
export function HosDriverPanel({ hos, teamSlug }: HosDriverPanelProps) {
    const { state } = hos;

    return (
        <div className="grid items-start gap-4 lg:grid-cols-[minmax(0,3fr)_minmax(0,2fr)]">
            <div className="flex min-w-0 flex-col gap-4">
                <Panel
                    title="Horas de servicio"
                    description={
                        state
                            ? `Lectura de Samsara ${relativeLabel(state.observedAt)}${state.asset ? ` · ${state.asset.name}` : ''}`
                            : 'Este chofer aún no entra al monitoreo HOS.'
                    }
                    action={
                        state ? (
                            <HosStatusBadge
                                dutyStatus={state.dutyStatus}
                                appDisconnected={
                                    state.appDisconnectedSince !== null
                                }
                            />
                        ) : null
                    }
                    bodyClassName="gap-3 p-4"
                >
                    {state ? (
                        <>
                            <HosClockBars clocks={state.clocks} />
                            {state.violationSeconds > 0 ? (
                                <p
                                    className={cn(
                                        'text-xs font-medium',
                                        TONE_TEXT.critical,
                                    )}
                                >
                                    Samsara marca{' '}
                                    {hoursMinutesLabel(state.violationSeconds)}{' '}
                                    en infracción.
                                </p>
                            ) : null}
                            {state.stale ? (
                                <p className="text-xs text-fg-3">
                                    Sin lectura reciente de Samsara: los
                                    relojes pueden estar atrasados.
                                </p>
                            ) : null}
                        </>
                    ) : (
                        <p className="text-xs text-fg-3">
                            Entra cuando maneja un tracto vigilado que cumple
                            la configuración de HOS.
                        </p>
                    )}
                </Panel>
                <Panel
                    title="Lo que está pasando"
                    description="Situaciones abiertas y los avisos que SAM ya le mandó."
                >
                    <HosEpisodeList
                        episodes={hos.openEpisodes}
                        teamSlug={teamSlug}
                        empty="Nada abierto: va en regla."
                    />
                </Panel>
            </div>
            <div className="flex min-w-0 flex-col gap-4">
                <Panel
                    title="Historial"
                    description="Situaciones cerradas, de la más reciente a la más antigua."
                >
                    <HosEpisodeList
                        episodes={hos.history}
                        teamSlug={teamSlug}
                        empty="Sin situaciones anteriores."
                    />
                </Panel>
            </div>
        </div>
    );
}
```

`resources/js/components/sam/hos/hos-fleet-table.tsx`:

```tsx
import { CellEmpty } from '@/components/sam/data-table/cell-empty';
import { DataTable } from '@/components/sam/data-table/data-table';
import type { DataTableColumn } from '@/components/sam/data-table/data-table';
import { StatusBadge } from '@/components/sam/status-badge';
import { relativeLabel } from '@/lib/time';
import { TONE_TEXT } from '@/lib/tone';
import { cn } from '@/lib/utils';
import type { HosFleetRow } from '@/types/hos';
import { HOS_SITUATION_LABELS, HOS_URGENCY } from './copy';
import { HosStatusBadge } from './hos-status-badge';
import { nearestLabel } from './lib';

export interface HosFleetTableProps {
    rows: HosFleetRow[];
    onSelect: (row: HosFleetRow) => void;
}

/** Choferes vigilados en el orden del servidor (lo más urgente primero). */
export function HosFleetTable({ rows, onSelect }: HosFleetTableProps) {
    const columns: DataTableColumn<HosFleetRow>[] = [
        {
            key: 'driver',
            header: 'Chofer',
            cell: (row) => (
                <div className="flex min-w-0 flex-col">
                    <span className="truncate text-sm font-medium text-fg-1">
                        {row.driver.fullName}
                    </span>
                    <span className="truncate text-2xs text-fg-3">
                        {row.asset?.name ?? 'Sin tracto'}
                    </span>
                </div>
            ),
        },
        {
            key: 'urgency',
            header: 'Situación',
            width: 'w-44',
            cell: (row) => (
                <StatusBadge size="sm" dot {...HOS_URGENCY[row.urgency]} />
            ),
        },
        {
            key: 'status',
            header: 'Estado',
            width: 'w-48',
            cell: (row) => (
                <HosStatusBadge
                    dutyStatus={row.dutyStatus}
                    appDisconnected={row.appDisconnected}
                />
            ),
        },
        {
            key: 'nearest',
            header: 'Reloj más corto',
            width: 'w-36',
            numeric: true,
            cell: (row) => nearestLabel(row),
        },
        {
            key: 'episodes',
            header: 'Abierto',
            cell: (row) =>
                row.openEpisodes.length === 0 ? (
                    <CellEmpty />
                ) : (
                    <span className="text-xs text-fg-2">
                        {row.openEpisodes
                            .map((episode) => HOS_SITUATION_LABELS[episode.situation])
                            .join(' · ')}
                    </span>
                ),
        },
        {
            key: 'observed',
            header: 'Lectura',
            width: 'w-28',
            cell: (row) => (
                <span
                    className={cn(
                        'text-xs',
                        row.stale ? TONE_TEXT.warn : 'text-fg-3',
                    )}
                >
                    {relativeLabel(row.observedAt)}
                </span>
            ),
        },
    ];

    return (
        <DataTable
            columns={columns}
            rows={rows}
            rowKey={(row) => row.driver.id}
            onRowClick={onSelect}
        />
    );
}
```

- [ ] **Step 6: Página de flota** `resources/js/pages/drivers/hos.tsx`:

```tsx
import type { SharedPageProps } from '@inertiajs/core';
import { Head, router, usePage } from '@inertiajs/react';
import { Hourglass } from 'lucide-react';
import { useState } from 'react';
import { HosFleetTable } from '@/components/sam/hos/hos-fleet-table';
import { ListEmptyState, ListPage } from '@/components/sam/list-page';
import { useBroadcastReload } from '@/hooks/use-team-broadcasts';
import { formatNumber } from '@/lib/format';
import { TONE_TEXT } from '@/lib/tone';
import driverRoutes from '@/routes/drivers';
import type { HosFleetPageProps, HosFleetRow, HosFleetSummary } from '@/types/hos';

export default function DriversHos({ fleet }: HosFleetPageProps) {
    const page = usePage();
    const teamSlug = page.props.currentTeam?.slug ?? null;
    const [refreshing, setRefreshing] = useState(false);

    // Cada sondeo (~1 min) recarga sólo la lista.
    useBroadcastReload(
        { 'hos.clocks_updated': ['fleet'] },
        { resync: ['fleet'] },
    );

    const refresh = () =>
        router.reload({
            only: ['fleet'],
            onStart: () => setRefreshing(true),
            onFinish: () => setRefreshing(false),
        });

    const open = (row: HosFleetRow) => {
        if (teamSlug !== null) {
            router.visit(
                driverRoutes.show.url([teamSlug, row.driver.id], {
                    query: { pestana: 'hos' },
                }),
            );
        }
    };

    return (
        <>
            <Head title="HOS (EE. UU.)" />
            <ListPage
                title="HOS (EE. UU.)"
                description="Choferes vigilados en Estados Unidos, del más urgente al más holgado."
                meta={<FleetMeta summary={fleet.summary} />}
                onRefresh={refresh}
                refreshing={refreshing}
            >
                {fleet.rows.length === 0 ? (
                    <ListEmptyState
                        icon={Hourglass}
                        filtered={false}
                        title="Nadie en monitoreo HOS ahora"
                        description="Entran los choferes que manejan un tracto vigilado y cumplen la configuración de HOS de tu empresa."
                        filteredDescription=""
                    />
                ) : (
                    <div className="min-h-0 flex-1 overflow-y-auto">
                        <HosFleetTable rows={fleet.rows} onSelect={open} />
                    </div>
                )}
            </ListPage>
        </>
    );
}

function FleetMeta({ summary }: { summary: HosFleetSummary }) {
    return (
        <span className="text-xs text-fg-3">
            <span className="font-medium text-fg-1">
                {formatNumber(summary.total)}
            </span>{' '}
            {summary.total === 1 ? 'chofer vigilado' : 'choferes vigilados'}
            {summary.violation > 0 ? (
                <>
                    {' · '}
                    <span className={TONE_TEXT.critical}>
                        {formatNumber(summary.violation)} en infracción
                    </span>
                </>
            ) : null}
            {summary.at_limit > 0 ? (
                <>
                    {' · '}
                    <span className={TONE_TEXT.high}>
                        {formatNumber(summary.at_limit)} en el límite
                    </span>
                </>
            ) : null}
        </span>
    );
}

DriversHos.layout = (props: SharedPageProps) => ({
    breadcrumbs: [
        {
            title: 'HOS (EE. UU.)',
            href: props.currentTeam
                ? driverRoutes.hos.index.url(props.currentTeam.slug)
                : '#',
        },
    ],
});
```

- [ ] **Step 7: Pestaña del chofer.** En `resources/js/types/drivers.ts`: `import type { HosDriverPanelData } from '@/types/hos';` y en `DriverShowProps`:

```ts
    /** Pestaña HOS; null sin la feature `hos_monitoring`. */
    hos: HosDriverPanelData | null;
```

En `resources/js/pages/drivers/show.tsx`: importar `useState` de `react`, `HosDriverPanel` de `@/components/sam/hos/hos-driver-panel`, `driverTabFromUrl` de `@/components/sam/hos/lib`, `TabBar` de `@/components/sam/tab-bar` y `useBroadcastReload` de `@/hooks/use-team-broadcasts`; recibir `hos` en las props y, al inicio del componente (después de `teamSlug`):

```tsx
    const [tab, setTab] = useState<'resumen' | 'hos'>(() =>
        hos !== null ? driverTabFromUrl(page.url) : 'resumen',
    );

    // Cada sondeo HOS (~1 min) recarga sólo la pestaña HOS.
    useBroadcastReload(
        { 'hos.clocks_updated': () => (hos === null ? null : ['hos']) },
        { resync: hos === null ? [] : ['hos'] },
    );
    const openEpisodes = hos?.openEpisodes.length ?? 0;
```

y, debajo de `<DriverHero ... />`, envolver el grid existente:

```tsx
                {hos !== null ? (
                    <TabBar
                        aria-label="Secciones del conductor"
                        items={[
                            { key: 'resumen', label: 'Resumen' },
                            {
                                key: 'hos',
                                label: 'HOS',
                                ...(openEpisodes > 0
                                    ? { count: openEpisodes }
                                    : {}),
                            },
                        ]}
                        value={tab}
                        onChange={(key) =>
                            setTab(key === 'hos' ? 'hos' : 'resumen')
                        }
                    />
                ) : null}

                {tab === 'hos' && hos !== null ? (
                    <HosDriverPanel hos={hos} teamSlug={teamSlug} />
                ) : (
                    /* el <div className="grid ..."> de siempre, sin cambios */
                )}
```

- [ ] **Step 8: Menú.** En `components/sam/ops-sidebar.tsx`, importar `Hourglass` de `lucide-react` y agregar en el grupo "Recursos", después de "Conductores":

```ts
                    {
                        label: 'HOS (EE. UU.)',
                        can: 'hos',
                        icon: Hourglass,
                        href: driverRoutes.hos.index.url(teamSlug),
                    },
```

(la regla de "prefijo más largo gana" ya evita que "Conductores" se ilumine en `/drivers/hos`).

- [ ] **Step 9:** Run `npm run types:check && npm run lint:check && npm run format:check && npm test` y `php artisan test --compact --filter='HosPanelTest|DriverShowPageTest'` → PASS.
- [ ] **Step 10: Commit** `feat: pestana hos del chofer y vista hos de la flota`

---

### Task 8: Gates, revisión y PR

- [ ] **Step 1:** `vendor/bin/pint --dirty --format agent`
- [ ] **Step 2:** `composer analyse` → sin errores (no tocar `phpstan-baseline.neon` ni usar `@phpstan-ignore`; arreglar la causa). Puntos probables: `mixed` de `$this->input()` en el FormRequest (ya pasa por `self::int()`/`is_array`), `payload_json` y la relación `deliveries.channel` en `BuildHosDriverPanel`, el `@var` de `HosProviderCache::tags()`, los `array{...}` de retorno de `ListHosFleet`/`BuildHosConfigForm`, el `$state->driver` nullable en `ListHosFleet`.
- [ ] **Step 3:** `php artisan wayfinder:generate --with-form` y `php artisan test --compact` (suite completa) → PASS. Si `public/hot` existe (dev server), apartarlo durante la corrida (SSR, memoria `prepush_ssr_hot_file`).
- [ ] **Step 4:** Front: `npm run types:check && npm run lint:check && npm run format:check && npm test && npm run build` → PASS.
- [ ] **Step 5:** Revisión de aislamiento: lanzar el agente `tenant-isolation-reviewer` sobre la rama (foco: `HosMonitoringPolicy` + `AuthorizeAction::isFeatureEnabled`, `UpdateHosMonitoringConfigRequest::teamAssetRule`, `HosProviderCache` (llaves con team), `PreviewHosEnrollment`, `BuildHosDriverPanel::nudges` (notificaciones y entregas por `team_id`), `ListHosFleet`, `HosPanelController::driver` (404 cross-team), `HosClocksUpdatedBroadcast` (canal del team)) y atender lo que reporte con commits nuevos.
- [ ] **Step 6:** Verificación visual (dev, team 5 `sam-pruebas` con `hos_monitoring` encendida): `npm run build`, abrir Ajustes → HOS (selector de etiquetas con `USA`/`TRACTOS USA`, vista previa que cambia al elegir/excluir, validación de la escalera, guardar y ver la versión en Avanzado → historial), la vista HOS (EE. UU.) y la pestaña HOS de un chofer `USA`; con `horizon` y `scheduler` arriba, confirmar que la lista se repinta sola cada minuto. Revisar en móvil (375 px) que la sección y la tabla no desborden. Tras `composer install`, `docker compose restart horizon scheduler` (memoria `horizon_stale_autoloader_after_composer`).
- [ ] **Step 7:** Push y PR (`feat: monitoreo hos — pr 3 configuracion y panel`). Cuerpo: resumen, desviaciones del spec (sección arriba, en especial #1 puerta opt-in, #2 vista previa sobre la última lectura, #7 broadcast condicionado, #11 seguimientos del PR 2), pruebas corridas, capturas de la sección y del panel. **Sin** `Co-Authored-By` ni banner "Generated with Claude Code" (regla del repo). Esperar CI (`gh pr checks --watch`) y arreglar lo rojo con commits nuevos.

---

## Autorrevisión de nombres y tipos

- Policy: `viewAny` / `viewConfig` / `updateConfig` sobre `HosDriverState::class` en Tasks 1, 2, 3, 5 (FormRequests usan `can('updateConfig'|'viewConfig', HosDriverState::class)`).
- Nav: `nav.hos` / `nav.hosConfig` (PHP Task 1) = `NavPermissions.hos` / `.hosConfig` (TS Task 1) = `can: 'hos'` (sidebar Task 7) y `nav.hosConfig` (`use-settings-nav`, Task 4).
- Rutas: `tenant-config.hos.update|tags|preview` ↔ `tenantConfigRoutes.hos.update|tags|preview`; `drivers.hos.index` ↔ `driverRoutes.hos.index`; API `api.tenant-config.hos.{show,update,tags,preview}`, `api.drivers.hos.{index,show}`.
- Props: `hos` (`settings/tenant-config`) = `HosConfigForm` con `config/defaults/assets/channels/hasIntegration/canManage/minGapMinutes` (`BuildHosConfigForm`); `hos` (`drivers/show`) = `HosDriverPanelData` (`state/openEpisodes/history`, `BuildHosDriverPanel`); `fleet` (`drivers/hos`) = `HosFleetData` (`rows/summary`, `ListHosFleet`).
- Llaves de error: `lead_minutes`, `cycle_lead_hours`, `rest_complete_nudge_minutes`, `rest_complete_expire_minutes`, `excluded_asset_ids`, `ladder`, `ladder.{i}.after_minutes|channels|escalate` iguales en `UpdateHosMonitoringConfigRequest` y `validateDraft`.
- Caché: `HosProviderCache::tagsKey` = `hos:tags:{team}:{integración}` (la del sondeo); `readingsKey` = `hos:clocks:{team}:{integración}`.
- Evento: `hos.clocks_updated` en `HosClocksUpdatedBroadcast::broadcastAs()`, `TEAM_EVENTS`, `TeamBroadcastEventMap` y los `useBroadcastReload` de `drivers/show` y `drivers/hos`.
- Códigos de log nuevos: `hos.config.updated`, `hos.tags.listed`, `hos.preview.computed` (+ `broadcast` en `hos.poll.completed`), todos en `docs/SAM/logging.md`.
