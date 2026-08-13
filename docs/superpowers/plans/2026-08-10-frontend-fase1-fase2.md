# Frontend Fase 1 (P0) + Fase 2 (P1) — Plan de implementación

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Cerrar los hallazgos P0 y P1 de la auditoría frontend 2026-08-10 (validada por doble verificación código+navegador) en dos PRs: Fase 1 "verdad y confianza" y Fase 2 "un solo vocabulario".

**Architecture:** Fase 1 introduce el contrato `HasLabel` en los enums que llegan a la UI y cambia los controladores a mandar `{value,label}`; el resto son fixes puntuales verificados (KPI strip, copy interno, auditoría legible, controles muertos, seeder/listeners en español). Fase 2 unifica primitivas (Select del DS, `Field`, helpers de fecha), gates de estado (SLA/Tomar), empty states y el `isActive` del sidebar.

**Tech Stack:** Laravel 13 · PHPUnit 12 (NO Pest) · Inertia v3 + React 19 + TS · Tailwind v4 · tokens del DS en `resources/css/app.css`.

## Global Constraints

- Tests en PHPUnit 12 con factories; nunca `Model::create()` manual. Cada página Inertia modificada → feature test con `assertInertia`.
- Tras cada cambio PHP: `vendor/bin/pint --dirty --format agent`. Gates de cierre: `php artisan test --compact`, `npm run types:check && npm run lint:check && npm run format:check`, `npm run build`.
- Commits pequeños en español, SOLO identidad del usuario (sin `Co-Authored-By`, sin banners). Push a rama de PR, nunca a `main`. CI verde obligatorio antes de dar por cerrado (`gh pr checks --watch`).
- No tocar `composer.json`/`package.json`. No crear directorios nuevos a nivel `app/` (el contrato va en `app/Contracts/`, ya existente).
- Copy SIEMPRE en español, tuteo. Glosario cerrado: Activo (nunca Asset), Automatización (nunca Workflow en UI), Correo, SMS, WhatsApp, Voz.
- Tipografía: usar utilities del token system (`text-3xs..text-sm`, `sam-*`); nunca tamaños arbitrarios.
- El worktree ya tiene `vendor/` real y Wayfinder generado. Si una tarea cambia rutas/controladores: `php artisan wayfinder:generate --with-form`.

---

# PARTE A — PR Fase 1 (rama actual `claude/sam-frontend-audit-b9537d`)

### Task A1: Contrato `HasLabel` + `label()` en los 5 enums de UI

**Files:**
- Create: `app/Contracts/HasLabel.php`
- Modify: `app/Domains/Automation/Enums/ActionType.php`, `app/Domains/Automation/Enums/WorkflowTriggerType.php`, `app/Domains/Decisions/Enums/RuleScope.php`, `app/Domains/Notifications/Enums/ChannelType.php`, `app/Domains/TenantConfig/Enums/AutomationLevel.php`
- Test: `tests/Unit/Contracts/EnumLabelTest.php`

**Interfaces:**
- Produces: `App\Contracts\HasLabel { public function label(): string; }`; cada enum implementa `HasLabel`. Tareas A2/A3 dependen de `->label()`.

- [ ] **Step 1: Test que falla** — crear `tests/Unit/Contracts/EnumLabelTest.php`:

```php
<?php

namespace Tests\Unit\Contracts;

use App\Contracts\HasLabel;
use App\Domains\Automation\Enums\ActionType;
use App\Domains\Automation\Enums\WorkflowTriggerType;
use App\Domains\Decisions\Enums\RuleScope;
use App\Domains\Notifications\Enums\ChannelType;
use App\Domains\TenantConfig\Enums\AutomationLevel;
use PHPUnit\Framework\TestCase;

class EnumLabelTest extends TestCase
{
    public function test_ui_enums_implement_has_label_with_spanish_labels(): void
    {
        foreach ([ActionType::class, WorkflowTriggerType::class, RuleScope::class, ChannelType::class, AutomationLevel::class] as $enum) {
            $this->assertContains(HasLabel::class, class_implements($enum), $enum);
            foreach ($enum::cases() as $case) {
                $this->assertNotSame('', $case->label(), $enum.'::'.$case->name);
                $this->assertNotSame($case->value, mb_strtolower($case->label()), "{$enum}::{$case->name} devuelve el value crudo");
            }
        }

        $this->assertSame('Enviar WhatsApp', ActionType::SendWhatsapp->label());
        $this->assertSame('Incidente creado', WorkflowTriggerType::IncidentCreated->label());
        $this->assertSame('Tipo de evento', RuleScope::EventType->label());
        $this->assertSame('SMS', ChannelType::Sms->label());
        $this->assertSame('WhatsApp', ChannelType::Whatsapp->label());
        $this->assertSame('Voz', ChannelType::Voice->label());
        $this->assertSame('Semiautomático', AutomationLevel::SemiAutomatic->label());
    }
}
```

