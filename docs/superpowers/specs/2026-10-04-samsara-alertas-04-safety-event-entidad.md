# Spec 4 — El safety event como entidad con estado

Fecha: 2026-10-04 · Rama: `feat/safety-event-entidad-con-estado` · Índice: [00](2026-10-04-samsara-alertas-00-indice.md)

## Problema

Samsara modela un safety event como un registro que cambia: su `eventState`
(`needsReview`, `reviewed`, `coached`, `dismissed`, …) y sus `behaviorLabels`
cambian cuando alguien lo revisa. El stream lo reentrega con cada cambio.

SAM lo trata como una serie de hechos:

- `IngestSafetyEvent` deduplica por `safety:{id}:{eventState}`, así que cada
  estado entra como un raw event nuevo (bien: queda la auditoría completa).
- `NormalizeRawEvent` crea un `normalized_event` **por raw event**
  (`updateOrCreate(['raw_event_id' => …])`).

Consecuencias cuando un cliente revise en Samsara:

- `RecalculateDriverRiskProfilesJob` y `LoadRecentAssetHistory` hacen `count(*)`
  sobre `normalized_events`: un frenazo revisado cuenta 2 o 3 veces.
- Un evento descartado (`dismissed`, falso positivo) sigue contando contra el
  conductor.
- Si la etiqueta cambia (p. ej. a `Invalid` tras revisión), queda el evento viejo
  con la etiqueta vieja y uno nuevo con la nueva.

Medición en dev (2026-10-03): 0 eventos afectados, porque nadie revisa todavía.

## Objetivo

Un safety event = **una fila** de `normalized_events`, que se actualiza en sitio
con el último estado y la última etiqueta. Los descartados dejan de contar en el
riesgo del conductor y en el historial reciente de la unidad.

## Diseño

### Esquema

Migración sobre `normalized_events`:

| Columna | Tipo | Uso |
|---|---|---|
| `provider_event_key` | string(191) nullable | Identidad del proveedor: `samsara:safety:{id}`. Sólo safety events del feed. |
| `provider_state` | string(32) nullable | Último `eventState` |
| `provider_dismissed_at` | timestamp nullable | Cuándo se descartó en origen (`updatedAtTime`), null si no |

Índice único parcial `(team_id, provider_event_key) WHERE provider_event_key IS NOT NULL`.

Backfill en la misma migración (pre-producción, volumen bajo): para cada safety
event del feed (raw con `deduplication_key` `safety:%`), clave y estado desde su
raw. Si hubiera varios por clave, la fila más reciente se queda la clave; las
anteriores quedan con `provider_state = 'superseded'` y sin clave (no se borran:
pueden tener enlaces a incidentes).

### Normalización

`NormalizeRawEvent::createNormalizedEvent`, sólo para safety events del feed
(raw con `deduplication_key` `safety:…` y `external_event_id`):

1. Busca la fila por `(team_id, provider_event_key)`.
2. **No existe** → crea como hoy, con `provider_event_key`, `provider_state` y
   `provider_dismissed_at`, y despacha `EventNormalized` (camino actual completo).
3. **Existe** → actualiza en sitio `raw_event_id` (apunta al último raw),
   `event_type_id`/categoría/severidad (si cambió la etiqueta), `provider_state`,
   `provider_dismissed_at`, `payload_normalized_json` y `processed_at`.
   **No** cambia `occurred_at` ni `trace_id` de origen. Marca el raw como
   procesado y despacha `NormalizedEventUpdated` (evento nuevo), no
   `EventNormalized`: el contexto, la media y la IA no se repiten por un cambio de
   estado.
4. Excepción: si el cambio de etiqueta **convierte** el evento en emergencia
   (antes no lo era, ahora sí: p. ej. revisado como `Crash`), se despacha también
   `EventNormalized`, para que la ruta rápida abra el incidente.

La concurrencia (dos estados del mismo evento en workers distintos) se cierra con
el índice único: la creación usa `firstOrCreate` dentro de una transacción y
reintenta como actualización si choca con la clave.

Unidad no vigilada: si la fila existe, se actualiza igual (es el mismo evento ya
admitido); si no existe, se descarta como hoy.

### Resolución en origen

`ApplyExternalResolutionOnEventNormalized` escucha también `NormalizedEventUpdated`.
`ApplyExternalResolutionJob::findOpenIncidents` añade la estrategia
`same_event` antes de `external_event_id`: incidentes abiertos enlazados a **este**
`normalized_event` (con la entidad, el incidente del choque está enlazado a la
misma fila que ahora llega `dismissed`).

### Consumidores que cuentan

Scope nuevo `NormalizedEvent::scopeCountable()`:
`provider_dismissed_at IS NULL AND (provider_state IS NULL OR provider_state <> 'superseded')`.

Se aplica en:

- `RecalculateDriverRiskProfilesJob` (conteos por conductor).
- `LoadRecentAssetHistory` (historial reciente y correlación que alimenta la IA).
- Copilot (`AssetActivityTool`, `RankAssetsTool`, `AssetTimelineTool`,
  `PanicKpisTool`) y `DbNormalizedEventStatsQuery`: se revisa cada uno; los que
  cuentan comportamiento usan `countable()`, los que listan historial muestran el
  estado.

La página de Eventos sigue mostrando todo; en el detalle se ve el estado en
origen ("Descartado en Samsara").

## Logging

- `normalization.safety_event.updated` (ok): `input` `normalized_event_id`,
  `raw_event_id`; `calc` `state_from`, `state_to`, `label_changed`,
  `became_emergency`.
- `normalization.safety_event.update_race` (degraded→ok): choque de clave
  resuelto como actualización.
- `incidents.external_resolution.matched` gana la estrategia `same_event`.

## Tests

- Mismo evento `needsReview → reviewed → dismissed`: una sola fila, estado final
  `dismissed`, `provider_dismissed_at` puesto, 3 raw events procesados.
- `dismissed` deja de contar en el perfil de riesgo y en el historial reciente.
- Cambio de etiqueta a `Crash`: actualiza el tipo y abre incidente por la ruta
  rápida; cambio de etiqueta entre no emergencias: no despacha `EventNormalized`.
- Choque `Crash` luego `dismissed`: la resolución en origen encuentra el incidente
  por `same_event`.
- Reentrega del mismo estado: duplicado como hoy (no toca la fila).
- Backfill: dos filas para la misma clave → la reciente con clave, la vieja
  `superseded`.
- **Fuga**: el mismo `external_event_id` en dos tenants produce dos filas, una por
  tenant; la actualización de uno nunca toca la del otro.
- `assertSystemLogged` + `assertNoSensitiveDataLogged`.

## Fuera de alcance

- AlertIncidents: su ciclo (`open → resolved`) ya se maneja con la identidad de
  `ResolveAlertIncidentIdentity` y la resolución en origen.
