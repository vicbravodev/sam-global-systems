# ROADMAP — Rutina recurrente

> Cola de trabajo del agente recurrente (`claude/night-roadmap`, runs cada ~2 h). Reglas en [`CLAUDE.md`](CLAUDE.md) §8; prompt maestro en [`ROUTINE_PROMPT.md`](ROUTINE_PROMPT.md).
> **No confundir con [`docs/ROADMAP.md`](docs/ROADMAP.md)** (roadmap de producto): este archivo es la cola operativa; el de `docs/` es la fuente de prioridades de donde salen las tareas.
>
> Formato de tarea: `- [ ]` pendiente · `- [x]` completada (mover a "Completadas" con fecha y commit) · `- [!]` bloqueada (mover a "Bloqueadas" con explicación).
> Las tareas auto-generadas van en secciones `## Iteración v{N} — auto-generada {fecha}` (máx. v5, máx. 10 tareas auto-generadas por día sumando todos los runs).

---

## Iteración v1 — manual

- [ ] **B6-P8 — Vínculo histórico de incidentes** (spec: `docs/ROADMAP.md` §4 B6-P8, esfuerzo S, backend-only). `App\Domains\Context\...\GetRelatedOpenIncidents` solo mira incidentes abiertos en ventana de 30 min; añadir lookup de incidentes **cerrados** del mismo asset/driver en los últimos 7 días y vincularlos como `EventRelatedIncidentLink` con tipo nuevo `PriorSimilarIncident` (enum). El vínculo entra al snapshot de contexto (la IA ve "tercer panic de este camión esta semana"). Sin merge automático — solo visibilidad. Tests: vínculo creado para incidente cerrado <7d del mismo asset; no vincula >7d ni de otro asset; aislamiento de tenant; idempotencia al re-enriquecer.

- [ ] **B6-P4 — GPS fresco en eventos críticos** (spec: `docs/ROADMAP.md` §4 B6-P4, esfuerzo S, backend-only). Extender `ProviderAdapter` con `fetchLiveLocation(TenantIntegration $integration, string $externalAssetId): ?array` e implementarlo en `SamsaraAdapter` (`GET /fleet/vehicles/locations?vehicleIds={id}`, patrón de `fetchAssetLocations`). En `EnrichContextJob`, **solo severidad critical**: si `latestLocation` es más vieja que `context.live_location_staleness_seconds` (TenantSetting, default 60), fetch en vivo con timeout corto; éxito → `location_snapshot_json.source='live_fetch'` + persistir como `AssetLocation`; fallo → fallback silencioso a `latestLocation` con flag `position_stale`. Geofence matching corre sobre la posición fresca. Tests: fetch solo en critical; respeta staleness; fallback ante timeout sin romper el pipeline (Http::fake); tenant isolation.

- [ ] **B1a — Policies de Tenancy faltantes** (spec 01 §9; gap listado en `CLAUDE.md` §3 y `docs/ROADMAP.md` §4 B1). Crear `SubscriptionPolicy`, `TenantBrandingPolicy` y `TenantFeaturePolicy` en `app/Domains/Tenancy/Policies/`, siguiendo el patrón de `DriverPolicy`/`RolePolicy` (permisos vía dominio Access), y registrarlas en `TenancyServiceProvider`. Solo backend: las policies + tests unit/feature de cada habilidad (view/update) incluyendo el caso cross-team (miembro de otro team ⇒ denegado). NO crear `BillingController`/`BrandingController` aún (eso es B1b, requiere diseño de UI).

- [ ] **F4a — Página `drivers/index`** (spec: `docs/ROADMAP.md` §3 F4, primera mitad). Lista de conductores siguiendo el patrón de referencia de `integrations/index` (PR #31) y de `assets/index` (PR #53): controller web dedicado en `routes/web.php` (grupo web, sesión + CSRF — nunca `/api` para el navegador), props Inertia tipadas, Wayfinder, `DriverPolicy` (ya existe y cubre los 6 endpoints) aplicada, columnas: nombre, estado, asset asignado actual, risk score, teléfono. Página React en `resources/js/pages/drivers/index.tsx` reutilizando los componentes de tabla/badges de assets. Tests: feature test del controller con `assertInertia` (component + props), authz (sin permiso ⇒ 403), aislamiento de tenant. El detalle `drivers/{id}` queda para otra tarea (F4b) — no lo incluyas aquí.

## Completadas

_(vacío — la rutina mueve aquí las `- [x]` con fecha y hash de commit)_

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
