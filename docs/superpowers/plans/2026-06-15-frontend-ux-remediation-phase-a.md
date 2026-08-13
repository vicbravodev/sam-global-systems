# Remediación de usabilidad — Fase A (P0 idioma/enums) Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Eliminar la mezcla inglés/español y los enums crudos visibles en el producto, dejando que el monitorista lea todo en español.

**Architecture:** El producto es español-only (`APP_LOCALE=es`); no se introduce i18n multi-idioma. Las etiquetas de enum tienen una única fuente de verdad: un método `label(): string` en cada enum de dominio. Los controladores que serializan datos a Inertia pasan la etiqueta humanizada (no el `value`/`code` crudo). Los strings que hoy viven como datos seedeados en inglés (nombres/descripciones de roles, categorías y tipos de evento) se traducen en sus seeders, que ya usan `updateOrCreate` keyed por `code` (additive, sin migración).

**Tech Stack:** PHP 8.5 enums · Laravel 13 seeders · Inertia v3 + React 19 + TypeScript · PHPUnit 12 (sqlite `:memory:`) · Pint.

**Alcance de esta fase:** A1 enum `label()`, A2 outcome/clasificación humanizados en eventos y reglas, A3 categorías/tipos de evento en español, A4 roles en español. Cada página Inertia tocada conserva sus tests `assertInertia` verdes; los nuevos labels se cubren con feature/unit tests. Gate de cierre: `vendor/bin/pint --test`, `php artisan test --compact`, `npm run types:check && lint:check && format:check`, `npm run build`.

---

## File Structure

| Archivo | Responsabilidad | Acción |
|---|---|---|
| `app/Domains/Decisions/Enums/DecisionOutcomeCode.php` | Enum outcome de decisión + `label()` | Modificar |
| `app/Domains/AI/Enums/EventClassification.php` | Enum clasificación IA + `label()` | Modificar |
| `app/Domains/Drivers/Enums/DriverStatus.php` | Enum estado conductor + `label()` | Modificar |
| `app/Domains/Assets/Enums/AssetCategory.php` | Enum categoría de activo + `label()` | Modificar |
| `tests/Unit/Domains/Shared/EnumLabelsTest.php` | Verifica que `label()` devuelve español | Crear |
| `app/Http/Controllers/Normalization/EventsPageController.php` | Pasa `classificationLabel`/`outcomeLabel` al detalle de evento | Modificar |
| `app/Http/Controllers/Decisions/RulesPageController.php` | Pasa el label del outcome en la lista de reglas | Modificar |
| `resources/js/pages/events/show.tsx` | Render del label en vez del code crudo | Modificar |
| `resources/js/pages/rules/index.tsx` | Render del label del outcome | Modificar |
| `database/seeders/NormalizationSeeder.php` | Nombres/descr de categorías y tipos de evento en español | Modificar |
| `database/seeders/AccessSeeder.php` | Nombres/descr de roles (y permisos) en español | Modificar |
| `tests/Feature/Domains/Normalization/EventTypeSpanishTest.php` | Categorías/tipos seedeados en español | Crear |
| `tests/Feature/Domains/Access/RoleSpanishTest.php` | Roles seedeados en español | Crear |

---

## Task A1: `label()` en los enums de dominio

**Files:**
- Modify: `app/Domains/Decisions/Enums/DecisionOutcomeCode.php`
- Modify: `app/Domains/AI/Enums/EventClassification.php`
- Modify: `app/Domains/Drivers/Enums/DriverStatus.php`
- Modify: `app/Domains/Assets/Enums/AssetCategory.php`
- Test: `tests/Unit/Domains/Shared/EnumLabelsTest.php`

- [x] **Step 1: Write the failing test**

Create `tests/Unit/Domains/Shared/EnumLabelsTest.php`:

```php
<?php

namespace Tests\Unit\Domains\Shared;

use App\Domains\AI\Enums\EventClassification;
use App\Domains\Assets\Enums\AssetCategory;
use App\Domains\Decisions\Enums\DecisionOutcomeCode;
use App\Domains\Drivers\Enums\DriverStatus;
use PHPUnit\Framework\TestCase;

final class EnumLabelsTest extends TestCase
{
    public function test_decision_outcome_labels_are_spanish(): void
    {
        $this->assertSame('Revisión humana', DecisionOutcomeCode::RequireHumanReview->label());
        $this->assertSame('Solo registro', DecisionOutcomeCode::LogOnly->label());
        $this->assertSame('Incidente', DecisionOutcomeCode::Incident->label());
    }

    public function test_event_classification_labels_are_spanish(): void
    {
        $this->assertSame('Sin determinar', EventClassification::Unclear->label());
        $this->assertSame('Evento real', EventClassification::RealEvent->label());
        $this->assertSame('Falso positivo', EventClassification::FalsePositive->label());
    }

    public function test_driver_status_labels_are_spanish(): void
    {
        $this->assertSame('Activo', DriverStatus::Active->label());
        $this->assertSame('En revisión', DriverStatus::UnderReview->label());
    }

    public function test_asset_category_labels_are_spanish(): void
    {
        $this->assertSame('Vehículo', AssetCategory::Vehicle->label());
        $this->assertSame('Dispositivo GPS', AssetCategory::GpsDevice->label());
    }

    public function test_every_case_has_a_non_empty_label(): void
    {
        foreach ([
            ...DecisionOutcomeCode::cases(),
            ...EventClassification::cases(),
            ...DriverStatus::cases(),
            ...AssetCategory::cases(),
        ] as $case) {
            $this->assertNotSame('', $case->label());
            $this->assertNotSame($case->value, $case->label());
        }
    }
}
```

- [x] **Step 2: Run the test to verify it fails**

Run: `php artisan test --compact --filter=EnumLabelsTest`
Expected: FAIL — `Call to undefined method ...::label()`.

- [x] **Step 3: Add `label()` to `DecisionOutcomeCode`**

En `app/Domains/Decisions/Enums/DecisionOutcomeCode.php`, dentro del enum (después de `createsIncident()`), añadir:

```php
    public function label(): string
    {
        return match ($this) {
            self::Ignore => 'Ignorar',
            self::LogOnly => 'Solo registro',
            self::Alert => 'Alerta',
            self::Incident => 'Incidente',
            self::Escalate => 'Escalar',
            self::RequireHumanReview => 'Revisión humana',
        };
    }
```

- [x] **Step 4: Add `label()` to `EventClassification`**

En `app/Domains/AI/Enums/EventClassification.php`, después de `isActionable()`:

```php
    public function label(): string
    {
        return match ($this) {
            self::RealEvent => 'Evento real',
            self::FalsePositive => 'Falso positivo',
            self::Noise => 'Ruido',
            self::Duplicate => 'Duplicado',
            self::Unclear => 'Sin determinar',
            self::PendingEvidence => 'Evidencia pendiente',
        };
    }
```

- [x] **Step 5: Add `label()` to `DriverStatus`**

En `app/Domains/Drivers/Enums/DriverStatus.php`, dentro del enum:

```php
    public function label(): string
    {
        return match ($this) {
            self::Active => 'Activo',
            self::OffDuty => 'Fuera de turno',
            self::Unavailable => 'No disponible',
            self::Suspended => 'Suspendido',
            self::UnderReview => 'En revisión',
        };
    }
```

- [x] **Step 6: Add `label()` to `AssetCategory`**

En `app/Domains/Assets/Enums/AssetCategory.php`, dentro del enum:

```php
    public function label(): string
    {
        return match ($this) {
            self::Vehicle => 'Vehículo',
            self::Trailer => 'Remolque',
            self::Camera => 'Cámara',
            self::GpsDevice => 'Dispositivo GPS',
            self::Sensor => 'Sensor',
        };
    }
```

- [x] **Step 7: Run the test to verify it passes**

Run: `php artisan test --compact --filter=EnumLabelsTest`
Expected: PASS (5 tests).

- [x] **Step 8: Format and commit**

```bash
vendor/bin/pint --dirty --format agent
git add app/Domains/Decisions/Enums/DecisionOutcomeCode.php app/Domains/AI/Enums/EventClassification.php app/Domains/Drivers/Enums/DriverStatus.php app/Domains/Assets/Enums/AssetCategory.php tests/Unit/Domains/Shared/EnumLabelsTest.php
git commit -m "feat(i18n): label() en español en enums de outcome/clasificación/estado/categoría"
```