- [ ] **Step 2:** `php artisan test --compact --filter=EnumLabelTest` → FAIL (interface no existe).
- [ ] **Step 3: Implementación.** `app/Contracts/HasLabel.php`:

```php
<?php

namespace App\Contracts;

interface HasLabel
{
    public function label(): string;
}
```

Añadir `implements HasLabel` + `label()` con `match` (patrón idéntico a `AssetCategory::label()`):

| Enum | Traducciones exactas |
|---|---|
| `WorkflowTriggerType` | DecisionOutcome='Resultado de decisión', IncidentCreated='Incidente creado', IncidentEscalated='Incidente escalado', PriorityChanged='Cambio de prioridad', MediaArrived='Media recibida', ManualTrigger='Disparo manual' |
| `ActionType` | SendEmail='Enviar correo', SendWhatsapp='Enviar WhatsApp', SendSms='Enviar SMS', SendPush='Notificación push', CreateTicket='Crear ticket', AssignIncident='Asignar incidente', Escalate='Escalar', UpdateAssetState='Actualizar estado del activo', RequestHumanReview='Pedir revisión humana', CallWebhook='Llamar webhook' |
| `RuleScope` | Global='Global', Tenant='Tenant', EventType='Tipo de evento', Category='Categoría', AssetType='Tipo de activo', OperationProfile='Perfil de operación' |
| `ChannelType` | Email='Correo', Sms='SMS', Push='Push', Whatsapp='WhatsApp', Web='Web', Slack='Slack', Webhook='Webhook', Voice='Voz' |
| `AutomationLevel` | Conservative='Conservador', Assisted='Asistido', SemiAutomatic='Semiautomático', HighlyAutomated='Altamente automatizado' |

- [ ] **Step 4:** test PASS. **Step 5:** `vendor/bin/pint --dirty --format agent` y commit `feat(enums): contrato HasLabel y etiquetas en español para los enums de UI`.

### Task A2: Controladores mandan `{value,label}`

**Files:**
- Modify: `app/Http/Controllers/Automation/AutomationPageController.php:66-67`, `app/Http/Controllers/Decisions/RulesPageController.php:89`, `app/Http/Controllers/TenantConfig/TenantConfigPageController.php:102,204-207`, `app/Http/Controllers/Admin/GlobalChannelController.php:46`, `app/Http/Controllers/Settings/NotificationPreferencesController.php:56-62`
- Test: ampliar `tests/Feature/Domains/Automation/AutomationPageTest.php`, `tests/Feature/Domains/Decisions/RulesPageTest.php`, `tests/Feature/Domains/TenantConfig/TenantConfigPageTest.php` (+ el feature test existente de canales admin y preferencias si existen; si no, añadir asserts en los de arriba)

**Interfaces:**
- Produces: props Inertia `triggerTypes`/`actionTypes`/`scopes`/`channelTypes`/`automationLevels`/`channelOptions` pasan de `string[]` a `array{value:string,label:string}[]`. Task A3 consume ese shape.

- [ ] **Step 1: Tests que fallan.** En cada PageTest, asserts del nuevo shape (ejemplo Automation, replicar patrón en los demás):

```php
$response->assertInertia(fn (AssertableInertia $page) => $page
    ->component('automation/index')
    ->where('options.triggerTypes.0', ['value' => 'decision_outcome', 'label' => 'Resultado de decisión'])
    ->where('options.actionTypes.0', ['value' => 'send_email', 'label' => 'Enviar correo']));
```

(Comprobar la ruta real de la prop en cada test existente — `options.` puede variar.)

- [ ] **Step 2:** correr los 3 filtros → FAIL. **Step 3:** patrón único en los 5 controladores:

```php
'triggerTypes' => array_map(
    fn (WorkflowTriggerType $type) => ['value' => $type->value, 'label' => $type->label()],
    WorkflowTriggerType::cases(),
),
```

En `NotificationPreferencesController` sustituir `'label' => ucfirst($type->value)` por `'label' => $type->label()`.

- [ ] **Step 4:** tests PASS + suite del dominio. **Step 5:** pint + commit `feat(web): los selects de enums reciben value y label en español`.

### Task A3: Frontend consume `{value,label}` y elimina `trigger: `

