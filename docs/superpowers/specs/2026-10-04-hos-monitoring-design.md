# Monitoreo HOS (EE. UU.) con recordatorios insistentes al chofer — diseño

**Fecha:** 2026-10-04 · **Estado:** aprobado en conversación, pendiente de revisión escrita

## 1. Propósito

Un cliente con flota que cruza a EE. UU. pide que SAM vigile las **Hours of Service** (reglas FMCSA) de
Samsara y **le insista al chofer** para que:

- **pare a descansar** antes de violar una regla (break de 30 min, 11 h de manejo, ventana de 14 h, ciclo
  70 h / 8 días), y
- **retome** cuando su pausa ya se cumplió.

Sólo aplica a la parte de la flota que opera en EE. UU. Hoy esa parte se identifica en Samsara con el tag
`USA`, pero el módulo no debe depender de ese nombre: se inscribe por **tags** y/o por **selección manual
de unidades**.

**Éxito:** un chofer inscrito recibe el aviso antes del límite, recibe insistencia escalonada hasta que
corrige, y si no corrige (o Samsara ya reporta violación) el equipo de monitoreo recibe un incidente con
contexto completo. Cero mensajes duplicados, cero ruido en la bandeja para lo que se corrige solo.

### Decisiones tomadas con el usuario

| Tema | Decisión |
|---|---|
| Canal al chofer | App de Samsara primero (gratis) → WhatsApp/SMS → llamada con la voz de SAM → incidente al monitorista |
| Disparadores | Break 30 min · manejo 11 h / turno 14 h · ciclo 70 h · fin de pausa (retomar) — los cuatro |
| Cobro | **Incluido en el tracto-día**: sólo se vigilan choferes que van en un tracto `monitored`. Sin medidor nuevo. Twilio se sigue cobrando como hoy (costo + 30 %) |
| Bandeja | Recordatorios = bitácora HOS (no abren incidente). Incidente sólo si la escalera al chofer se agota o Samsara reporta violación |
| Inscripción | Enfoque A: reglas dinámicas tags + incluidos manuales − excluidos |
| Llamadas con el tracto en movimiento | **Sí se envían** (decisión del cliente: los tractos traen soporte de celular). Se documenta como decisión del tenant |

## 2. Lo que dice la API de Samsara (verificado 2026-10-04 contra la integración real, team 5)

- `GET /fleet/hos/clocks` (scope **Read ELD Compliance Settings (US)** — el token actual lo tiene). Una fila
  **por chofer**: `currentDutyStatus.hosStatusType` (`driving|onDuty|offDuty|sleeperBed|yardMove|personalConveyance`,
  o `""` si la app del chofer está desconectada), `currentVehicle` (sólo si tiene tracto asignado),
  `clocks.break.timeUntilBreakDurationMs`, `clocks.drive.driveRemainingDurationMs`,
  `clocks.shift.shiftRemainingDurationMs`, `clocks.cycle.{cycleRemainingDurationMs,cycleStartedAtTime,cycleTomorrowDurationMs}`,
  `violations.{shiftDrivingViolationDurationMs,cycleViolationDurationMs}`. Paginado (máx 512), filtros
  `tagIds`, `parentTagIds`, `driverIds`.
- `tagIds` filtra por **tags de conductor**: `USA` → 49 choferes con clocks; `TRACTOS USA` (tag sólo de
  vehículos) → 0. Los tags de vehículo se resuelven por `currentVehicle`.
- Valores en reposo: break 8 h (28 800 000 ms), manejo 11 h, turno 14 h, ciclo 70 h. Que el break vuelva a
  8 h o manejo/turno vuelvan a 11/14 h es la señal de "pausa cumplida".
- En el momento de la prueba: 56 choferes con clocks (3 `driving`, 8 `sleeperBed`), 13 con tracto; un chofer
  manejando a **26 min** de su break obligatorio (el caso de uso exacto). Los 251 choferes tienen ruleset
  `USA 70 hour / 8 day · US Interstate Property`; 42 de 43 choferes `USA` tienen teléfono.
