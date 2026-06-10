# ROADMAP — SAM Global Systems

> Documento vivo de next steps para frontend y backend. Actualizado al **2026-06-10** (F3 cerrado en PRs #53–#55; B6-P1 `isResolved` cerrado), verificado contra el código (no contra docs viejas).
> Úsalo al inicio de cada sesión para decidir qué sigue. Cuando un ítem se complete, anótale el PR que lo cerró y muévelo a §6. Actualiza este documento en el mismo PR que cierra cada ítem.

---

## 1. Scope del producto (recordatorio)

SAM es una plataforma multi-tenant de gestión de flotas. El flujo central:

```
Proveedor externo (Samsara) → Ingestion (raw events) → Normalization → Context (enriquecimiento + media)
  → AI (evaluación, SDK Laravel AI) → Decisions (motor de reglas) → Incidents (bandeja operativa)
  → Automation (workflows) + Notifications (multicanal) + Audit + Analytics
  → Billing metered local (usage events por tenant; cobro por transferencia, sin Stripe)
```

El producto terminado es: un operador de flota abre SAM, ve su flota en vivo (mapa + telemetría), recibe incidentes generados/triageados por IA, los gestiona desde una bandeja en tiempo real, configura automatizaciones y notificaciones, y consulta analytics — todo tenant-scoped, facturado por uso.

---

## 2. Estado actual (auditoría 2026-06-09, verificada en código)

### Backend — ✅ COMPLETO (specs 01–16 + I1/I2/I3 + diferidos cerrados)

- ~754+ tests verdes. 16 dominios + 3 specs de infra implementados y mergeados (PRs #1–#24).
- **Los diferidos que CLAUDE.md §3 listaba como pendientes YA se cerraron** (PRs #18–#24):
  - IA real: `laravel/ai ^0.6.7` instalado, `SdkEventEvaluationAgent` + `SdkMediaAssessmentAgent` bindeados condicionalmente en `AIServiceProvider` (caen a `Null*` solo si el SDK no está configurado).
  - Multimodal: `ai_media_assessments` + `EvaluateEventMediaJob` shippeados.
  - Reportes: render real PDF (DomPDF) y XLSX en `GenerateReport`.
  - Canal `jobs.{jobId}` con authz real vía modelo `Job` (`routes/channels.php`).
- Pipeline Samsara validado end-to-end con eventos reales: webhook con firma verificada (Base64), replay, seeders, sync periódica de assets/drivers/posiciones (PR #42), scheduler dedicado en compose (PR #46).
- Consola super-admin completa (PRs #37, #41/#43/#44/#45): tenants, suscripción/plan, topes con enforcement, features, miembros, operadores, auditoría cross-tenant, impersonación, ciclo de vida.

**Gaps backend reales que quedan:**

| Gap | Detalle |
|-----|---------|
| Billing/Branding tenant-facing (spec 01 §9) | No existen `BillingController` ni `BrandingController` — el tenant no puede ver su consumo/facturas ni configurar branding. Único endpoint web pendiente del spec 01. |
| Billing local (sin Stripe) | Decisión 2026-06-09: el cobro será por transferencia bancaria — **Stripe queda fuera**. Falta el modelo local: registro de facturas/comprobantes por periodo (FileObject ya existe), estado de pago, y activar/desactivar tenants fácil por impago o factura no subida (la suspensión manual del super-admin, PRs #41–#45, es la base). Evaluar retirar Cashier/Billable al implementarlo. |
| Policies Tenancy | Subscription/TenantBranding/TenantFeature sin policies — crearlas junto con sus controllers (ítem Billing/Branding). |
| Segundo provider | Adapter pattern probado solo con Samsara; Geotab/Motive cuando haya demanda real. |
| Pipeline de emergencias (panic) | Auditoría 2026-06-09 del flujo panic end-to-end: el incidente crítico se crea confiablemente. ~~`isResolved` sin consumir~~ (cerrado en P1: dedup por estado + `ApplyExternalResolution` + setting `annotate`/`close`); sin GPS fresco (se usa `latestLocation`, posiblemente stale); sin media on-demand aunque `has_camera` está en el snapshot y `EventMediaRequest`/`FetchDeferredEventMediaJob` existen (stub sin adapter); SLA decorativo (`response_sla_seconds=300` sin timer ni escalación); notificación genérica sin ubicación ni asignación de operador; ~~panics del mismo vehículo fuera de la ventana de 30 min quedan sin vínculo~~ (cerrado en P8: lookup de cerrados 7d → `PriorSimilarIncident`). **Plan completo en §4 B6 (P1 ✅, P8 ✅, P2–P7 pendientes).** |
| Safety events feed (Samsara) | Solo entran eventos por webhook (`AlertIncident`). El feed `GET /safety-events/stream` (behaviorLabels tipo Crash/Drowsy/MobileUsage, GPS inline, media descargable, eventState) no se consume — son los eventos de seguridad más ricos del proveedor. Plan en B6-P2. |

### Frontend — 🟡 PARCIAL (~35% de las vistas del producto)

**Infra frontend lista:** Echo/Soketi cableado (`resources/js/echo.ts` + hooks `use-team-broadcasts` / `use-echo-channel` / `use-realtime-connection`); la bandeja de incidentes ya consume realtime.

| Página | Estado |
|--------|--------|
| `auth/*` (7 vistas Fortify) | ✅ Real |
| `incidents/index` (bandeja + detalle, 3 layouts, SLA vivo, realtime) | ✅ Conectada al backend (PRs #27–#30, #40) |
| `integrations/index` | ✅ Conectada (PR #31) — **patrón de referencia para páginas nuevas** |
| `admin/*` (tenants, plans, operators, audit) | ✅ Completa (PRs #37, #41–#45) |
| `teams/*`, `settings/{profile,appearance,security}` | ✅ Real (starter kit) |
| `dashboard` | ✅ Conectado (PR #49) — `DashboardController` con KPIs/stream/integraciones/uso reales + realtime |
| `settings/roles/index` | ✅ Conectada (PR #48) — CRUD de roles + cambio de rol de miembros, con `RolePolicy` |

**Vistas del producto que NO existen aún:** Assets/Flota (mapa + lista + detalle), Drivers, Analytics/Reportes, Notificaciones (centro + preferencias + canales), Automation (workflows), TenantConfig (settings del tenant), Billing/Usage + Branding.

---

## 3. NEXT STEPS — Frontend (orden recomendado)

Patrón obligatorio (el de `integrations/index`, PR #31): controller web dedicado en `routes/web.php` (grupo web = sesión + CSRF; NUNCA `/api` para acciones del navegador), props Inertia tipadas, Wayfinder, policy aplicada, `sam-fetch` + `router.reload({ only: [...] })` para acciones, tests de feature del controller.

### F1. ✅ Fix: página `settings/roles/index` faltante — CERRADO (PR #48)
Página creada (CRUD de roles, permisos por módulo, cambio de rol de miembros) + `RolePolicy` nueva: el CRUD no tenía NINGUNA autorización y `MemberRoleController` aceptaba memberships de otros teams (ambos huecos cerrados en el mismo PR). Ver §6.

### F2. ✅ Dashboard real (sustituir `MOCK_DASHBOARD`) — CERRADO (PR #49)
`DashboardController` con KPIs reales (abiertos, críticos, SLA 7d, precisión IA 7d), top-5 incidentes, stream con decisiones del motor, salud de integraciones + eventos 24h, panel nuevo de uso del tenant, y realtime con reloads parciales debounced. Ver §6.

### F3. ✅ Assets / Flota — CERRADO (PRs #53, #54, #55)
Lista (`assets/index` con filtros + realtime, PR #53), detalle (`assets/{id}` con telemetría/historial/incidentes, PR #54) y mapa en vivo (MapLibre GL + tiles OpenFreeMap, markers vía broadcasts, PR #55). Ver §6. **Nota post-merge: correr `npm install` en el checkout principal (dependencia `maplibre-gl` nueva).**

### F4. Drivers
`drivers/index` + `drivers/{id}`: perfil, assignments, documentos (FileObject + `temporaryUrl` listos), risk profile, status log. `DriverPolicy` ya cubre los 6 endpoints. **Esfuerzo: 2 sesiones.**

### F5. Notificaciones en la UI
Campanita/centro (driver Web ya persiste en DB) + preferencias por usuario (`NotificationPreference`) + gestión de canales del tenant (Slack/Twilio/FCM, secrets cifrados con `EncryptedChannelConfigCast`). **Esfuerzo: 2 sesiones.**

### F6. Analytics
Dashboards de KPIs (`KpiRecord` + snapshots de `BuildAnalyticsSnapshotJob`), definición/ejecución de reportes con download PDF/XLSX (el render backend ya es real). **Esfuerzo: 2–3 sesiones.**

### F7. Billing + Branding del tenant (en pareja con B1)
Página de consumo/uso (meters, agregados diarios, invoice snapshots) y settings de branding. Depende de B1 (controllers). **Esfuerzo: 2 sesiones.**

### F8. TenantConfig + Automation UI (cola de prioridad)
Settings del tenant (AI profile, políticas de notificación/escalación, rule overrides, schedules — spec 16 backend completo) y lista/builder de workflows (puede empezar read-only). **Esfuerzo: 3+ sesiones; vistas de power-user, al final.**

---

## 4. NEXT STEPS — Backend (orden recomendado)

### B1. Billing + Branding tenant-facing (spec 01 §9) — único endpoint web pendiente
`BillingController` (uso, meters, agregados, invoice snapshots del team actual) + `BrandingController` (logo vía FileObject, colores), con policies de Tenancy (Subscription/TenantBranding/TenantFeature) que hoy no existen. Alimenta F7. **Esfuerzo: 1–2 sesiones.**

### B2. Billing local-only (cobro por transferencia) — re-scoped 2026-06-09, Stripe CANCELADO
El cobro será por transferencia bancaria; todo lo relacionado a Stripe queda fuera del roadmap. Modelo local: facturas/comprobantes subidos por periodo (FileObject + `temporaryUrl` listos), estado de pago por tenant, y **activar/desactivar tenants de forma sencilla** cuando no paguen o no hayan subido su factura (reutilizar la suspensión/ciclo de vida del super-admin, PRs #41–#45). Los usage meters locales (`ai_calls`, `ai_tokens_*`, assets) siguen siendo la fuente del consumo a cobrar. Al implementar: evaluar retirar Cashier/Billable del código. **Esfuerzo: 2 sesiones.**

### B3. ✅ IA real en operación — CERRADO (PR #50)
Provider OpenAI configurable por env (`AI_DEFAULT`/`OPENAI_API_KEY`/`OPENAI_TEXT_MODEL`), binding config:cache-safe, latencia real (hrtime) y costo real (`ModelPricing` + `ai.pricing`) en ambos agentes SDK, validado end-to-end con `samsara:replay` (facturación `ai_calls`/`ai_tokens_in`/`ai_tokens_out` verificada). Ver §6. Follow-up pendiente: routing de modelo por tenant (`TenantAIProfileData.preferredModel`) y afinado fino de prompts.

### B4. Endpoints de soporte para las vistas nuevas
A demanda de F3–F6: lecturas que falten (historial de posiciones paginado, agregados para dashboard, etc.). Siempre Queries DB-backed en el dominio dueño (patrón `Db*MetricsQuery`), nunca lógica en el controller. **Esfuerzo: incremental.**

### B5. Hardening / expansión (cola de prioridad)
Segundo provider de integración (Geotab/Motive) para validar que el adapter pattern generaliza — solo con demanda real. Revisión de retention jobs (audit/analytics) en producción.

### B6. Pipeline de emergencias (panic) + safety events — plan P1–P8

Origen: auditoría del flujo panic end-to-end (2026-06-09, ver gaps en §2). Objetivo: que un panic llegue con GPS del momento, footage de cámara, señales de resolución/falsa alarma, SLA con escalación real y notificación accionable — y que los **safety events** de Samsara (crash, fatiga, distracción…) entren al mismo pipeline.

**Infra existente que se reutiliza (no inventar):** `EventMediaRequest` + `RequestDeferredEventMedia` + `FetchDeferredEventMediaJob` (hoy stub — el comentario del job dice explícito que espera el adapter del proveedor), `AttachImmediateEventMedia` (materializa `RawEventAttachment` → `EventMediaContext` + `FileObject`), `EvaluateEventMediaJob` (multimodal), `EscalationPolicy` (Decisions) + `EscalateIncident` (Incidents), resolvers de spec 16 (`TenantSetting`, `TenantEscalationConfig`, `TenantScheduleProfile`), scheduler con `PollAllAssetLocationsJob`/`SyncDueIntegrationsJob` como patrón, `SamsaraAdapter.fetchAssetLocations`.

**API Samsara verificada (spec oficial, 2026-06-09):** `GET /safety-events/stream` — feed con cursor (`startTime` por `updatedAtTime` + `after`/`endCursor`), trae GPS inline (lat/lng + dirección + geofence), `behaviorLabels`, `eventState` (needsReview/dismissed/…), `maxAccelerationGForce` y URLs de media por cámara; rate limit 5 req/s; scopes *Read Safety Events & Scores* + *Read Camera Media* · `POST`/`GET /cameras/media/retrieval` — footage on-demand por vehículo/cámara/ventana de tiempo · `GET /fleet/vehicles/locations` — posición fresca por vehículo.

**Principios transversales:** degradar nunca suprime el registro del evento; auto-close default **off** (un panic cancelado puede ser coacción); media auto-request gateado por `TenantSetting` (consume cuota/costo); todo punto facturable nuevo pasa por `RecordUsageEvent` con `event_key` idempotente; colas según topología existente (`ingestion`/`context`/`notifications`).

#### P1 ✅ CERRADO — Ciclo de vida de `isResolved` (quick win)
Implementado tal como se diseñó (ver §6): dedup key de `AlertIncident` con sufijo de estado (`{eventId}:open|resolved`), `Incidents/ApplyExternalResolution` + `ApplyExternalResolutionJob` (lookup por `external_event_id` del raw event original, fallback asset/driver en ventana), columna `incidents.external_resolved_at`, timeline `externally_resolved`, `TenantSetting` `panic.auto_close_on_external_resolution` = `annotate` (default) | `close`, y anotación en creación cuando el evento ya llega resuelto (nunca auto-close en creación). El trigger de media de P3 NO se incluyó — queda íntegro en P3.

#### P2 — Safety events feed (dónde y cómo se guardan)
**Sin tablas nuevas**: los safety events entran como `RawEvent` por el mismo embudo que los webhooks. Nuevo `PollSamsaraSafetyEventsJob` (cola `ingestion`, scheduler cada 1–2 min `onOneServer()`, patrón `PollAllAssetLocationsJob`): por cada `TenantIntegration` Samsara activa lee `GET /safety-events/stream` paginando y persiste el `endCursor` en `sync_state_json` de la integración (arranque sin cursor = `now() - 24h`). Cada evento → `RawEvent` (`event_type_raw='SafetyEvent'`, `payload_json` completo, `event_source` tipo nuevo `polling_feed`, dedup key `safety:{id}:{eventState}` — el feed va por `updatedAtTime`, así que el mismo evento reaparece cuando cambia de estado y debe pasar como update, mismo mecanismo que P1). Media inline: descargar las URLs **al momento** (expiran) → `RawEventAttachment` → `AttachImmediateEventMedia` las materializa sin código nuevo. Normalización: seeders de `EventMappingRule` por behaviorLabel (`Crash`/`HarshImpact`→`collision` critical, `Drowsy`→`driver_fatigue` high, `MobileUsage`→`distracted_driving` medium, `SevereSpeeding`→`severe_speeding` high, …) + `EventType`s nuevos en `NormalizationSeeder`; el extractor de location de `NormalizeRawEvent` aprende la ruta `location.latitude/longitude` (GPS inline — aquí no hay stale). `eventState=dismissed` en Samsara → reutiliza `ApplyExternalResolution` (P1). Reglas de decisión seed: `collision` → INCIDENT urgente siempre; resto según clasificación IA. Usage: `RecordUsageEvent` por evento ingerido. Tests: cursor avanza/no repite/retoma tras fallo; throttle 5 req/s; mapeos por label; update de estado no duplica incidente; media inline → `EventMediaContext`; tenant isolation. **Esfuerzo: L. Depende de P1 (semántica de updates).**

#### P3 — Media on-demand para el panic (cablear el stub)
Nuevo contrato `App\Contracts\Integrations\MediaRetrievalAdapter` (`requestMedia`/`checkMedia`) implementado en `SamsaraAdapter`: `POST /cameras/media/retrieval` (vehículo + `dashcamRoadFacing`/`dashcamDriverFacing` + ventana `occurred_at ± 30s`) guardando `retrievalId` en `EventMediaRequest.metadata_json`; `FetchDeferredEventMediaJob` deja de ser stub — crea el retrieval, se re-encola con su backoff existente hasta `available`, descarga a `teams/{team}/events/{event}/media/` y materializa `EventMediaContext`/`FileObject` (reutilizar lógica de `AttachImmediateEventMedia`); `RefreshContextMediaSnapshot` ya bumpea `context_version` y `EventMediaAvailable` ya dispara `EvaluateEventMediaJob` (multimodal). Trigger: listener `RequestPanicMediaOnContextBuilt` — `severity=critical` + `asset_snapshot.has_camera` → `RequestDeferredEventMedia` (idempotente), gateado por `TenantSetting` `media.auto_request_on_critical`. Usage: `media_request:{id}`. Tests: Http::fake del ciclo pending→sent→processing→ready; expiración 6h → `Failed`; no pide sin cámara ni en no-críticos. **Esfuerzo: M. Incluye tanto el trigger (listener) como el adapter de retrieval (P1 cerró sin tocar media).**

#### P4 — GPS fresco en el momento del panic
Extender `ProviderAdapter` con `fetchLiveLocation(TenantIntegration, string $externalAssetId): ?array` → `GET /fleet/vehicles/locations?vehicleIds={id}` (patrón ya existente en `fetchAssetLocations`). En `EnrichContextJob`, **solo severidad critical**: si la última posición es más vieja que `context.live_location_staleness_seconds` (default 60), fetch en vivo con timeout 2–3s; éxito → `location_snapshot_json.source='live_fetch'` + persistir como `AssetLocation` (alimenta el mapa); fallo → fallback silencioso a `latestLocation` con flag `position_stale`. El geofence matching corre sobre la posición fresca (habilita "estacionado en base" de P7). Tests: fetch solo en critical; fallback ante timeout sin romper pipeline. **Esfuerzo: S.**

#### P5 — Notificación rica + asignación de operador
`NotificationTemplate` `incident.panic.created` (asset, driver, ubicación con dirección, link al incidente, indicador de media) — `NotifyOnIncidentCreated` resuelve template por `incident_type` antes del genérico. Auto-asignación: `TenantScheduleProfile` (spec 16) define el on-call → `assigned_to_id` en el incidente + notificación dirigida Critical por todos los canales del usuario. El contacto directo al driver (SMS/WhatsApp — drivers Twilio ya implementados) va como `ActionTemplate` de Automation **opt-in**, no hardcodeado. **Esfuerzo: M. Mejor después de P2/P4 (para tener ubicación rica).**

#### P6 — SLA real con escalación
Columnas en `incidents`: `sla_due_at` (= `opened_at` + `response_sla_seconds` de la severidad), `acknowledged_at`/`acknowledged_by` + endpoint `POST /incidents/{id}/acknowledge` (botón en la bandeja). `CreateIncidentFromEvent` despacha `CheckIncidentAcknowledgementJob` con `->delay($sla)` (sin cron por-minuto): si al ejecutarse sigue abierto y sin ack → `EscalateIncident` (existe) + notificación a contactos de `TenantEscalationConfig` + timeline `SlaBreached`; el job se reprograma por nivel de la `EscalationPolicy` hasta ack o agotamiento. Broadcast de escalación → la bandeja realtime ya escucha el canal. Tests: ack a tiempo no escala; sin ack escala y reprograma; cierre cancela; idempotencia. **Esfuerzo: M.**

#### P7 — Validación de falsa alarma (degradación inteligente)
Depende de señales de P1/P3/P4. Nuevas señales en `signals_json`/`AIInputContext`: `external_resolved`, `parked_at_base` (geofence `WITHIN` tipo base + speed 0), `repeated_panic_count_24h`, `media_assessment`. `RuleConditionEvaluator` ya soporta los operadores — solo exponer los campos nuevos en los facts de `EvaluateDecisionRules`. Reglas de tenant **opt-in** (seeder de ejemplo): panic + resuelto + estacionado en base → outcome `REVIEW` con `requires_human_review` en vez de INCIDENT urgente; la regla dura `panic-button-always-incident` sigue siendo el default. Prompt del `SdkEventEvaluationAgent`: evaluar falsa alarma vs. coacción (panic "resuelto" en zona insegura NO se degrada). Tests: matriz panic limpio→urgente / resuelto+en base→review / resuelto en carretera→urgente / IA caída→nunca degrada. **Esfuerzo: M. Depende de P1, P3, P4.**

#### P8 ✅ CERRADO — Vínculo histórico de incidentes
Implementado (2026-06-10, rutina nocturna): `GetPriorSimilarIncidents` busca incidentes **cerrados** (status terminal) del mismo asset/driver en los últimos 7 días (`config('incidents.context_prior_lookback_days')`, default 7), excluyendo los ya vinculados al propio evento; `BuildEventContext` los persiste como `EventRelatedIncidentLink` tipo `PriorSimilarIncident` y los mete en `incidents_snapshot_json` (filas marcadas `relation = prior_similar_incident`, con `closed_at`). `SignalsBuilder` expone `has_prior_similar_incident` y `has_open_incident` sigue contando solo abiertos. Sin merge automático — solo visibilidad. La UI "Historial relacionado" del incidente queda para la fase de frontend de incidentes.

**Orden recomendado dentro de B6:** ~~P1~~ ✅ → P2 → P3 → P4 → P5 → P6 → P7 → ~~P8~~ ✅. Un PR por fase (P2 puede partirse en poller/normalización si crece), CI verde, y actualizar este documento en el mismo PR que cierre cada P.

---

## 5. Orden global sugerido (fases por sesiones)

| Fase | Ítems | Resultado |
|------|-------|-----------|
| **1. Quick wins ✅** | F1 (roles rota) → F2 (dashboard real) | App sin páginas rotas ni mocks |
| **2. IA encendida ✅** | B3 (IA real operando) | Incidentes triageados por IA de verdad — demo del corazón del producto |
| **3. Flota visible + emergencias 🔵 ACTUAL** | F3 ✅ (assets + mapa, PRs #53–#55) → F4 (drivers) · backend en paralelo: B6-P1 ✅ (isResolved) → P2 (safety feed) → P3 (media panic) → P4 (GPS fresco) | El operador ve su flota en vivo y el pipeline de emergencias consume señales reales (resolución, footage, posición fresca, safety events) |
| **4. Cierre operativo** | F5 (notificaciones UI) → F6 (analytics) · B6-P5 (notificación rica + asignación) → P6 (SLA + escalación) → P7 (falsa alarma) → P8 (histórico) | Producto operativo completo con respuesta a emergencias garantizada |
| **5. Monetización** | B1+F7 (billing/branding) → B2 (billing local por transferencia) | Listo para facturar |
| **6. Power-user** | F8 (tenant config / automation UI) → B5 | Configurabilidad avanzada |

**Regla de decisión al abrir sesión:** si la fase actual tiene un ítem a medias, continuarlo; si no, tomar el siguiente de la tabla. Un PR por ítem (o sub-ítem), CI verde antes de merge, y actualizar este documento en el mismo PR.

> ⚠️ Nota de fiabilidad: la tabla de estado §3 de `CLAUDE.md` quedó congelada en la auditoría 2026-04-29 y su lista de "diferidos" estaba obsoleta (se corrigió el 2026-06-09). Ante duda, verificar contra el código, no contra docs.

---

## 6. Completados (histórico resumido)

- Backend specs 01–16 + I1/I2/I3 y cierre de diferidos: AI SDK, multimodal, PDF/XLSX, drivers de notificación, queries DB-backed, Echo wiring, DemoSeeder (PRs #1–#24).
- UI Incidents con realtime (PRs #27–#30, #40) · UI Integraciones (PR #31).
- Pipeline Samsara real: adapter, firma webhook, replay, seeders, sync periódica, scheduler dedicado (PRs #28, #32, #34, #35, #42, #46).
- Consola super-admin completa (PRs #37, #39, #41, #43–#45).
- **F1** — Página `settings/roles` (CRUD de roles + permisos por módulo + cambio de rol de miembros) con `RolePolicy` nueva cerrando el hueco de autorización del CRUD y el scoping cross-team de `MemberRoleController` (PR #48).
- **F2** — Dashboard con datos reales: `DashboardController` (KPIs honestos incl. SLA 7d y precisión IA con la fórmula de `EvaluateAIEffectiveness`, top-5, stream con decisiones, integraciones + eventos 24h, uso del tenant) + queries nuevas en Incidents/Normalization + realtime debounced. Fase 1 (quick wins) completa: app sin páginas rotas ni mocks (PR #49).
- **B3** — IA real en operación: `AI_DEFAULT`/`OPENAI_API_KEY`/`OPENAI_TEXT_MODEL` en env (default `gpt-5.4`), `isAiSdkConfigured()` leyendo config en vez de `env()` (config:cache-safe), `ModelPricing` + sección `ai.pricing` (USD/1M tokens, fallback por prefijo para ids versionados), latencia real con hrtime y `cost_estimate` real en `SdkEventEvaluationAgent`/`SdkMediaAssessmentAgent`, tests nuevos (pricing, media via SDK, bindings) y pipeline validado con `samsara:replay` + verificación de `ai_inference_logs` y `usage_events`. Fase 2 (IA encendida) completa (PR #50).
- **F3** — Assets/Flota completo: lista `assets/index` con filtros q/status/type, paginación y realtime debounced (PR #53); detalle `assets/{id}` con telemetría por tipo, historial de ubicaciones e incidentes vinculados (PR #54); mapa en vivo con MapLibre GL 5 + tiles OpenFreeMap (chunk lazy ~276KB gzip, markers actualizados en memoria vía `asset.location_updated` sin reload) (PR #55).
- **B6-P1** — Ciclo de vida de `isResolved` (panic): dedup key de `AlertIncident` con estado (`{eventId}:open|resolved` — cambios de estado pasan, mismo estado es duplicado), columna `incidents.external_resolved_at`, acción `ApplyExternalResolution` (timeline `externally_resolved` "Resolved at source", idempotente), `ApplyExternalResolutionJob` en cola `incidents` (lookup del incidente original por `external_event_id` del raw event, fallback asset/driver en ventana de 30 min), listener sobre `EventNormalized`, `TenantSetting` `panic.auto_close_on_external_resolution` = `annotate` (default) | `close` (cierra vía `CloseIncident` con `ResolutionCode::ResolvedExternally`), y anotación en creación cuando el evento ya llega resuelto (nunca auto-close en creación — un panic cancelado puede ser coacción). `NormalizeRawEvent` expone `external_resolved_at` (`data.resolvedAtTime`).