**Files:**
- Modify: `resources/js/pages/automation/index.tsx:315-375` (3 selects + array inline de destinos L371), `resources/js/pages/rules/index.tsx:574-576`, `resources/js/pages/settings/tenant-config.tsx:582,641-642,1776-1778`, `resources/js/pages/admin/channels/index.tsx`, `resources/js/pages/settings/notifications.tsx:112`

- [ ] **Step 1:** Actualizar tipos TS de las props (`string[]` → `{ value: string; label: string }[]`) y los `<option>`:

```tsx
{options.triggerTypes.map((t) => (
    <option key={t.value} value={t.value}>
        {t.label}
    </option>
))}
```

Eliminar el prefijo literal `trigger: ` de automation/index.tsx:325. Para el array inline de destinos (L371), constante local:

```tsx
const TARGET_TYPES = [
    { value: 'role', label: 'Rol' },
    { value: 'user', label: 'Usuario' },
    { value: 'email', label: 'Correo' },
    { value: 'phone', label: 'Teléfono' },
    { value: 'url', label: 'URL' },
] as const;
```

- [ ] **Step 2:** `npm run types:check && npm run lint:check && npm run format:check` verdes; `npm run build` OK.
- [ ] **Step 3:** Verificación visual (preview): el select de disparador muestra "Incidente creado", canales muestran "WhatsApp"/"SMS"/"Voz".
- [ ] **Step 4:** commit `feat(ui): selects de automatizaciones, reglas y ajustes muestran etiquetas en español`.

### Task A4: Panel muestra nombre del evento, no código

**Files:**
- Modify: `app/Http/Controllers/Dashboard/DashboardController.php:290`
- Test: feature test del dashboard existente (buscar `tests/Feature/**/Dashboard*`; si no asserta el stream, añadir caso)

- [ ] **Step 1: Test que falla:** crear evento normalizado con factory cuyo `EventType` tenga `code='device_offline'`, `name='Dispositivo sin conexión'`; assert `->where('stream.0.type', 'Dispositivo sin conexión')` (verificar nombre real de la prop en el controlador).
- [ ] **Step 2:** FAIL. **Step 3:** `'type' => (string) ($event->eventType?->name ?? $event->eventType?->code ?? '—'),`
- [ ] **Step 4:** PASS. **Step 5:** pint + commit `fix(dashboard): el stream en vivo muestra el nombre del tipo de evento`.

### Task A5: KPIs del panel con `KpiStrip`/`Kpi` del DS

**Files:**
- Modify: `resources/js/pages/dashboard.tsx` (borrar `KpiCard` L212-249 y `KpiGrid` L293-352; render L92), `resources/js/components/sam/kpi-strip.tsx` (añadir prop opcional `sub`)

**Interfaces:**
- Consumes: `KpiStrip {children, cols=4}`, `Kpi {label, value: ReactNode, unit?, delta?: DeltaProps, sparkline?: ReactNode}` (kpi-strip.tsx:56-116).
- Produces: `Kpi` gana `sub?: ReactNode` (texto pequeño bajo el valor, para "SLA promedio: 02:30" y "sin comparativa previa").

- [ ] **Step 1:** En `kpi-strip.tsx` añadir a `KpiProps` `/** Texto secundario bajo el valor (p. ej. "SLA promedio: 02:30"). */ sub?: ReactNode;` y renderizarlo tras el value: `{sub && <div className="text-2xs text-fg-3">{sub}</div>}`.
- [ ] **Step 2:** En `dashboard.tsx`: importar `Kpi, KpiStrip` desde `@/components/sam`; sustituir `<KpiGrid kpis={kpis} />` por:

```tsx
<KpiStrip className="shrink-0">
    <Kpi
        label="Incidentes abiertos"
        value={String(kpis.openIncidents.value)}
        delta={kpis.openIncidents.deltaPct !== null ? { value: kpis.openIncidents.deltaPct } : undefined}
        sub={kpis.openIncidents.deltaPct === null ? 'sin datos de ayer' : undefined}
        sparkline={<Spark series={kpis.openIncidents.series} />}
    />
    <Kpi
        label="Críticos ahora"
        value={String(kpis.criticalOpen.value)}
        sub={`SLA promedio: ${formatSlaClock(kpis.criticalOpen.avgSlaRemainingSeconds)}`}
        sparkline={<Spark series={kpis.criticalOpen.series} />}
    />
    <Kpi
        label="SLA cumplido · 7 d"
        value={formatPercent(kpis.slaCompliance.value)}
        delta={kpis.slaCompliance.deltaPp !== null ? { value: kpis.slaCompliance.deltaPp, unit: 'pp' } : undefined}
        sub={kpis.slaCompliance.deltaPp === null ? 'sin comparativa previa' : undefined}
    />
    <Kpi
        label="Precisión IA · 7 d"
        value={formatPercent(kpis.aiPrecision.value)}
        delta={kpis.aiPrecision.deltaPp !== null ? { value: kpis.aiPrecision.deltaPp, unit: 'pp' } : undefined}
        sub={kpis.aiPrecision.deltaPp === null ? 'sin comparativa previa' : undefined}
    />
</KpiStrip>
```