- `GET /tags` lista tags con sus vehículos y conductores (con `parentTagId`).
- `POST /v1/fleet/messages` (`{driverIds, text≤2500}`, API legacy) manda a la app del chofer. Requiere el
  scope **Write Messages**: **no verificado** (leer mensajes sí funciona; los despachadores del cliente ya
  usan este canal). Se valida en el PR 2 con un chofer de prueba y permiso explícito.
- No hay webhook de cambio de estado HOS: se sondea.

## 3. Arquitectura

### 3.1 Ubicación

Submódulo `Hos` dentro del dominio existente **`Drivers`** (`app/Domains/Drivers/{Actions,Jobs,Models,Support,Enums}/Hos*`).
No se crea dominio ni directorio nuevo bajo `app/`. Notificaciones e Incidentes se extienden en sus propios
dominios.

### 3.2 Activación y configuración

- **Feature por tenant** `hos_monitoring` (`TenantFeature`, la activa el super-admin). Sin ella: no se
  sondea, no se muestra la sección, los endpoints responden 403 vía `AuthorizeAction`.
- **Config del tenant** en `TenantSetting` (grupo `compliance`), clave `hos.monitoring`:

```json
{
  "tag_ids": ["4738197", "7076291"],
  "included_asset_ids": [123],
  "excluded_asset_ids": [456],
  "situations": { "break_due": true, "drive_limit": true, "shift_limit": true, "cycle_limit": true, "rest_complete": true },
  "lead_minutes": [30, 15, 0],
  "cycle_lead_hours": [5, 1],
  "rest_complete_nudge_minutes": [15, 30],
  "ladder": [
    { "after_minutes": 0,  "channels": ["samsara_driver_app"] },
    { "after_minutes": 5,  "channels": ["samsara_driver_app", "whatsapp"] },
    { "after_minutes": 10, "channels": ["voice"] },
    { "after_minutes": 15, "escalate": "incident" }
  ]
}
```

Los valores de arriba son los defaults (`config/hos.php`), validados al guardar (umbrales > 0, escalones
crecientes, mínimo 2 min entre escalones como en `EscalationLadder`).

### 3.3 Tags en el sync existente

`SamsaraAdapter::mapVehicle()` y `mapDriver()` guardan `metadata.tags = [{id, name}]` (hoy el chofer sólo
guarda nombres; el vehículo nada). Se sigue usando `metadata_json` (precedente: `offline_alert_minutes`), sin
tabla de tags. El selector de la UI lee `GET /tags` en vivo vía un método nuevo del adapter
(`fetchTags()`), cacheado 10 min por tenant (`hos:tags:{teamId}`).

### 3.4 Conjunto efectivo (enfoque A)

`ResolveHosEnrollment` (por tenant, en cada ciclo) decide si una fila de clocks se vigila:

```
vigilado(chofer) =
    chofer.currentVehicle → Asset del tenant (por AssetExternalReference)
    ∧ asset.monitoring_state = monitored
    ∧ asset.id ∉ excluded_asset_ids
    ∧ ( asset.id ∈ included_asset_ids
        ∨ asset.metadata.tags ∩ tag_ids ≠ ∅
        ∨ driver.metadata.tags ∩ tag_ids ≠ ∅ )
```

Sin tracto asignado → no se vigila (consistente con "incluido en el tracto-día"; el chofer en su casa no
recibe nada). Se loguea cuántos quedaron fuera y por qué (`not_monitored`, `no_vehicle`, `excluded`,
`no_match`).

### 3.5 Sondeo

- `PollHosClocksJob` (orquestador) cada **60 s** en `routes/console.php`, `->onOneServer()`, cola
  `telematics`. Corre en `TenantContext::withoutTenant()` sobre `TenantIntegration::ofLiveTeam()` activas de
  Samsara cuyo team tenga `hos_monitoring`; respeta `TenantCanSend::blockedReason()` (suspendidos).
- Por integración despacha `SyncHosClocksJob` (`ShouldBeUnique` por integración, cola `telematics`) que corre
  dentro de `TenantContext::for($teamId)`.
- `ProviderAdapter::fetchHosClocks(TenantIntegration): HosClockReading[]` — método nuevo en contrato,
  manager y adapter. Pagina hasta agotar `hasNextPage`. 401/403 → `ProviderUnauthorized` (se loguea
  degradado y no se reintenta hasta el siguiente minuto); 429/5xx → `ProviderRequestFailedException`.
