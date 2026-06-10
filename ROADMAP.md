# ROADMAP — Rutina recurrente

> Cola de trabajo del agente recurrente (`claude/night-roadmap`, runs cada ~2 h). Reglas en [`CLAUDE.md`](CLAUDE.md) §8; prompt maestro en [`ROUTINE_PROMPT.md`](ROUTINE_PROMPT.md).
> **No confundir con [`docs/ROADMAP.md`](docs/ROADMAP.md)** (roadmap de producto): este archivo es la cola operativa; el de `docs/` es la fuente de prioridades de donde salen las tareas.
>
> Formato de tarea: `- [ ]` pendiente · `- [x]` completada (mover a "Completadas" con fecha y commit) · `- [!]` bloqueada (mover a "Bloqueadas" con explicación).
> Las tareas auto-generadas van en secciones `## Iteración v{N} — auto-generada {fecha}` (máx. v5, máx. 10 tareas auto-generadas por día sumando todos los runs).

---

## Iteración v1 — manual

## Iteración v2 — auto-generada 2026-06-10

- [ ] **B6-P3 — Media on-demand para el panic** (spec: `docs/ROADMAP.md` §4 B6-P3, esfuerzo M). Contrato `App\Contracts\Integrations\MediaRetrievalAdapter` (`requestMedia`/`checkMedia`) implementado en `SamsaraAdapter` (`POST/GET /cameras/media/retrieval`, `retrievalId` en `EventMediaRequest.metadata_json`); `FetchDeferredEventMediaJob` deja de ser stub (crear retrieval → re-encolar con backoff hasta `available` → descargar a `teams/{team}/events/{event}/media/` → materializar `EventMediaContext`/`FileObject` reutilizando `AttachImmediateEventMedia`); listener `RequestPanicMediaOnContextBuilt` (critical + `has_camera` → `RequestDeferredEventMedia`, gateado por `TenantSetting` `media.auto_request_on_critical`); usage `media_request:{id}`. Tests: ciclo pending→ready con Http::fake, expiración 6h → Failed, no pide sin cámara ni en no-críticos.

- [ ] **F4b — Página `drivers/{id}`** (spec: `docs/ROADMAP.md` §3 F4, segunda mitad; F4a cerrada en esta rama). Detalle de conductor con el patrón de `assets/show`: `DriverPageController@show` (authorize `view`, abort 404 cross-team), perfil + assignments históricos + contactos + documentos (FileObject con `temporaryUrl`) + risk profile + status log; página React `drivers/show.tsx` (layout Ops), fila de la lista navega al detalle. Tests: feature con `assertInertia` (component + props), 403 sin permiso, 404 cross-team.

- [ ] **T1 — `assertInertia` para páginas de producto sin aserción de componente** (hallazgo de auditoría 2026-06-10: `teams/index`, `teams/edit`, `admin/operators/index`, `settings/profile`, `settings/appearance` se renderizan en tests existentes pero ningún test fija el componente/props con `assertInertia`, así que un typo en el nombre del componente o una prop rota no se detecta). Añadir o extender los feature tests correspondientes con `$response->assertInertia(fn (Assert $page) => $page->component('...')->has(...))` para esas 5 páginas. No tocar `welcome` ni las `auth/*` que ya tienen coverage equivalente vía Fortify.

## Completadas

- [x] **B6-P2 — Safety events feed de Samsara** — 2026-06-10, commit `feat(ingestion): safety events feed de Samsara (B6-P2)`. `SamsaraAdapter::fetchSafetyEvents` (`GET /safety-events/stream`, paginación interna, `startTime` sin cursor / `after` con cursor; en contrato `ProviderAdapter` + Null + manager). `PollSamsaraSafetyEventsJob` (orquestador, scheduler cada 2 min `onOneServer()`, gate `config_json.sync.poll_safety_events`) → `PollSafetyEventsJob` por integración (única, cola `ingestion`, cursor persistido en `tenant_integrations.sync_state_json` nuevo — migración additive; backfill 24 h sin cursor). `IngestSafetyEvent`: `RawEvent` con dedup key `safety:{id}:{eventState}` (transiciones de estado pasan como update, re-entregas same-state se marcan duplicate), `event_type_raw` = behaviorLabel (mapea con las reglas seeded; fallback `'SafetyEvent'`), source type nuevo `polling_feed`, media inline (downloadForward/Inward/TrackedInwardVideoUrl) descargada al momento → `RawEventAttachment` (no se re-descarga en duplicados), `RecordUsageEvent` idempotente (meter nuevo `ingested_events`, `IngestionMeterSeeder`). Normalización: `eventState=dismissed` → `is_resolved=true` + `external_resolved_at=updatedAtTime` (reusa `ApplyExternalResolution` de P1); `occurred_at` aprende `payload.time`; seeder añade `severe_speeding` (high) y remapea `SevereSpeeding`. Tests: `SamsaraAdapterSafetyEventsTest` (5) + `SafetyEventsPollingTest` (10: cursor inicial/retoma, duplicado same-state, transición de estado → resolución sin duplicar incidente, media única, usage idempotente, aislamiento de tenant, fan-out del orquestador, mapeo severe_speeding).