`Spark` = extraer el JSX de sparkline que hoy vive dentro del `KpiCard` local a un componente local de 10 líneas (misma implementación, solo movida). Borrar `KpiCard`/`KpiGrid` y `formatDeltaPp` si queda sin uso.

- [ ] **Step 3:** gates front + build. **Step 4: Verificación visual en preview:** la franja mide >100px y se ven las 4 celdas (antes: 2px). Medir con JS si hay dudas.
- [ ] **Step 5:** commit `fix(dashboard): franja de KPIs visible usando KpiStrip del design system`.

### Task A6: Purga de identificadores internos del copy (B9/P5/P6/keys)

**Files:**
- Modify: `resources/js/pages/settings/tenant-config.tsx` líneas 420, 428, 447-449, 479, 485, 989-990, 1406, 1496, 1835

- [ ] **Step 1:** Reemplazos exactos (la clave técnica pasa a `<span className="block font-mono text-3xs text-fg-3">{KEY}</span>` como en la tabla de Otros ajustes, o desaparece):
  - L420: `{MEDIA_AUTO_REQUEST_KEY}: consume cuota…` → `Consume cuota de retrievals del proveedor (apagado por defecto).` + span mono con la key.
  - L428: `Resolución externa de pánico ({PANIC_AUTO_CLOSE_KEY})` → `Resolución externa de pánico` + span mono con la key bajo el control.
  - L447-449: `GPS fresco: umbral de obsolescencia en segundos ({LIVE_LOCATION_KEY})` → `GPS fresco: umbral de obsolescencia en segundos` + span mono.
  - L479: `Otros settings ({otherSettings.length})` → `Otros ajustes ({otherSettings.length})`; L485: `Sin settings adicionales.` → `Sin ajustes adicionales.`
  - L989-990: `Sin configuración de escalación: el SLA (P6) no escala por niveles hasta definir los steps.` → `Sin configuración de escalación: el SLA no escala por niveles hasta que definas los pasos.`
  - L1406: `Sin perfiles de horario: la asignación on-call (P5) usa el fallback (primer admin del equipo).` → `Sin perfiles de horario: la asignación on-call usa el respaldo (primer admin del equipo).`
  - L1496: `label="Shift rules (turnos on-call que usa P5)"` → `label="Reglas de turno (JSON)"`.
  - L1835: `…o FCM para que las notificaciones y B9 operen.` → `…o FCM para que las notificaciones y las llamadas de verificación operen.`
- [ ] **Step 2:** `rg -n "B9|\bP5\b|\bP6\b|Shift rules" resources/js/pages/settings/tenant-config.tsx` → 0 hits visibles. Gates front.
- [ ] **Step 3:** commit `fix(settings): copy sin identificadores internos ni claves técnicas en labels`.

### Task A7: Auditoría legible (entityLabel + adiós columna RESUMEN)

**Files:**
- Modify: `app/Http/Controllers/Audit/AuditPageController.php:78-87`, `resources/js/pages/audit/index.tsx:114-135`
- Test: feature test de la página de auditoría (buscar `tests/Feature/**/Audit*Page*`; ampliar)

**Interfaces:**
- Produces: prop `logs[].entityLabel: string|null` (reemplaza el uso visual de `entityType`; `entityType` puede seguir viajando).

- [ ] **Step 1: Test que falla:** crear `AuditLog` por factory con `entity_type = 'App\\Domains\\Normalization\\Models\\NormalizedEvent'` y assert `->where('logs.0.entityLabel', 'Evento normalizado')`; otro con `entity_type='incident'` → `'Incidente'`.
- [ ] **Step 2:** FAIL. **Step 3:** en el controlador, método privado:

```php
private const ENTITY_LABELS = [
    'NormalizedEvent' => 'Evento normalizado',
    'RawEvent' => 'Evento crudo',
    'AIEventEvaluation' => 'Evaluación IA',
    'EventContextSnapshot' => 'Contexto de evento',
    'UsageRecorded' => 'Uso registrado',
    'Incident' => 'Incidente',
    'Decision' => 'Decisión',
    'User' => 'Usuario',
    'Team' => 'Tenant',
    'Subscription' => 'Suscripción',
    'NotificationChannel' => 'Canal de notificación',
    'InvoiceSnapshot' => 'Factura',
    'incident' => 'Incidente',
    'notification_channel' => 'Canal de notificación',
    'invoice_snapshot' => 'Factura',
];

private function entityLabel(?string $type): ?string
{
    if ($type === null) {
        return null;
    }

    $base = class_basename($type);

    return self::ENTITY_LABELS[$base] ?? self::ENTITY_LABELS[$type] ?? Str::headline($base);
}
```