---

## Task A2: outcome y clasificación humanizados en eventos y reglas

El detalle de evento muestra `REQUIRE_HUMAN_REVIEW` y `unclear` crudos; la lista de reglas muestra el `code` del outcome. Se pasan etiquetas humanizadas desde el backend usando los `label()` de A1.

**Files:**
- Modify: `app/Http/Controllers/Normalization/EventsPageController.php`
- Modify: `app/Http/Controllers/Decisions/RulesPageController.php`
- Modify: `resources/js/pages/events/show.tsx`
- Modify: `resources/js/pages/rules/index.tsx`
- Test: extender el feature test existente de la página de eventos y el de reglas.

- [x] **Step 1: Localizar el ensamblado de props del detalle de evento**

Run: `grep -n "classification\|outcome\|->value\|EventClassification\|DecisionOutcomeCode" app/Http/Controllers/Normalization/EventsPageController.php`
Anotar el método que arma el payload del detalle (el que produce `Clasificación` y `Resultado` que vimos en `detail-events`) y las líneas donde hoy se emite el `value`/`code` crudo de clasificación y outcome.

- [x] **Step 2: Write the failing test (evento expone labels en español)**

Localizar el feature test de la página de eventos: `grep -rln "events/show\|EventsPage\|component('events" tests/`. En ese archivo (o crear `tests/Feature/Domains/Normalization/EventDetailLabelsTest.php` siguiendo su estilo con `RefreshDatabase` y factories), añadir un test que cargue el detalle de un evento con clasificación `unclear` y outcome `REQUIRE_HUMAN_REVIEW` y asserte vía `assertInertia` que el prop incluye `classificationLabel === 'Sin determinar'` y `outcomeLabel === 'Revisión humana'`. Usar factories existentes (`NormalizedEvent`, evaluación IA, decisión); nunca `Model::create()` manual.

- [x] **Step 3: Run the test to verify it fails**

Run: `php artisan test --compact --filter=EventDetailLabels`
Expected: FAIL — el prop `classificationLabel`/`outcomeLabel` no existe todavía.

- [x] **Step 4: Emitir los labels desde `EventsPageController`**

En el método del detalle (identificado en Step 1), donde hoy se serializa la clasificación y el outcome, añadir los campos humanizados junto a los crudos (mantener los crudos por compatibilidad):

```php
// donde $classification es App\Domains\AI\Enums\EventClassification (o tryFrom del valor)
'classification' => $classification?->value,
'classificationLabel' => $classification?->label(),
// donde $outcome es App\Domains\Decisions\Enums\DecisionOutcomeCode
'outcome' => $outcome?->value,
'outcomeLabel' => $outcome?->label(),
```

Si en el controlador el valor llega como string, resolver el enum con `EventClassification::tryFrom($value)` / `DecisionOutcomeCode::tryFrom($value)` antes de llamar `label()`.

- [x] **Step 5: Consumir el label en `events/show.tsx`**

Run: `grep -n "classification\|outcome\|Clasificación\|Resultado" resources/js/pages/events/show.tsx`
Sustituir el render del code crudo por el label, con fallback al crudo:

```tsx
{event.classificationLabel ?? event.classification ?? '—'}
{decision.outcomeLabel ?? decision.outcome ?? '—'}
```

Actualizar la interfaz TypeScript local del componente para incluir `classificationLabel?: string | null` y `outcomeLabel?: string | null`.

- [x] **Step 6: Run the test to verify it passes**

Run: `php artisan test --compact --filter=EventDetailLabels`
Expected: PASS.

- [x] **Step 7: Write the failing test (reglas muestran label del outcome)**

Run: `grep -rln "rules/index\|component('rules" tests/` para hallar el feature test de reglas. Añadir un test que cargue la página de reglas con una regla cuyo outcome sea `REQUIRE_HUMAN_REVIEW` y asserte vía `assertInertia` que el prop de la fila incluye `outcomeLabel === 'Revisión humana'` (o que el array `outcomes` incluye `label`). Run para confirmar fallo.

- [x] **Step 8: Emitir el label del outcome en `RulesPageController`**