- `HosClockReading` es un DTO inmutable (ms → segundos, status como enum `HosDutyStatus`, `null` si `""`).

### 3.6 Estado por chofer: `hos_driver_states`

Una fila por (team, driver) vigilado:

| Columna | Uso |
|---|---|
| `team_id` (FK, índice), `driver_id` (FK, único con team) | scope de tenant |
| `asset_id` (nullable) | tracto en el último sondeo |
| `duty_status`, `status_since` | estado actual y desde cuándo SAM lo ve |
| `break_remaining_s`, `drive_remaining_s`, `shift_remaining_s`, `cycle_remaining_s`, `violation_s` | último snapshot |
| `observed_at` | último sondeo |
| `app_disconnected_since` (nullable) | status vacío |

Modelo `HosDriverState` con `BelongsToTenant`.

### 3.7 Episodios: `hos_episodes`

Cada situación detectada es un episodio con ciclo de vida propio:

| Columna | Uso |
|---|---|
| `team_id`, `driver_id`, `asset_id` | scope y contexto |
| `situation` (`HosSituation`: `break_due`, `drive_limit`, `shift_limit`, `cycle_limit`, `rest_complete`, `violation`) | tipo |
| `opened_at`, `resolved_at`, `resolution` (`corrected`, `expired`, `incident`, `unenrolled`) | ciclo |
| `ladder_step`, `next_nudge_at` | escalera |
| `incident_id` (nullable) | si escaló |
| `snapshot_json` | clocks al abrir (para el incidente y el panel) |

Índice parcial único: un episodio **abierto** por (team, driver, situation).

### 3.8 Detección (`DetectHosSituations`, pura y testeable)

Entrada: estado previo + lectura nueva + config. Salida: episodios a abrir y episodios a resolver.

| Situación | Abre | Se resuelve (`corrected`) |
|---|---|---|
| `break_due` | `driving` ∧ break ≤ primer `lead_minutes` | break vuelve a 8 h, o deja `driving` (se pausa la insistencia; si vuelve a manejar sin break, reanuda) |
| `drive_limit` | `driving` ∧ manejo ≤ primer `lead_minutes` | `offDuty`/`sleeperBed` |
| `shift_limit` | (`driving` ∨ `onDuty`) ∧ turno ≤ primer `lead_minutes` | `offDuty`/`sleeperBed` |
| `cycle_limit` | ciclo ≤ `cycle_lead_hours[i]` (un aviso por umbral, **sin escalera**) | ciclo se reinicia |
| `rest_complete` | **transición**: (break pasó de < 8 h a 8 h, o manejo/turno volvieron a 11/14 h) ∧ no `driving` ∧ con tracto | pasa a `driving`; si no, aviso en cada `rest_complete_nudge_minutes` y luego `expired` (sin incidente) |
| `violation` | `violation_s > 0`, o manejo = 0 ∧ `driving` | violación vuelve a 0 → `corrected` (el incidente sigue su curso) |

Reglas transversales:
- Status `null` (app desconectada) → no se abre nada, se pausan las escaleras abiertas, log degradado.
- Chofer que sale del conjunto efectivo → episodios abiertos se cierran `unenrolled`.
- Los avisos previos (`lead_minutes` 30/15/0) son escalones informativos **dentro** del episodio: el
  "0" (llegó al límite) es el que arranca la escalera de insistencia completa.

### 3.9 Escalera de insistencia (`AdvanceHosEpisode`)

Cada ciclo, para episodios abiertos con `next_nudge_at <= now`:

1. Toma el escalón `ladder[ladder_step]`, construye el texto con `HosNoticeCopy` y despacha la notificación
   por los canales del escalón.
2. `event_key` / dedupe de notificación = `hos:{episode_id}:{step}` → idempotente ante reintentos o
   solapes del job.
3. Escalón `escalate: incident` → abre el incidente (3.11) y cierra el episodio `incident`.

Se detiene en cuanto el siguiente sondeo ve la situación corregida.

### 3.10 Mensajería al chofer (extensión de Notificaciones)