y en el map: `'entityLabel' => $this->entityLabel($log->entity_type),`.

- [ ] **Step 4:** PASS + pint. **Step 5:** en `audit/index.tsx`: la columna Entidad renderiza `{log.entityLabel ?? '—'}{log.entityId ? ` #${log.entityId}` : ''}` (sin font-mono), y **eliminar la columna `Resumen`** (L126-135) y su header. Gates front.
- [ ] **Step 6:** commit `fix(audit): entidades con nombre legible y sin columna resumen duplicada`.

### Task A8: Topbar honesto (campana, Conectado, rol)

**Files:**
- Modify: `resources/js/components/sam/ops-topbar.tsx:32-38,116-133,167-170`
- Verify: `resources/js/components/sam/realtime-status.tsx` (union `RealtimeState`), `app/Http/Middleware/HandleInertiaRequests.php` (`navBadges()` — ver si expone contador de notificaciones), `app/Models/User.php::toUserTeam` (si `currentTeam.role` viaja)

- [ ] **Step 1: Conectado real.** Eliminar `useState`/`toggleRealtime` (L32-38). Importar `useRealtimeConnection` de `@/hooks/use-realtime-connection` y mapear a la union real de `RealtimeState` (leerla primero en realtime-status.tsx):

```tsx
const connection = useRealtimeConnection();
const realtimeState: RealtimeState =
    connection === 'connected' ? 'ok' : connection === 'connecting' || connection === 'reconnecting' ? 'degraded' : 'down';
```

(si la union no tiene `degraded`, usar el estado intermedio que exista o `down`). El wrapper deja de ser `<button>` → `<div>` con `title` descriptivo; sin onClick.

- [ ] **Step 2: Campana.** Convertir a `<Link href={`/${teamSlug}/notifications`}>` (obtener `teamSlug` de `page.props.currentTeam?.slug`; si es null, ocultar campana). Punto rojo condicionado: `{(page.props.navBadges?.notifications ?? 0) > 0 && (<span …/>)}` — leer `HandleInertiaRequests::navBadges()` para el nombre real de la clave; si no existe contador de notificaciones, eliminar el punto.
- [ ] **Step 3: Rol real.** Leer `User::toUserTeam()`; si `currentTeam.role` existe (string TeamRole), mapa local `{ owner: 'Propietario', admin: 'Administrador', member: 'Miembro' }` (alinear con los cases reales de `App\Enums\TeamRole`); si no viaja el rol, eliminar la línea "Supervisor".
- [ ] **Step 4:** gates front + build + verificación en preview (clic en campana navega a la bandeja; "Conectado" ya no togglea).
- [ ] **Step 5:** commit `fix(topbar): campana navegable, estado realtime real y rol del usuario en vez de placeholder`.

### Task A9: Retirar controles muertos del detalle de incidente

**Files:**
- Modify: `resources/js/components/sam/incident-detail/detail-header.tsx:101-107,159-162`, `resources/js/components/sam/incident-detail/detail-center.tsx:488-492`

- [ ] **Step 1:** Borrar el `<button aria-label="Ver en proveedor">` (detail-header L101-107) y el `<Button variant="outline"><ExternalLink/> Ver en {incident.provider}</Button>` (L159-162): no existe `externalUrl` en los tipos ni en backend; retirar es lo honesto (re-añadir cuando el dato exista).
- [ ] **Step 2:** En detail-center L488: cambiar `<button type="button">` de las tarjetas de evidencia por `<div>` (mismas clases sin `hover:border-border-strong` ni cursor-pointer), conservando el layout.
- [ ] **Step 3:** gates front + build. **Step 4:** commit `fix(incidentes): retirar controles sin acción del detalle (ver en proveedor, tarjetas de evidencia)`.

### Task A10: Seeder y listeners de notificaciones en español

**Files:**
- Modify: `database/seeders/DemoSeeder.php:140,146-149,492-499,547-551`, `app/Domains/Notifications/Listeners/NotifyOnIncidentStatusChanged.php:41-42`, `app/Domains/Notifications/Listeners/NotifyOnIncidentCreated.php:51`
- Test: los feature tests existentes de Notifications que asserten subjects (ajustar expectativas); si ninguno lo hace, añadir un caso en el test del listener

