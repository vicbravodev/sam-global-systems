# CLAUDE.md — SAM Global Systems

Guía operativa para Claude Code. El documento autoritativo de reglas Laravel sigue siendo [`AGENTS.md`](AGENTS.md) (Laravel Boost); este archivo NO lo reemplaza, lo complementa con el contexto específico del producto SAM y el estado actual de implementación.

**Lee primero, en este orden, antes de cualquier cambio no trivial:**

1. [`AGENTS.md`](AGENTS.md) — convenciones Laravel/Inertia/React de este repo.
2. [`specs/00-MASTER-GUIDE.md`](specs/00-MASTER-GUIDE.md) — arquitectura domain-modular, stack, convenciones de migrations/tests, topología de colas.
3. El spec concreto del dominio donde vas a trabajar (`specs/NN-*.md`).
4. El módulo ya implementado como plantilla de estilo (ver tabla de estado abajo).

---

## 1. Qué es SAM

Plataforma multi-tenant de flotas: ingesta webhooks/eventos de proveedores externos (Samsara, etc.), los normaliza, los enriquece con contexto operacional, los evalúa con IA, y genera incidentes + automatizaciones. Billing metered por Stripe con eventos de uso por tenant.

**Stack:** Laravel 13 · PHP 8.5 · Inertia v3 · React 19 · Tailwind v4 · PostgreSQL 18 · Valkey (no Redis) · RustFS (S3-compatible) · Soketi (Pusher-compatible) · Horizon · Cashier Stripe · Mailpit (dev).

**Tenant = Team.** `app/Models/Team.php` ES el tenant. No existe un modelo `Tenant` separado — no lo inventes.

---

## 2. Arquitectura domain-modular (NO saltarse)

Todo código de negocio nuevo vive bajo `app/Domains/{Dominio}/` con subdirs: `Actions/ Data/ Enums/ Events/ Jobs/ Listeners/ Models/ Policies/ Queries/ Services/ Support/`. Cada dominio registra un `ServiceProvider` en [`bootstrap/providers.php`](bootstrap/providers.php).

**Reglas duras:**

- Modelos tenant-scoped DEBEN usar el trait `App\Concerns\BelongsToTenant` (scope global + auto-set `team_id` en create). Ver [`app/Concerns/BelongsToTenant.php`](app/Concerns/BelongsToTenant.php).
- Cada tabla tenant-scoped incluye `foreignId('team_id')->constrained()->cascadeOnDelete()` + `index('team_id')`.
- Helper global `currentTeam()` (ya autoloaded vía [`app/Support/helpers.php`](app/Support/helpers.php)) — úsalo; no llames `auth()->user()->currentTeam` ad-hoc.
- Contratos cross-domain en `app/Contracts/` con implementaciones en `app/Infrastructure/` o en el dominio dueño. Mira cómo `Integrations` bindea `NullRawEventIngestion` / `NullAssetSyncHandler` / `NullDriverSyncHandler` con `singletonIf` para romper dependencias circulares.
- Jobs van a colas con nombre por dominio (`ingestion`, `normalization`, `ai-evaluation`, `billing`, `sync`, etc.). Ver [`config/horizon.php`](config/horizon.php).
- Uso metered: todo punto facturable llama `App\Domains\Tenancy\Actions\RecordUsageEvent` con `event_key` idempotente.
- Rutas API tenant-scoped viven bajo `/{current_team}/...` con middleware `EnsureTeamMembership` (ver [`routes/api.php`](routes/api.php)).

**Convenciones de nombres (del Master Guide §8):** Modelos singular PascalCase, tablas plural snake_case, columnas JSON sufijadas `_json`, actions `Verbo+Sustantivo`, jobs `...Job`, eventos en pasado, broadcasting events con sufijo `Broadcast` solo si hay que desambiguar.

---

## 3. Estado de implementación (auditoría al 2026-04-22)

**255 tests passing · 690 assertions · corrida local ~3s.** Specs 01–07 implementados; 08–16 pendientes. Huecos críticos de I1/I2/I3/05 cerrados (ver §3.1).

