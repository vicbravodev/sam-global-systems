# ROADMAP — Rutina recurrente

> Cola de trabajo del agente recurrente (`claude/night-roadmap`, runs cada ~2 h). Reglas en [`CLAUDE.md`](CLAUDE.md) §8; prompt maestro en [`ROUTINE_PROMPT.md`](ROUTINE_PROMPT.md).
> **No confundir con [`docs/ROADMAP.md`](docs/ROADMAP.md)** (roadmap de producto): este archivo es la cola operativa; el de `docs/` es la fuente de prioridades de donde salen las tareas.
>
> Formato de tarea: `- [ ]` pendiente · `- [x]` completada (mover a "Completadas" con fecha y commit) · `- [!]` bloqueada (mover a "Bloqueadas" con explicación).
> Las tareas auto-generadas van en secciones `## Iteración v{N} — auto-generada {fecha}` (máx. v5, máx. 10 tareas auto-generadas por día sumando todos los runs).

---

## Iteración v1 — manual

## Completadas

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