- [ ] **Step 1:** Traducciones exactas del seeder:
  - Severidades L146-149: `Low→'Baja'`, `Medium→'Media'`, `High→'Alta'`, `Critical→'Crítica'` (solo `label`; `code` intacto).
  - Títulos L492-499 (en orden): `'Botón de pánico activado cerca del centro'`, `'Colisión detectada en la Carretera 15'`, `'Alerta de fatiga del conductor (3.ª esta semana)'`, `'Cámara obstruida en la Van V-203'`, `'Vehículo fuera de la ruta planificada'`, `'Activo salió de zona autorizada'`, `'Parada larga no programada investigada'`, `'Falso positivo de botón de pánico (capacitación)'`.
  - Notificaciones L547-551 (subject / preview): `'Crítico: botón de pánico activado'` / `'Botón de pánico activado en el Truck T-101 cerca del centro.'`; `'Alerta de fatiga del conductor'` / `'Carlos Hernández marcado por fatiga en la hora 9 del turno.'`; `'Incidente #3 pasó a En revisión'` / `'El caso de fatiga del conductor está en revisión.'`; `'Automatización ejecutada: Notificar al supervisor'` / `'Automatización completada para el incidente #1.'`; `'Salida de geocerca'` / `'La Van V-204 salió del perímetro del almacén.'`
  - L140: `Str::headline($code).' events'` → `'Eventos de '.Str::headline($code)`.
- [ ] **Step 2:** Listeners (runtime, más importante que el seeder):
  - `NotifyOnIncidentCreated.php:51`: `'New incident created'` → `'Nuevo incidente creado'`.
  - `NotifyOnIncidentStatusChanged.php:41-42`: subject `'Estado del incidente actualizado'`; body con label español del status — buscar `IncidentStatusPresenter` (fuente de verdad de labels según status-pill.tsx) y usar su label; fallback al status crudo:

```php
subject: 'Estado del incidente actualizado',
bodyPreview: "El incidente #{$incident->id} pasó a ".IncidentStatusPresenter::label($newStatus).'.',
```

(verificar la firma real del presenter; si es método de instancia o array const, adaptar la llamada).

- [ ] **Step 3:** `php artisan test --compact tests/Feature/Domains/Notifications` (ajustar asserts de subjects si los hay) → verde.
- [ ] **Step 4:** pint + commit `fix(notificaciones): seeder demo y listeners con copy en español`.

### Task A11: Gates de cierre + PR Fase 1

- [ ] `vendor/bin/pint --test` limpio; `php artisan test --compact` verde completo.
- [ ] `npm run types:check && npm run lint:check && npm run format:check`; `npm run build`.
- [ ] Verificación visual en preview de: panel (KPIs visibles + nombres de evento), automatizaciones (labels español), ajustes (sin B9/P5/P6), auditoría (entidades legibles), topbar.
- [ ] `git push -u origin claude/sam-frontend-audit-b9537d` + `gh pr create` (título: `Fase 1 auditoría frontend: verdad y confianza (P0)`; cuerpo con resumen por hallazgo). `gh pr checks --watch` hasta verde. NO mergear sin autorización.

---

# PARTE B — PR Fase 2 (rama nueva `claude/frontend-fase2-vocabulario` desde la rama de Fase 1)

### Task B1: Un solo Select (el del DS) para los 20 nativos

**Files:**
- Modify: `resources/js/pages/rules/index.tsx` (6), `resources/js/pages/automation/index.tsx` (3), `resources/js/pages/settings/tenant-config.tsx` (3), `resources/js/components/sam/incident-detail/detail-center.tsx` (3), `resources/js/pages/audit/index.tsx` (2), `resources/js/pages/events/index.tsx` (1), `resources/js/pages/admin/channels/index.tsx` (1), `resources/js/components/sam/incident-detail/detail-side.tsx` (1)

- [ ] **Step 1:** Conversión canónica (repetir por cada `<select>`; el trigger SIEMPRE `className="h-9"` → 36px):

```tsx
import { Select, SelectContent, SelectItem, SelectTrigger, SelectValue } from '@/components/ui/select';

<Select value={form.trigger_type} onValueChange={(v) => setForm({ ...form, trigger_type: v })}>
    <SelectTrigger className="h-9 w-full">
        <SelectValue placeholder="Disparador" />
    </SelectTrigger>
    <SelectContent>
        {options.triggerTypes.map((t) => (
            <SelectItem key={t.value} value={t.value}>
                {t.label}
            </SelectItem>
        ))}
    </SelectContent>
</Select>
```

