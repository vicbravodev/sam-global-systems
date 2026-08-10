# Pack 6 — Pulido i18n + extras proactivos (P2)

> **Objetivo:** cero inglés en UI de usuario, coherencia de producto, y extender los monitores proactivos.
> **Origen:** spec §3 Pack 6 · hallazgos `AUDITORIA.md` A10 (inglés/códigos), Assets (3 watchdogs, supresión por horario), Frontend (badge rol, MockIncident).

## Diseño

### i18n (corregir, no solo reportar)
Traducir/etiquetar cada string del **inventario de inglés** de `AUDITORIA.md §3 Frontend`:
- Cabeceras: `Timeline` (`detail-timeline.tsx:34`) → "Línea de tiempo"; `Outcome` (`rules/index.tsx:303,703`) → "Resultado"; `Meter` (`billing/index.tsx:330`) → "Medidor".
- Estados crudos de billing (`active`/`trial`/`paid`/`pending`/`suspended`/`cancelled`) → etiquetas español.
- Override types y feature keys crudas (`force_human_review`, `sms`, `ai_eval`) → mapa de etiquetas.
- `events/show.tsx:185`: `eventTypeCode` crudo como H1 → fallback humanizado (reusa `lib/labels.ts` de Pack 4).
- Badge de rol en topbar (`ops-topbar.tsx:169`) hardcodeado "Supervisor" → **rol real** del usuario (`auth().user.roles`/rol de equipo de Access).
- Renombrar tipo `MockIncident` → `IncidentRow` (alias ya existe en `types/dashboard.ts`); consolidar.

### Extras proactivos (mismo molde `Detect*Job`)
- **Batería baja (P1 dentro del pack):** `DetectLowBatteryAssetsJob` con umbral configurable por tenant, auto-resolución y anti-spam (espejo de `DetectOfflineAssetsJob`).
- Velocidad sostenida y geofence dwell (P2): mismos patrones.
- **Supresión por ventana de horario/mantenimiento** (cierra hallazgo Assets): que `DetectOfflineAssetsJob`/`DetectUnauthorizedStopJob` consulten `TenantScheduleResolver::withinOperatingHours()` antes de despachar, no solo el status del asset.
- Añadir `public int $tries = 1` (o idempotencia posicional) a los 3 `Detect*Job` para que un retry parcial no contamine `raw_events`.

## Tests
- `DetectLowBatteryAssetsJobTest`: umbral por tenant, genera alerta, auto-resuelve, dedup (feature + isolation).
- `ScheduleSuppressionTest`: asset apagado dentro de ventana de mantenimiento planificada **no** genera alerta falsa.
- Guard i18n: test/lint que falle si aparecen las palabras inglesas del inventario en JSX visible (anti-regresión).

## Criterios de aceptación (del spec)
- [ ] Sin inglés en UI de usuario; el badge de rol refleja el rol real; al menos el monitor de **batería baja** shippeado.

## Archivos clave
`resources/js/components/sam/incident-detail/detail-timeline.tsx`, `resources/js/components/sam/ops-topbar.tsx`, `resources/js/pages/rules/index.tsx`, `resources/js/pages/billing/index.tsx`, `resources/js/types/sam.ts` + `types/dashboard.ts`, `resources/js/lib/labels.ts`, nuevo `app/Domains/Assets/Jobs/DetectLowBatteryAssetsJob.php`, `routes/console.php`.

## Riesgo
Bajo. i18n es mecánico; el monitor de batería sigue un patrón ya probado.
