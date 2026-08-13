# Pack 4 — UX del monitorista (P1)

> **Objetivo:** que el agente entienda un panic de un vistazo y vea info de primera mano.
> **Origen:** spec §3 Pack 4 · hallazgos `AUDITORIA.md` A2 (razonamiento fabricado), A3 (sin mapa), A10 (códigos crudos), + botones muertos y TZ.

## Diseño

### 4.3 Mapa en el detalle del incidente
- Incrustar `LiveMap` (ya existe en `components/sam/assets/live-map.tsx`) en `incidents/show.tsx`/`detail-center.tsx`, centrado en las coordenadas del asset al momento del panic, con pin y, si hay, la traza reciente (`AssetLocationSnapshot`).
- Backend: exponer `lat/lng` + traza reciente en los props del detalle (`IncidentInboxController`/`incidents/show`).

### 4.4 Trace real de IA (cierra A2 — honestidad)
- Eliminar los 5 pasos sintéticos de `detail-center.tsx:303-327`.
- Exponer en los props el `explanation_text`/`reasoning_steps`/`key_factors` reales (`AIEventEvaluation`/`AIExplanation`) y el `DecisionTrace` (spec 10). Renderizar eso; si no hay trace, etiquetar honestamente "Pasos del pipeline" con datos reales (no fabricados).

### 4.5 Panel "Eventos de seguridad alrededor (±N min)"
- Nuevo panel en el detalle que liste el `nearby_safety_breakdown` con **etiquetas humanas en español** y el `offset_seconds` (de Pack 3.3): "Frenada brusca · 1 min antes".
- Sustituir los códigos crudos (`forward_collision_warning`) en "Relacionados" y "Stream en vivo".

### 4.6 Pulido
- Mapa central `lib/labels.ts`: códigos de evento Samsara → español; decisiones → `DECISION_LABEL` (unificar las dos copias inconsistentes de `detail-center.tsx` y `dashboard.tsx`).
- Evidence tiles: cablear `onClick` para abrir `MediaGallery`/`FileObject`, o quitarlos.
- "Ver en {provider}": `href` real desde `RawEvent`/`ExternalRef` (URL del evento en Samsara); si no hay, ocultar el botón.
- Zona horaria MX: forzar `timeZone:'America/Mexico_City'` en `lib/format.ts` y adoptar `formatDate/formatDateTime` en todas las páginas (hoy solo billing lo usa); el turno del dashboard debe convertir con esa TZ.

## Tests
- Inertia: `incidents/show` recibe props con `reasoning`/`explanation` reales (no string fabricado) y `coordinates`.
- Unit: `labels.ts` mapea los códigos comunes a español; `DECISION_LABEL` cubre todos los outcomes.
- Render: el detalle muestra el panel de safety events con etiqueta+offset; "Ver en Samsara" tiene `href` cuando hay `external_ref`.

## Criterios de aceptación (del spec)
- [ ] El detalle de un panic muestra **mapa** + safety events correlacionados **etiquetados en español** + razonamiento **veraz**.
- [ ] El "Stream en vivo" no muestra códigos crudos.

## Archivos clave
`resources/js/components/sam/incident-detail/*` (`detail-center.tsx`, `detail-timeline.tsx`, `detail-header.tsx`), `resources/js/pages/incidents/show.tsx`, `resources/js/pages/dashboard.tsx`, nuevo `resources/js/lib/labels.ts`, `resources/js/lib/format.ts`, `components/sam/assets/live-map.tsx` (reuso), backend `IncidentInboxController` (props de mapa + trace + external ref).

## Riesgo
Bajo. Principalmente front; la dependencia es exponer trace/coords/external-ref reales en los props (cambio aditivo de controller).