- [ ] **Step 2:** `rg -c '<select' resources/js --glob '*.tsx'` → 0. Gates front + build.
- [ ] **Step 3:** Verificación visual: formulario de automatizaciones con una sola altura de control (36px).
- [ ] **Step 4:** commit `refactor(ui): un solo componente Select del DS en toda la app`.

### Task B2: Etiquetas accesibles con `Field` en Automatizaciones, Reglas y Ajustes

**Files:**
- Modify: `resources/js/pages/automation/index.tsx` (formulario Nuevo workflow L281-450), `resources/js/pages/rules/index.tsx` (formularios), `resources/js/pages/settings/tenant-config.tsx` (los 6 `<label>` nativos sin htmlFor), `resources/js/pages/settings/notifications.tsx` (2 labels)

**Interfaces:**
- Consumes: `Field {label, help?, htmlFor, children}` de `resources/js/components/sam/field.tsx` (ya existe).

- [ ] **Step 1:** En el formulario de automatizaciones, envolver cada control en `Field` con `htmlFor` + `id` (labels: Código, Nombre, Disparador, Acción, Tipo de destino, Destino, Retraso en segundos). Controles compactos en fila (pasos) que no admiten `Field`: `aria-label` en español directamente en el control.
- [ ] **Step 2:** Reglas y tenant-config: cada `<label>` nativo gana `htmlFor` apuntando al `id` del control (añadir `id` si falta). notifications.tsx igual.
- [ ] **Step 3:** Verificación: script del navegador contando controles sin nombre accesible en esas 4 páginas → 0. Gates front.
- [ ] **Step 4:** commit `fix(a11y): todos los controles de formularios con etiqueta accesible`.

### Task B3: Un solo sistema de fechas

**Files:**
- Modify: `resources/js/pages/events/index.tsx:86`, `resources/js/pages/events/show.tsx:190`, `resources/js/pages/audit/index.tsx:80,147`, `resources/js/pages/integrations/index.tsx:89-94`, `resources/js/pages/drivers/show.tsx:63-67`, `resources/js/components/sam/assets/asset-signal.tsx:11-15`, `resources/js/components/sam/incident-detail/detail-header.tsx:140-142`, `resources/js/components/sam/inbox/incident-row.tsx:73-88`

- [ ] **Step 1:** Sustituir cada `toLocaleString('es'…)`/helper local por `formatDateTime`/`formatDate` de `@/lib/format` (eliminando los helpers locales duplicados de drivers/show y asset-signal).
- [ ] **Step 2:** `detail-header.tsx:140`: `Creado hace {incident.ageMin} min` → `<span className="text-fg-3">Creado <RelativeTime minutes={incident.ageMin} className="text-xs text-fg-3" /></span>`.
- [ ] **Step 3:** `incident-row.tsx`: borrar `RelativeTimeCell` local (L73-88) y usar `RelativeTime` de `@/components/sam`.
- [ ] **Step 4:** `rg -n "toLocaleString\(|toLocaleDateString\(" resources/js --glob '*.tsx'` → solo dentro de `lib/format.ts`. Gates front + build.
- [ ] **Step 5:** commit `refactor(ui): fechas unificadas con lib/format y RelativeTime`.

### Task B4: Glosario cerrado (Activo / Automatización)

**Files:**
- Modify: `resources/js/components/sam/drivers/drivers-table.tsx:79`, `resources/js/pages/drivers/show.tsx:378`, `resources/js/pages/automation/index.tsx:87,649,676`

- [ ] **Step 1:** `'Asset asignado'` → `'Activo asignado'`; `<th>Asset</th>` → `<th>Activo</th>`; tab `label: 'Workflows'` → `'Automatizaciones'`; `'Nuevo workflow'` (×2) → `'Nueva automatización'`. Revisar copy circundante del empty state de automation ("Un workflow reacciona solo…" → "Una automatización reacciona sola…").
- [ ] **Step 2:** `rg -in "\basset\b|workflow" resources/js/pages resources/js/components/sam --glob '*.tsx'` → sin hits en strings visibles al usuario (identifiers de código pueden quedarse). Gates front.
- [ ] **Step 3:** commit `fix(ui): glosario cerrado — Activo y Automatización en todo el copy`.

### Task B5: SLA y "Tomar" ocultos en incidentes cerrados

**Files:**
- Modify: `resources/js/components/sam/inbox/incident-row.tsx` (LiveSlaCell L39-68, ClaimControl L108-160 y sus call sites), `resources/js/components/sam/incident-detail/detail-header.tsx` (BigSlaDisplay)

**Interfaces:**
- Consumes: `IncidentStatus` de `@/components/sam` (`'resolved' | 'closed' | 'discarded'` son los terminales).

