# ROADMAP — Rutina recurrente

> Cola de trabajo del agente recurrente (`claude/night-roadmap`, runs cada ~2 h). Reglas en [`CLAUDE.md`](CLAUDE.md) §8; prompt maestro en [`ROUTINE_PROMPT.md`](ROUTINE_PROMPT.md).
> **No confundir con [`docs/ROADMAP.md`](docs/ROADMAP.md)** (roadmap de producto): este archivo es la cola operativa; el de `docs/` es la fuente de prioridades de donde salen las tareas.
>
> Formato de tarea: `- [ ]` pendiente · `- [x]` completada (mover a "Completadas" con fecha y commit) · `- [!]` bloqueada (mover a "Bloqueadas" con explicación).
> Las tareas auto-generadas van en secciones `## Iteración v{N} — auto-generada {fecha}` (máx. v5, máx. 10 tareas auto-generadas por día sumando todos los runs).

---

## Iteración v1 — manual

- [ ] **B1a — Policies de Tenancy faltantes** (spec 01 §9; gap listado en `CLAUDE.md` §3 y `docs/ROADMAP.md` §4 B1). Crear `SubscriptionPolicy`, `TenantBrandingPolicy` y `TenantFeaturePolicy` en `app/Domains/Tenancy/Policies/`, siguiendo el patrón de `DriverPolicy`/`RolePolicy` (permisos vía dominio Access), y registrarlas en `TenancyServiceProvider`. Solo backend: las policies + tests unit/feature de cada habilidad (view/update) incluyendo el caso cross-team (miembro de otro team ⇒ denegado). NO crear `BillingController`/`BrandingController` aún (eso es B1b, requiere diseño de UI).

- [ ] **F4a — Página `drivers/index`** (spec: `docs/ROADMAP.md` §3 F4, primera mitad). Lista de conductores siguiendo el patrón de referencia de `integrations/index` (PR #31) y de `assets/index` (PR #53): controller web dedicado en `routes/web.php` (grupo web, sesión + CSRF — nunca `/api` para el navegador), props Inertia tipadas, Wayfinder, `DriverPolicy` (ya existe y cubre los 6 endpoints) aplicada, columnas: nombre, estado, asset asignado actual, risk score, teléfono. Página React en `resources/js/pages/drivers/index.tsx` reutilizando los componentes de tabla/badges de assets. Tests: feature test del controller con `assertInertia` (component + props), authz (sin permiso ⇒ 403), aislamiento de tenant. El detalle `drivers/{id}` queda para otra tarea (F4b) — no lo incluyas aquí.

## Completadas

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