- **Canal nuevo** `ChannelType::SamsaraDriverApp` + `SamsaraDriverAppNotificationDriver`, que envía con
  `ProviderAdapter::sendDriverMessage(integration, externalDriverId, text)` (`POST /v1/fleet/messages`).
  Gratis: `usageMeterCode()` = `null` (no se mide). Si la respuesta es 401/403 (falta el scope Write
  Messages), el intento se marca fallido, se loguea degradado (`hos.nudge.channel_unavailable`) y la
  escalera brinca al siguiente escalón sin esperar.
- **Destinatario nuevo** `RecipientType::Driver`: resuelve `Driver.phone` (ya normalizado) para
  WhatsApp/SMS/voz y la `DriverExternalReference` de Samsara para la app. Se reutilizan supresiones,
  enfriamiento de canales pagados (`PAID_COOLDOWN_SECONDS`), reconciliación y cobro costo + 30 %.
  **Sin horario silencioso** para HOS (es seguridad vial y el chofer está en ruta).
- `NotificationSourceType::HosEpisode` como fuente.
- **Copy** (`HosNoticeCopy`, mismo estilo que `IncidentNoticeCopy`: español de México, de tú, voz de SAM,
  SMS ≤ 160, versión hablada para la llamada). Ejemplos:
  - break: "Te quedan 15 min para tu break obligatorio de 30 min. Busca dónde parar con seguridad."
  - manejo: "Se te acaban tus 11 h de manejo en 30 min. Planea tu parada para tu descanso de 10 h."
  - retomar: "Ya cumpliste tu descanso de 10 h. Cuando estés listo, puedes retomar tu ruta."
  - ciclo: "Te quedan 5 h en tu ciclo de 70 h. Vas a necesitar tu reinicio de 34 h."

### 3.11 Incidente

Por el pipeline existente, igual que `DetectUnauthorizedStopJob`:

- Evento crudo con `EventSourceType::InternalMonitor`, `payload.internal.monitor = 'hos_watchdog'`,
  tracto, chofer, situación y clocks; `deduplicationKey = hos:{episode_id}`.
- Tipos: `hos_violation` (**ya existe**, categoría `compliance`, severidad alta) para violación reportada y
  **nuevo** `hos_unattended` ("HOS sin atender", `compliance`, alta) para escalera agotada. Nuevo
  `IncidentTypeCode::HosCompliance` en `IncidentTypeSeeder`.
- **Regla de decisión por defecto** en `ApplyDefaultTenantConfig` (+ migración/seeder para tenants
  existentes): `event_type_code in [hos_violation, hos_unattended]` → outcome incidente,
  `stop_processing`. No se agrega a `ai.skip_evaluation_categories` (eso impediría el incidente,
  invariante 6); la regla resuelve antes de la IA, sin costo de IA. **Verificar en el plan** que una regla
  con `stop_processing` efectivamente evita la llamada a IA.
- El incidente sigue la escalera normal de comunicaciones (turno → supervisores → admins).
- Si el chofer corrige **antes de que alguien tome el incidente** (sin acuse), el incidente se resuelve
  solo con una entrada en la línea de tiempo ("El chofer ya se detuvo") y se detiene su escalera. Si ya
  alguien lo tomó, sólo se agrega la entrada y el cierre queda en manos de esa persona.

### 3.12 UI