| Spec | Dominio | Estado | Tests | Notas de auditoría |
|------|---------|--------|-------|--------------------|
| I1 | Storage (RustFS) | ✅ Cerrado | `tests/Feature/Infrastructure/Storage/RustFsObjectStorageTest.php`, `tests/Feature/Domains/Tenancy/FileObjectTest.php` | Contract `ObjectStorage` alineado al spec I1 §3 (incluye `temporaryUrl()`, `mimeType()`, firmas void). Modelo `FileObject` + migración `file_objects` + factory listos en `app/Domains/Tenancy/Models/FileObject.php`. Pendiente: validación manual de `temporaryUrl` contra RustFS real (fuera de CI). |
| I2 | Broadcasting (Soketi) | ✅ Parcialmente cerrado | `tests/Feature/Broadcasting/ChannelAuthorizationTest.php` | Canales `accounts.{teamId}`, `jobs.{jobId}`, `users.{userId}` registrados en [`routes/channels.php`](routes/channels.php). **Pendiente (no crítico):** `presence-incidents.{incidentId}` depende de spec 11 Incidents (no implementado). Tests corren contra PusherBroadcaster con fake creds. |
| I3 | Valkey/KV | ✅ Parcialmente cerrado | `tests/Feature/Http/Webhooks/WebhookRateLimitTest.php`, `tests/Feature/Http/Api/ApiRateLimitTest.php` | Rate limiters `webhooks` (300/min por IP) y `api` (60/min por tenant) definidos en `FortifyServiceProvider::configureRateLimiting()`. Aplicados en [`routes/api.php`](routes/api.php) vía `throttle:webhooks` y `throttle:api`. **Diferido (YAGNI):** contrato `KeyValueStore` — `Cache::` ya abstrae Valkey. |
| 01 | Tenancy | ✅ COMPLETADO | `tests/Feature/Domains/Tenancy/*` (8 archivos) | Modelos: Plan, Subscription, TenantFeature, TenantBranding, UsageMeter, UsageEvent, UsageDailyAggregate, TenantUsageCounter, BillingRate, InvoiceSnapshot, **FileObject (nuevo, cross-domain)**. Jobs: Aggregate/ReportUsage/GenerateInvoice. Scheduler `AggregateUsageJob` diario 02:00 en [`routes/console.php`](routes/console.php). Policies diferidas (no hay controllers para Subscription/TenantBranding todavía). |
| 02 | Access | ✅ COMPLETADO | `tests/Feature/Domains/Access/*` (5 archivos) | Role/Permission/UserPreference + pivot. Actions: AssignRole, AuthorizeAction, SyncRolePermissions. `InertiaPermissionsTest` cubre share con frontend. |
| 03 | Integrations | ✅ COMPLETADO | `tests/Feature/Domains/Integrations/*` (7 archivos) | 6 modelos, adapter pattern (`ProviderAdapter` + `NullProviderAdapter`), webhook handler público **con throttle `300/min`** en `/webhooks/{endpoint_url}`, policy registrada en provider. |
| 04 | Assets | ✅ COMPLETADO | `tests/Feature/Domains/Assets/*` (8 archivos) | AssetType/Asset/Device/ExternalRef/Location+Telemetry snapshots. Command `assets:record-usage-meters` scheduled daily vía `AssetsServiceProvider::boot()`. Broadcasting events `AssetLocationUpdatedBroadcast` y `AssetStatusChangedBroadcast` emitidos. API CRUD bajo `/{current_team}/assets`. |
| 05 | Drivers | ✅ COMPLETADO | `tests/Feature/Domains/Drivers/*` (10 archivos) | Driver + Assignments + Contacts + Documents + RiskProfile + StatusLog. **`DriverPolicy` añadida** (`viewAny`, `view`, `updateContacts`, `updateDocuments`) registrada en `DriversServiceProvider::boot()`. `DriverController` invoca `$this->authorize(...)` en los 6 endpoints. |
| 06 | Ingestion | ✅ COMPLETADO | `tests/Feature/Domains/Ingestion/*` (7 archivos) | RawEvent + EventSource + Dedup + Attachments. Servicio `RawEventIngestionService` bindea contract. Job `PollExternalProviderJob` en cola `ingestion`. |
| 07 | Normalization | ✅ COMPLETADO | `tests/Feature/Domains/Normalization/*` (5 archivos) | EventCategory/Severity/Type/MappingRule + NormalizedEvent. Seeder `NormalizationSeeder` carga catálogo base. |
| 08 | Context (core) | ✅ PARCIAL (PR #1) | `tests/Feature/Domains/Context/*` (7 archivos) + `tests/Unit/Domains/Context/Support/*` (3 archivos) | Snapshots, geofences, perfil operacional, `EnrichContextJob` en cola `context`. Escucha `EventNormalized` y construye `EventContextSnapshot` + `GeofenceMatch[]` + `EventRecentHistorySnapshot` + `OperationalContextProfile`. Idempotente vía `context_version`. `GetRelatedOpenIncidents` es stub (`SPEC-11-DEFERRED`). **Pipeline de media y `EventRelatedIncidentLink` diferidos** (PR #2 y spec 11). |
| 09 | AI (core pipeline) | ✅ PARCIAL (PR #1) | `tests/Feature/Domains/AI/*` (8 archivos, 19 tests) | Pipeline `EvaluateEventWithAI` (rules → heuristics → ai_text → fusión → explicación → acciones). 7 tablas (`ai_event_evaluations`, `ai_model_versions`, `ai_inference_logs`, `ai_decision_signals`, `ai_recommended_actions`, `ai_explanations`, `ai_reevaluation_requests`). `EvaluateEventJob` en cola `ai-evaluation` con `ShouldBeUnique`. Listener `EvaluateEventOnEventNormalized` engancha `EventNormalized`. `BroadcastAIEvaluationCompleted` emite en `private-accounts.{teamId}`. Usage metering para `ai_calls`/`ai_tokens_in`/`ai_tokens_out`. `AIEvaluationPolicy` + controller + 3 rutas API. **SDK real de Laravel AI diferido** (PR #2) — contrato `EventEvaluationAgent` + `NullEventEvaluationAgent` determinístico. **Multimodal diferido** (depende de spec 08 PR #2). `TenantAIProfile` usa defaults in-memory (SPEC-16-DEFERRED). |
| 10–16 | Decisions / Incidents / Automation / Notifications / Audit / Analytics / TenantConfig | ❌ Pendiente | — | Directorios `app/Domains/{Decisions,Incidents,Automation,Notifications,Audit,Analytics,TenantConfig}` aún no creados. |

### 3.1 Huecos críticos cerrados (2026-04-22)

| Hueco | Cómo se cerró | Archivos clave |
|-------|----------------|----------------|
| `ObjectStorage` contract incompleto (I1 §3) | Añadidos `temporaryUrl()` y `mimeType()`; firmas alineadas al spec (`put/delete` → void, `size` → `?int`, `put` recibe `array $options`) | [`app/Contracts/ObjectStorage.php`](app/Contracts/ObjectStorage.php), [`app/Infrastructure/Storage/RustFsObjectStorage.php`](app/Infrastructure/Storage/RustFsObjectStorage.php), [`app/Contracts/NullImplementations/NullObjectStorage.php`](app/Contracts/NullImplementations/NullObjectStorage.php) |
| Falta tabla `file_objects` + modelo `FileObject` (I1 §6) | Migración + modelo `Tenancy\Models\FileObject` con `BelongsToTenant` + morphTo `fileable` + factory | `database/migrations/2026_04_22_220256_create_file_objects_table.php`, [`app/Domains/Tenancy/Models/FileObject.php`](app/Domains/Tenancy/Models/FileObject.php), `database/factories/Domains/Tenancy/FileObjectFactory.php` |
| Webhook público sin throttle | `RateLimiter::for('webhooks', 300/min por IP)` + middleware `throttle:webhooks` en la ruta | [`app/Providers/FortifyServiceProvider.php`](app/Providers/FortifyServiceProvider.php), [`routes/api.php`](routes/api.php) |
| Rutas API tenant sin throttle | `RateLimiter::for('api', 60/min por current_team o IP)` + `throttle:api` en el grupo `{current_team}` | ídem anterior |
| Canal `private-users.{userId}` faltante (I2 §3) | Registrado callback `$user->id === $userId` | [`routes/channels.php`](routes/channels.php) |
| `DriverController` sin authorize (spec 05 §10) | `DriverPolicy` + `Gate::policy` + `$this->authorize(...)` en los 6 endpoints. Controller base ahora usa trait `AuthorizesRequests`. Tests existentes de Drivers siembran `AccessSeeder` en setUp. | [`app/Domains/Drivers/Policies/DriverPolicy.php`](app/Domains/Drivers/Policies/DriverPolicy.php), [`app/Domains/Drivers/DriversServiceProvider.php`](app/Domains/Drivers/DriversServiceProvider.php), [`app/Http/Controllers/Controller.php`](app/Http/Controllers/Controller.php), [`app/Http/Controllers/Drivers/DriverController.php`](app/Http/Controllers/Drivers/DriverController.php) |

**Diferidos explícitamente** (no-críticos, no bloquean spec 08):

- Policies Tenancy (Subscription/TenantBranding/TenantFeature) — no hay controllers para esos endpoints aún.
- Contrato `KeyValueStore` — YAGNI: Laravel `Cache::` ya abstrae Valkey sin añadir indirection extra.
- Canal `presence-incidents.{incidentId}` — depende del modelo `Incident` (spec 11, no implementado).
- Echo frontend wiring (`resources/js/echo.ts`) — fuera de scope backend-crítico.
- Authz real del canal `jobs.{jobId}` (hoy es `$user !== null`) — requiere el modelo Job que aún no se definió.
- **`SPEC-11-DEFERRED`** (spec 08 → spec 11): `GetRelatedOpenIncidents` retorna `collect()` vacía y `EventRelatedIncidentLink` no se creó. Cuando aterrice el dominio Incidents, buscar el marcador `SPEC-11-DEFERRED` en [`app/Domains/Context/Actions/GetRelatedOpenIncidents.php`](app/Domains/Context/Actions/GetRelatedOpenIncidents.php).
- **Spec 08 PR #2 (pipeline de media)**: `EventMediaAsset`, `ExtractEventMediaJob`, media enrichment. Dependerá de FileObject (I1) + integración Samsara media endpoints.
- **`SPEC-09-SDK-DEFERRED`** (spec 09 → PR #2): integración real de `Laravel\AI\Agent`, `ai_conversation_links`, streaming SSE, `AIEvaluationProgressBroadcast` en `private-jobs.{taskId}`. Actualmente el contrato `App\Contracts\AI\EventEvaluationAgent` se bindea a `NullEventEvaluationAgent` determinístico. Cuando aterrice el SDK, reemplazar el `singletonIf` en `AIServiceProvider::register()`.
- **`SPEC-09-MULTIMODAL-DEFERRED`** (spec 09 → depende de spec 08 PR #2): `ai_media_assessments`, `EvaluateEventMultimodally`, `EvaluateEventMediaJob`. No se creó la tabla ni el enum `EvaluationMode::Multimodal` se usa todavía.
- **`SPEC-16-DEFERRED`** (spec 09 → spec 16): tabla `tenant_ai_profiles`. El action `ResolveTenantAIProfile` devuelve defaults in-memory (`automation_level='semi'`, `monthly_token_limit=1_000_000`) hasta que spec 16 (TenantConfig) aterrice.

---

## 4. Flujo de trabajo en este repo

### Crear un dominio nuevo (specs 08+)

Seguir literalmente `specs/00-MASTER-GUIDE.md` §9 (22 pasos). Resumen operativo:

```bash
mkdir -p app/Domains/{Nombre}/{Actions,Data,Enums,Events,Jobs,Listeners,Models,Policies,Queries,Services,Support}
php artisan make:migration create_xxx_table --no-interaction
php artisan make:model --no-interaction     # luego mover a app/Domains/{Nombre}/Models
php artisan make:factory --no-interaction
php artisan make:job --no-interaction       # luego mover a app/Domains/{Nombre}/Jobs
php artisan make:event --no-interaction
php artisan make:test --phpunit --no-interaction {Nombre}Test
```

Después: crear `{Nombre}ServiceProvider`, registrarlo en [`bootstrap/providers.php`](bootstrap/providers.php), cablear `RecordUsageEvent` en todos los puntos facturables listados en la §12 del spec, y escribir tests que cubran aislamiento de tenant e idempotencia.

### Comandos que Claude Code debe correr

```bash
# Formato (obligatorio tras cambios PHP)
vendor/bin/pint --dirty --format agent

# Tests: filtrar siempre
php artisan test --compact --filter=NombreDelTest
php artisan test --compact tests/Feature/Domains/{Dominio}

# Suite completa (antes de dar por cerrado un módulo)
php artisan test --compact

# Dev stack (Sail)
composer run dev   # arranca serve + queue + pail + vite
./vendor/bin/sail up -d pgsql valkey rustfs soketi mailpit

# Migraciones
php artisan migrate
php artisan migrate:fresh --seed    # resetea + ejecuta DatabaseSeeder/AccessSeeder/AssetTypeSeeder/NormalizationSeeder

# Frontend
npm run dev        # vite watch
npm run build      # producción
npm run types:check && npm run lint:check && npm run format:check

# Wayfinder (tipados frontend)
php artisan wayfinder:generate    # regenerar tras cambiar rutas/controladores
```

### Tests — qué exigir siempre

- Un test por cada Action y cada Job crítico.
- Un `TenantIsolationTest` por dominio que verifique que `BelongsToTenant` scope aísla queries entre teams distintos.
- Tests de idempotencia en cualquier cosa que reciba `event_key` / signature / webhook (duplicates no deben crear side-effects).
- Usar factories; nunca `Model::create()` manual en tests.
- Para Storage: `Storage::fake('rustfs')`. Para eventos: `Event::fake([...Broadcast::class])`.

---

## 5. Puntos de integración críticos

- **Webhook público:** `POST /webhooks/{endpoint_url}` en [`routes/api.php:47`](routes/api.php:47) — sin middleware de tenant (el tenant se resuelve desde `WebhookEndpoint` en DB). Valida firma vía `ValidateWebhookSignature`. **Aún sin throttle nombrado.**
- **Broadcasting:** canales privados en [`routes/channels.php`](routes/channels.php). Todo event broadcast debe declarar `broadcastOn()` con el canal `private-accounts.{teamId}` cuando sea tenant-scoped. Ya existen `AssetLocationUpdatedBroadcast`, `AssetStatusChangedBroadcast`, `UsageUpdatedBroadcast`.
- **Horizon supervisors** ([`config/horizon.php`](config/horizon.php)): `supervisor-high` = ingestion/normalization/decisions/incidents · `-medium` = context/ai-evaluation/automation/notifications/billing/sync · `-low` = default/audit/analytics. Respeta esta topología al despachar jobs.
- **Bindings condicionales:** `IngestionServiceProvider` decide `RustFsObjectStorage` vs `NullObjectStorage` según `config('filesystems.disks.rustfs')`. Los `NullImplementations/` existen para permitir tests sin dependencias externas — úsalos.
- **Scheduler:** solo hay dos tareas programadas hoy — `AggregateUsageJob` diario 02:00 ([`routes/console.php`](routes/console.php)) y `assets:record-usage-meters` diario (vía `AssetsServiceProvider`). Las siguientes specs añadirán más.

---

## 6. Qué NO hacer

- No crear directorios nuevos a nivel `app/` sin aprobación (regla AGENTS.md).
- No cambiar dependencias de `composer.json` / `package.json` sin aprobación.
- No reemplazar modelos existentes (`User`, `Team`, `Membership`, `TeamInvitation`) — extender.
- No usar Redis Cluster — Horizon no lo soporta y Valkey corre standalone.
- No inventar un modelo `Tenant`: `Team` = tenant.
- No añadir `axios` de forma manual al frontend (Inertia v3 lo removió); usar `useHttp` / `useForm`.
- No mockear la base de datos en tests de feature — usar `RefreshDatabase` + factories reales.
- No crear docs en `docs/` ni `README` nuevos sin que el usuario lo pida explícitamente.
- No correr `vendor/bin/pint --test` — correr `vendor/bin/pint --dirty --format agent`.

### 6.1 Git, GitHub y commits (reglas duras)

- **Todos los commits se firman SOLO con la identidad del usuario** (`user.name = "Victor Jesus Bravo de la Peña"`, `user.email = "vicbravodev@gmail.com"`). **Nunca** añadir `Co-Authored-By: Claude ...` ni ningún otro coautor automático. No usar `--author`, `--trailer`, ni banners tipo "Generated with Claude Code" en el mensaje del commit. Todo lo que llegue al remoto debe salir a nombre del usuario.
- **Claude PUEDE**: crear commits locales, ramas locales, `git add`, `git status` / `git diff` / `git log`, `git push` a la rama de la PR (no a `main`), `gh pr create` (cuerpo del PR redactado por Claude pero autoría de los commits = usuario), `gh pr comment`, `gh pr checks`, `gh run view`, y en general cualquier acción de publicación sobre ramas de trabajo.
- **Claude NUNCA hace**:
  - `git push` a `main` / `master` (directo o por `push --force`). El merge a `main` lo hace el usuario desde la UI de GitHub al aprobar el PR.
  - `gh pr merge` de ningún PR. El merge es exclusivo del usuario.
  - `gh release create` / `gh release publish`.
  - `git push --force` o `--force-with-lease` sobre cualquier rama sin petición explícita del usuario en ese turno.
- **Ver CI es obligatorio antes de dar por cerrada una tarea con PR**: después de `git push` Claude espera al workflow (`gh run watch` / `gh pr checks --watch`) y reporta el resultado. Si CI falla por un cambio de Claude, Claude lo arregla y empuja un commit nuevo; no se da por terminada la tarea con CI rojo salvo que el usuario pida explícitamente ignorarlo.
- **Limpieza de worktrees post-merge es obligatoria**: una vez que un PR creado por Claude está mergeado a `main` (verificable con `gh pr view <num> --json mergedAt,state`), Claude DEBE eliminar el worktree asociado y su rama local desde el repo principal: `git worktree remove .claude/worktrees/<slug>` seguido de `git branch -d claude/<slug>`. Si Claude está corriendo dentro del worktree que se acaba de mergear, no puede borrarlo desde dentro — debe avisarlo y dejar el comando preparado para que el usuario lo ejecute (o esperar al siguiente turno fuera del worktree). Al iniciar una tarea nueva, Claude revisa con `git worktree list` y poda los que correspondan a PRs ya mergeados. No dejar worktrees mergeados acumulándose: cada uno consume cientos de MB y contamina `git branch -a`. Excepción al uso prohibido de `git branch -D`: si la rama está mergeada (`git branch --merged main` la lista) puede usar `git branch -d` (lowercase, seguro); sólo si git rechaza con `-d` por sospecha de pérdida y el usuario lo autoriza explícitamente, usar `-D`.
- **Excepción para tareas demostrativas**: si el usuario pide explícitamente en el turno actual publicar código que no pasa thresholds o checks (p.ej. para ver cómo se ve el reporte en GitHub), Claude puede hacer `git push` y `gh pr create` aunque el CI vaya a fallar, pero debe avisarlo en el mismo mensaje.
- **Claude NO usa** `--no-verify`, `--no-gpg-sign`, `git reset --hard`, `git checkout .`, `git clean -fd`, `git branch -D`, `git rebase -i`, ni amends a commits ya publicados, salvo petición explícita del usuario en ese turno.
- Si un hook de pre-commit o pre-push falla, Claude arregla la causa raíz y crea un commit NUEVO; no repite el commit con `--amend` ni salta el hook (salvo `SKIP_COVERAGE=1` en pushes demostrativos si el usuario lo autoriza).

---

## 7. Archivos de referencia rápida

| Propósito | Archivo |
|-----------|---------|
| Reglas Laravel Boost (autoritativo) | [`AGENTS.md`](AGENTS.md) |
| Arquitectura, convenciones, orden de specs | [`specs/00-MASTER-GUIDE.md`](specs/00-MASTER-GUIDE.md) |
| Specs por módulo (negocio) | [`specs/01-tenancy.md`](specs/01-tenancy.md) … [`specs/16-tenant-config.md`](specs/16-tenant-config.md) |
| Specs de infraestructura | [`specs/I1-storage-infrastructure.md`](specs/I1-storage-infrastructure.md), [`I2`](specs/I2-realtime-broadcasting.md), [`I3`](specs/I3-keyvalue-caching.md) |
| Docs de producto en español | [`docs/SAM/`](docs/SAM/) |
| Trait tenant-scope | [`app/Concerns/BelongsToTenant.php`](app/Concerns/BelongsToTenant.php) |
| Helper `currentTeam()` | [`app/Support/helpers.php`](app/Support/helpers.php) |
| Providers de dominios registrados | [`bootstrap/providers.php`](bootstrap/providers.php) |
| Configuración de colas | [`config/horizon.php`](config/horizon.php) |
| Rutas API tenant-scoped | [`routes/api.php`](routes/api.php) |
| Canales broadcasting | [`routes/channels.php`](routes/channels.php) |
| Docker services | [`compose.yaml`](compose.yaml) |