- [x] **F4a — Página `drivers/index`** — 2026-06-10, commit `feat(drivers): página drivers/index (F4a)`. `DriverPageController@index` en grupo web (`/{current_team}/drivers`, `DriverPolicy::viewAny` con `drivers.view`), filtros q (nombre/código, case-insensitive) y estado, paginación 50, fila: nombre+código, estado, asset asignado actual (`currentAssignment.asset`), risk score, teléfono móvil primario, visto. Frontend: `pages/drivers/index.tsx` + `DriversTable`/`DriverStatusBadge` (patrón assets), tipos en `types/drivers.ts`, link del sidebar "Conductores" activado, layout Ops en `app.tsx`. Tests: `DriversPageTest` (6: guest redirect, 403 sin permiso, `assertInertia` con row shape completo, aislamiento de tenant, filtro de estado, búsqueda). `drivers/{id}` queda para F4b.

- [x] **B1a — Policies de Tenancy faltantes** — 2026-06-10, commit `feat(tenancy): policies de Subscription/TenantBranding/TenantFeature (B1a)`. `SubscriptionPolicy` (viewAny/view → `tenancy.billing.view`, update → `tenancy.billing.manage`), `TenantBrandingPolicy` y `TenantFeaturePolicy` (view/update → `tenancy.manage`), patrón `DriverPolicy` con `AuthorizeAction` + chequeo de `team_id`, registradas con `Gate::policy` en `TenancyServiceProvider::boot`. Tests: `TenancyPoliciesTest` (11 casos: con/sin permiso, cross-team denegado para los 3 modelos, usuario sin current team). Sin controllers (B1b pendiente de diseño de UI).

- [x] **B6-P4 — GPS fresco en eventos críticos** — 2026-06-10, commit `feat(context): GPS fresco para eventos críticos (B6-P4)`. `ProviderAdapter::fetchLiveLocation` (Samsara `GET /fleet/vehicles/locations?vehicleIds={id}` con timeout 3s configurable, Null adapter y manager delegando), acción `FetchLiveLocationForEvent` en el pipeline de `BuildEventContext`: solo critical, solo sin GPS inline, solo si `latestLocation` supera `context.live_location_staleness_seconds` (TenantSetting, default 60); éxito → `location_snapshot_json.source='live_fetch'` + `AssetLocationSnapshot` persistido; fallo → fallback con `position_stale` (activa `gps_signal_weak`). Geofences corren sobre la posición fresca. Tests: `SamsaraAdapterLiveLocationTest` (5) + `FetchLiveLocationForEventTest` (7: solo critical, staleness default y por TenantSetting, GPS inline, timeout con fallback, aislamiento de tenant).

- [x] **B6-P8 — Vínculo histórico de incidentes** — 2026-06-10, commit `feat(context): vínculo histórico de incidentes cerrados (B6-P8)`. `GetPriorSimilarIncidents` (cerrados del mismo asset/driver en 7 días, configurable `incidents.context_prior_lookback_days`, excluye incidentes ya vinculados al evento), enum `IncidentRelationType::PriorSimilarIncident`, links idempotentes vía `BuildEventContext`, filas en `incidents_snapshot_json` con `relation`/`closed_at`, signals `has_prior_similar_incident` (y `has_open_incident` sigue contando solo abiertos). Tests: `GetPriorSimilarIncidentsTest` (8 casos: asset/driver, ventana 7d, otro asset, otro tenant, no-terminal, auto-exclusión, idempotencia) + `SignalsBuilderTest` ampliado. Nota del mismo run: commit previo `fix(audit): evitar fatal de constante de trait UPDATED_AT en PHP < 8.5` reparó la base en PHP 8.3/8.4.

## Bloqueadas / requieren decisión

_(vacío — tareas `- [!]` con explicación de por qué se atoraron y qué decisión se necesita)_

## Descartadas (won't fix)

> La rutina NUNCA debe re-generar tareas equivalentes a estas.

- Integración Stripe / Cashier end-to-end — **cancelado por decisión de producto 2026-06-09**: el cobro es por transferencia bancaria; billing local-only.
- Instalar PHPStan/Larastan u otra dependencia nueva — prohibido tocar `composer.json`/`package.json` sin aprobación del usuario (CLAUDE.md §6).
- Migrar tests a Pest — el repo usa PHPUnit 12; no convertir.
- Modelo `Tenant` separado — `Team` ES el tenant.
- Segundo provider de integración (Geotab/Motive) — solo con demanda real, decisión del usuario.
- B6-P1 (ciclo de vida de `isResolved`) — **en curso por otro agente** (2026-06-09); no duplicar. Si al auditar ya está mergeado, tratarlo como completado, no regenerarlo.