- **Configuración**: sección `?seccion=hos` en `settings/tenant-config.tsx` (visible sólo con la feature),
  componente `hos-section.tsx`: selector de tags (de `fetchTags`, marcando si son de vehículo, chofer o
  ambos), selector de unidades a incluir/excluir, **vista previa del conjunto efectivo** ("13 tractos · 43
  choferes entran ahora"), switches por situación, umbrales y escalera. Controlador web + espejo API, rutas
  con Wayfinder.
- **Panel**: pestaña **HOS** en el detalle del chofer (estado, barras break/manejo/turno/ciclo, episodio
  activo, historial de avisos con canal y resultado) y vista **HOS (EE. UU.)** en la flota con la lista de
  choferes vigilados ordenada por lo más urgente. Datos de `hos_driver_states`, `hos_episodes` y
  notificaciones; refresco por broadcast `private-accounts.{teamId}` (`QueuesRealtimeBroadcast` +
  `ShouldRescue`) al cerrar cada sondeo.

## 4. Aislamiento de tenant

- `hos_driver_states` y `hos_episodes` con `team_id` + `BelongsToTenant`; todo job entra a
  `TenantContext::for()`; el orquestador es el único `withoutTenant` y sólo lee integraciones.
- La resolución chofer/tracto se hace por `external_id` **dentro del tenant de la integración** (el mismo id
  de Samsara en otro tenant no debe cruzar).
- Caché de tags con `teamId` en la llave. Endpoints bajo `{current_team}`.
- Tests de fuga con `AssertsTenantIsolation` sobre: sondeo (dos tenants con el mismo `driver.id` externo),
  config, panel y API.

## 5. Logging (códigos en `docs/SAM/logging.md`)

`hos.poll.completed` (ok/skipped/degraded, con conteos de vigilados y excluidos por razón) ·
`hos.poll.unauthorized` · `hos.episode.opened` · `hos.episode.resolved` · `hos.nudge.sent` ·
`hos.nudge.failed` · `hos.nudge.channel_unavailable` · `hos.incident.raised` ·
`hos.driver.app_disconnected` · `hos.config.updated`. Sólo ids, situación y números; nunca nombre ni
teléfono del chofer ni texto del mensaje. Cada uno con `assertSystemLogged` + `assertNoSensitiveDataLogged`.

## 6. Pruebas

Fixtures reales de `/fleet/hos/clocks`, `/tags` y `/fleet/drivers` capturados el 2026-10-04 y anonimizados
(`tests/Fixtures/samsara/hos/`). `Http::fake` para Samsara y Twilio; DB real.

- Detección (unit sobre `DetectHosSituations`): cada situación abre y se resuelve; `rest_complete` sólo en
  transición; app desconectada; violación.
- Inscripción: tag de vehículo, tag de chofer, incluido manual, excluido gana, tracto no `monitored` fuera,
  sin tracto fuera.
- Escalera: avance por tiempo (`travel`), idempotencia por `event_key`, se detiene al corregir, escalón
  incidente crea evento interno → regla → incidente; canal de app sin scope brinca de escalón.
- Feature apagada / tenant suspendido → no sondea.
- Fuga de tenant (ver §4) y `AssertsSystemLog`.
- Frontend: Vitest de la sección de config (validación, vista previa) y del panel.

## 7. Entrega en 3 PRs

1. **Observación sin envío.** Tags en el sync, `fetchHosClocks`, `fetchTags`, `hos_driver_states`,
   `hos_episodes`, detección e inscripción (config por tinker/seeder). Se loguean los episodios sin mandar
   nada → validación en vivo con los choferes `USA` del cliente.
2. **Insistencia.** Canal `samsara_driver_app` (+ verificación real del scope con un chofer de prueba),
   destinatario `driver`, `HosNoticeCopy`, escalera, evento interno, regla e incidente.
3. **UI.** Sección de configuración y panel HOS (chofer + flota).

## 8. Fuera de alcance

- Reglas de pasajeros, exenciones (`eldExempt`, adverse weather, big day), reglas de Canadá/México.
- Escribir duty status en Samsara (`POST /v1/fleet/drivers/{id}/hos/duty_status`).
- Leer respuestas del chofer en la app como acuse (posible fase siguiente con `GET /v1/fleet/messages`).
- Cobro adicional por HOS (decidido: incluido en el tracto-día).

## 9. Riesgos y verificaciones abiertas

- **Scope Write Messages** del token: sin él, el primer escalón no llega y la escalera arranca de facto en
  WhatsApp. Pedir al cliente que lo agregue al token.
- **Equipos de dos choferes** (team driving): dos choferes con el mismo `currentVehicle` (visto en T-0423).
  Cada uno se vigila por separado; el que va en `sleeperBed` no recibe avisos de manejo.
- **Latencia**: sondeo de 60 s + clocks que Samsara recalcula; el aviso de "0 min" puede llegar hasta ~1 min
  tarde. Por eso los avisos previos a 30/15 min.
- **Llamadas en movimiento**: decisión explícita del cliente (soporte de celular). Si otro tenant no lo
  quiere, la escalera es configurable.