Run: `grep -n "outcome\|DecisionOutcomeCode\|->value\|->code" app/Http/Controllers/Decisions/RulesPageController.php`
Donde se arma cada fila de regla y el listado `outcomes`, añadir `outcomeLabel` resolviendo `DecisionOutcomeCode::tryFrom($code)?->label()` con fallback al code:

```php
'outcomeCode' => $code,
'outcomeLabel' => $code !== null ? (DecisionOutcomeCode::tryFrom($code)?->label() ?? $code) : null,
```

- [x] **Step 9: Consumir el label en `rules/index.tsx`**

Run: `grep -n "outcomeCode\|OUTCOME\|outcome" resources/js/pages/rules/index.tsx`
Render de la columna OUTCOME: `{rule.outcomeLabel ?? rule.outcomeCode ?? '—'}`. Añadir `outcomeLabel?: string | null` a la interfaz de la fila.

- [x] **Step 10: Run tests + front gates**

Run: `php artisan test --compact --filter="EventDetailLabels|Rules"`
Expected: PASS.
Run: `npm run types:check`
Expected: sin errores de tipos.

- [x] **Step 11: Format and commit**

```bash
vendor/bin/pint --dirty --format agent
git add app/Http/Controllers/Normalization/EventsPageController.php app/Http/Controllers/Decisions/RulesPageController.php resources/js/pages/events/show.tsx resources/js/pages/rules/index.tsx tests/
git commit -m "feat(i18n): outcome y clasificación humanizados en detalle de evento y reglas"
```

---

## Task A3: categorías y tipos de evento en español

Los nombres/descripciones de `event_categories` y `event_types` están en inglés en el seeder y se muestran como tipo de evento (p.ej. "Device Offline"). Se traducen en `NormalizationSeeder`, que usa `updateOrCreate` keyed por `code` (additive: re-seed actualiza `name`/`description`, sin migración).

**Files:**
- Modify: `database/seeders/NormalizationSeeder.php`
- Test: `tests/Feature/Domains/Normalization/EventTypeSpanishTest.php`

- [x] **Step 1: Write the failing test**

Create `tests/Feature/Domains/Normalization/EventTypeSpanishTest.php`:

```php
<?php

namespace Tests\Feature\Domains\Normalization;

use Database\Seeders\NormalizationSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

final class EventTypeSpanishTest extends TestCase
{
    use RefreshDatabase;

    public function test_categories_and_types_are_seeded_in_spanish(): void
    {
        $this->seed(NormalizationSeeder::class);

        $this->assertSame('Seguridad', DB::table('event_categories')->where('code', 'safety')->value('name'));
        $this->assertSame('Emergencia', DB::table('event_categories')->where('code', 'emergency')->value('name'));
        $this->assertSame('Botón de pánico', DB::table('event_types')->where('code', 'panic_button')->value('name'));
        $this->assertSame('Frenado brusco', DB::table('event_types')->where('code', 'harsh_braking')->value('name'));
    }
}
```

> Nota: confirmar los nombres de tabla reales con `grep -n "table(" database/seeders/NormalizationSeeder.php` antes de correr; ajustar `event_categories`/`event_types` si difieren.

- [x] **Step 2: Run the test to verify it fails**

Run: `php artisan test --compact --filter=EventTypeSpanish`
Expected: FAIL — los valores siguen en inglés.

- [x] **Step 3: Traducir categorías**

En `database/seeders/NormalizationSeeder.php`, el array de categorías:

```php
['code' => 'safety', 'name' => 'Seguridad', 'description' => 'Eventos de seguridad por riesgos del conductor o del vehículo'],
['code' => 'emergency', 'name' => 'Emergencia', 'description' => 'Eventos críticos de emergencia que requieren respuesta inmediata'],
['code' => 'compliance', 'name' => 'Cumplimiento', 'description' => 'Violaciones de cumplimiento normativo y de políticas'],
['code' => 'operational', 'name' => 'Operativo', 'description' => 'Eventos operativos generales para el monitoreo de la flota'],
['code' => 'maintenance', 'name' => 'Mantenimiento', 'description' => 'Eventos de mantenimiento de equipos y dispositivos'],
```