- [ ] **Step 1:** Constante compartida en `resources/js/components/sam/status-pill.tsx` (junto al tipo): `export const TERMINAL_STATUSES: IncidentStatus[] = ['resolved', 'closed', 'discarded'];`
- [ ] **Step 2:** `LiveSlaCell` recibe `status: IncidentStatus`; primera línea: `if (TERMINAL_STATUSES.includes(status)) return <span className="font-mono text-xs text-fg-3">—</span>;`. Call site pasa `incident.status`.
- [ ] **Step 3:** `ClaimControl`: `if (TERMINAL_STATUSES.includes(incident.status)) return incident.claimedBy ? <ClaimedBadge …/> : null;` (mostrar quién lo tomó si aplica; nunca el botón "Tomar").
- [ ] **Step 4:** `BigSlaDisplay` en detail-header: mismo gate → renderiza el `StatusPill` grande o nada, nunca "VENCIDO" en rojo sobre cerrados.
- [ ] **Step 5:** gates front + verificación visual: pestaña "Todos" sin VENCIDO ni Tomar en filas Resuelto/Cerrado/Descartado.
- [ ] **Step 6:** commit `fix(incidentes): SLA y toma ocultos en incidentes terminales`.

### Task B6: EmptyState en todas las listas (y fix de guarda en la bandeja)

**Files:**
- Modify: `resources/js/pages/incidents/index.tsx:1143-1160`, `resources/js/pages/rules/index.tsx:691-694,1015`, `resources/js/pages/settings/tenant-config.tsx:485` y bloque Canales L~1830, `resources/js/pages/billing/index.tsx:511-516`

- [ ] **Step 1: Bandeja.** Dentro de la rama `hasIncidents`, si `rows.length === 0` renderizar en lugar de la tabla:

```tsx
<EmptyState
    className="min-h-0 flex-1"
    icon={Inbox}
    title="Nada en esta pestaña"
    description="No hay incidentes que coincidan con la pestaña o los filtros activos. Cambia de pestaña o limpia los filtros."
/>
```

- [ ] **Step 2: Reglas.** Sustituir los dos `<p>Sin reglas…</p>` por `EmptyState` (icono `Scale` o similar de lucide, título `"Todavía no hay reglas de decisión"` / `"Todavía no hay reglas de mapeo"`, description de una frase explicando cómo se crean, action con el botón de crear si `canManage`).
- [ ] **Step 3: Canales y Otros ajustes** (tenant-config): mismo patrón `EmptyState` compacto.
- [ ] **Step 4: Facturación.** El párrafo `"…subes el comprobante arriba…"` (L511-516) se renderiza solo si `invoices.length > 0`; si no, EmptyState: título `"Todavía no hay facturas"`, description `"Cuando SAM emita tu primera factura aparecerá aquí con su botón para subir el comprobante de transferencia."` (cierra el hallazgo P1-12 real).
- [ ] **Step 5:** gates front + build + verificación visual (pestaña Abiertos de la bandeja ya no muestra tabla decapitada).
- [ ] **Step 6:** commit `fix(ui): empty states explicativos en bandeja, reglas, canales y facturación`.

### Task B7: `isActive` del sidebar por segmento más largo

**Files:**
- Modify: `resources/js/components/sam/ops-sidebar.tsx:252-258` (+ recolección de hrefs de los arrays de nav del mismo archivo)

- [ ] **Step 1:** Sustituir por matching de mejor candidato:

```tsx
const path = currentUrl.split('?')[0];
const allHrefs = navGroups.flatMap((g) => g.items.map((i) => i.href)); // adaptar al nombre real del array de nav
const activeHref = allHrefs
    .filter((h) => h !== '#' && (path === h || path.startsWith(h + '/')))
    .sort((a, b) => b.length - a.length)[0];
const isActive = (href: string) => href === activeHref;
```

- [ ] **Step 2:** gates front + verificación visual: en `/sam-demo/assets/map` solo "Mapa en vivo" activo; en `/sam-demo/assets` solo "Flota".
- [ ] **Step 3:** commit `fix(sidebar): un solo ítem activo (coincidencia por segmento más largo)`.

### Task B8: Gates de cierre + PR Fase 2

- [ ] `vendor/bin/pint --test` limpio; `php artisan test --compact` verde; gates front; `npm run build`.
- [ ] Recorrido visual completo en preview (bandeja, detalle, automatizaciones, reglas, ajustes, eventos, auditoría, facturación, sidebar).
- [ ] Push + `gh pr create` (título `Fase 2 auditoría frontend: un solo vocabulario (P1)`, base = rama Fase 1 o main según estado del PR 1) + `gh pr checks --watch`. NO mergear sin autorización.