- [x] **Step 4: Traducir tipos de evento (todo el array `$eventTypes`)**

Traducir el `name` de **cada** entrada del array `$eventTypes` (los `code`, `category` y `severity` no se tocan). Glosario (aplicar a todas las entradas del array, incluidas las que estén más abajo de la línea 109 con el mismo criterio):

```
panic_button → Botón de pánico            collision → Colisión
rollover_protection → Protección por vuelco   harsh_braking → Frenado brusco
speeding → Exceso de velocidad            severe_speeding → Exceso de velocidad severo
driver_fatigue → Fatiga del conductor     driver_distraction → Distracción del conductor
forward_collision_warning → Aviso de colisión frontal   harsh_acceleration → Aceleración brusca
harsh_turn → Giro brusco                  lane_departure → Salida de carril
following_distance → Distancia de seguimiento   near_collision → Casi colisión
aggressive_driving → Conducción agresiva  rolling_stop → Alto incompleto
ran_red_light → Se pasó el semáforo en rojo   mobile_usage → Uso de móvil
yaw_control → Control de derrape          reversing → Marcha atrás
u_turn → Vuelta en U                      did_not_yield → No cedió el paso
railroad_crossing_violation → Violación de cruce ferroviario
```

Para cualquier `code` adicional presente en el array y no listado arriba (p.ej. `device_offline → Dispositivo sin conexión`, tipos `operational`/`maintenance`), traducir su `name` al español manteniendo el sentido. La lista es finita y vive solo en este archivo: traducir **todas** las entradas, no dejar ninguna en inglés.

- [x] **Step 5: Run the test to verify it passes**

Run: `php artisan test --compact --filter=EventTypeSpanish`
Expected: PASS.

- [x] **Step 6: Format and commit**

```bash
vendor/bin/pint --dirty --format agent
git add database/seeders/NormalizationSeeder.php tests/Feature/Domains/Normalization/EventTypeSpanishTest.php
git commit -m "feat(i18n): categorías y tipos de evento seedeados en español"
```

> Despliegue: re-correr `php artisan db:seed --class=NormalizationSeeder` en cada entorno para actualizar los nombres existentes (additive, idempotente vía updateOrCreate).

---

## Task A4: roles del sistema en español

Los nombres y descripciones de roles en `AccessSeeder` están en inglés y se muestran en la card de "Equipo y roles". Se traducen en el seeder (keyed por `code` con `updateOrCreate`). Los `code` no cambian (son contrato del RBAC).

**Files:**
- Modify: `database/seeders/AccessSeeder.php`
- Test: `tests/Feature/Domains/Access/RoleSpanishTest.php`

- [x] **Step 1: Write the failing test**

Create `tests/Feature/Domains/Access/RoleSpanishTest.php`:

```php
<?php

namespace Tests\Feature\Domains\Access;

use Database\Seeders\AccessSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

final class RoleSpanishTest extends TestCase
{
    use RefreshDatabase;

    public function test_roles_are_seeded_in_spanish(): void
    {
        $this->seed(AccessSeeder::class);

        $this->assertSame('Analista', DB::table('roles')->where('code', 'analyst')->value('name'));
        $this->assertSame('Gestor de facturación', DB::table('roles')->where('code', 'billing_manager')->value('name'));
        $this->assertSame(
            'Gestión completa del tenant, incluyendo facturación y usuarios',
            DB::table('roles')->where('code', 'tenant_admin')->value('description'),
        );
    }
}
```

> Confirmar el nombre real de la tabla con `grep -n "table(\|Role::" database/seeders/AccessSeeder.php`; ajustar `roles` si difiere.

- [x] **Step 2: Run the test to verify it fails**

Run: `php artisan test --compact --filter=RoleSpanish`
Expected: FAIL — nombres en inglés.

- [x] **Step 3: Traducir el array de roles**

En `database/seeders/AccessSeeder.php`, el array de roles:

```php
['code' => 'super_admin', 'name' => 'Super Administrador', 'scope' => 'global', 'description' => 'Acceso total al sistema, omite todas las verificaciones de tenant'],
['code' => 'tenant_admin', 'name' => 'Administrador del tenant', 'scope' => 'tenant', 'description' => 'Gestión completa del tenant, incluyendo facturación y usuarios'],
['code' => 'supervisor', 'name' => 'Supervisor', 'scope' => 'tenant', 'description' => 'Supervisión operativa: incidentes, activos, conductores, reportes'],
['code' => 'monitorista', 'name' => 'Monitorista', 'scope' => 'tenant', 'description' => 'Monitoreo en tiempo real: ver/gestionar/resolver incidentes, ver activos'],
['code' => 'analyst', 'name' => 'Analista', 'scope' => 'tenant', 'description' => 'Analítica de solo lectura: reportes, análisis de IA, registros de auditoría'],
['code' => 'billing_manager', 'name' => 'Gestor de facturación', 'scope' => 'tenant', 'description' => 'Gestión de facturación y suscripción'],
['code' => 'viewer', 'name' => 'Observador', 'scope' => 'tenant', 'description' => 'Acceso de solo lectura a todos los módulos del tenant'],
```

- [x] **Step 4: Traducir el array de permisos (`name`/`description`)**

Traducir el `name` y `description` de cada permiso del array de permisos (los `code` y `module` no se tocan). Patrón: "View X"→"Ver X", "Manage X"→"Gestionar X", "Export"→"Exportar", "Execute"→"Ejecutar", "Send"→"Enviar", "Invite"→"Invitar", "Resolve"→"Resolver", "Close"→"Cerrar", "Override"→"Anular"/"Sobrescribir". Traducir las descripciones al español manteniendo el sentido. Cubrir **todas** las entradas del array.

- [x] **Step 5: Run the test to verify it passes**

Run: `php artisan test --compact --filter=RoleSpanish`
Expected: PASS.

- [x] **Step 6: Verificar que no se rompió RBAC**

Run: `php artisan test --compact tests/Feature/Domains/Access`
Expected: PASS (los tests existentes usan `code`, no `name`, así que no deben verse afectados).

- [x] **Step 7: Format and commit**

```bash
vendor/bin/pint --dirty --format agent
git add database/seeders/AccessSeeder.php tests/Feature/Domains/Access/RoleSpanishTest.php
git commit -m "feat(i18n): roles y permisos del sistema en español"
```

> Despliegue: re-correr `php artisan db:seed --class=AccessSeeder` en cada entorno.

---

## Cierre de la Fase A (gate completo)

- [x] **Step 1: Suite completa**

Run: `php artisan test --compact`
Expected: todo verde (incluye los ~624 tests previos + los nuevos).

- [x] **Step 2: Estilo backend**

Run: `vendor/bin/pint --test`
Expected: limpio.

- [x] **Step 3: Gates frontend**

Run: `npm run types:check && npm run lint:check && npm run format:check`
Expected: verdes.

- [x] **Step 4: Build**

Run: `npm run build`
Expected: exitoso.

- [x] **Step 5: Verificación visual (opcional, recomendado)**

Run: `node scripts/audit-ux.mjs --base=http://localhost` y revisar `detail-events`, `rules`, `settings-roles`, `events` — confirmar que outcome/clasificación/tipo/roles salen en español.

---

## Self-Review (cubierto por el plan)

- **Cobertura del spec (Fase A):** A1 enum labels (Task A1), A2 outcome/clasificación en eventos+reglas (Task A2), A3 categorías/tipos de evento (Task A3), A4 roles (Task A4). El badge de estado de conductor/activo ya estaba en español (`driver-status-badge.tsx`, `asset-status-badge.tsx`), por eso A2 del spec se reduce a confirmar consistencia — los `label()` de A1 dejan una única fuente de verdad para futuros usos server-side.
- **Decisión de admin "Tenants"/"Trial":** los textos del header/sidebar admin son strings de frontend/Inertia, no enums; se tratan en la Fase C (reorganización de navegación) o como ajuste puntual de strings — fuera del alcance de los enums de la Fase A. Anotado para no duplicar.
- **Sin placeholders en A1 y A4** (código completo). A2 y A3 incluyen pasos `grep` de localización seguidos del código/transformación exacta porque la edición depende de líneas concretas de archivos no transcritos aquí; las cadenas finales (labels, traducciones) están dadas literalmente.
