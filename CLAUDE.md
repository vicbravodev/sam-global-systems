# CLAUDE.md — SAM Global Systems

Guía operativa para Claude Code. El documento autoritativo de reglas Laravel sigue siendo [`AGENTS.md`](AGENTS.md) (Laravel Boost); este archivo NO lo reemplaza, lo complementa con el contexto específico del producto SAM y el estado actual de implementación.

**Lee primero, en este orden, antes de cualquier cambio no trivial:**

1. [`AGENTS.md`](AGENTS.md) — convenciones Laravel/Inertia/React de este repo.
2. [`specs/00-MASTER-GUIDE.md`](specs/00-MASTER-GUIDE.md) — arquitectura domain-modular, stack, convenciones de migrations/tests, topología de colas.
3. El spec concreto del dominio donde vas a trabajar (`specs/NN-*.md`).
4. El módulo ya implementado como plantilla de estilo (ver tabla de estado abajo).

---

## 1. Qué es SAM

Plataforma multi-tenant de flotas: ingesta webhooks/eventos de proveedores externos (Samsara, etc.), los normaliza, los enriquece con contexto operacional, los evalúa con IA, y genera incidentes + automatizaciones. Billing metered **local** con eventos de uso por tenant: el cobro es por transferencia bancaria (decisión 2026-06-09, **Stripe cancelado** — no arrancar trabajo Stripe; el admin activa/desactiva tenants según pago/factura subida).

**Stack:** Laravel 13 · PHP 8.5 · Inertia v3 · React 19 · Tailwind v4 · PostgreSQL 18 · Valkey (no Redis) · RustFS (S3-compatible) · Soketi (Pusher-compatible) · Horizon · Mailpit (dev). (Cashier retirado 2026-06-10 — billing local por transferencia.)

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

## 3. Estado de implementación (auditoría al 2026-04-29)

**~624 tests passing · ~1480 assertions · corrida local ~17s.** Specs 01–16 e infra I1/I2/I3 implementados y mergeados. PR de cierre de gaps post-spec-16 cubre el wiring TenantConfig→consumidores, listener typing, y refresh de docs.

| Spec | Dominio | Estado | Tests | Notas |
|------|---------|--------|-------|-------|
| I1 | Storage (RustFS) | ✅ Cerrado | `tests/Feature/Infrastructure/Storage/`, `tests/Feature/Domains/Tenancy/FileObjectTest.php` | Contract `ObjectStorage` con `temporaryUrl()` / `mimeType()`. Modelo `FileObject` + migración + factory. |
| I2 | Broadcasting (Soketi) | ✅ Cerrado | `tests/Feature/Broadcasting/ChannelAuthorizationTest.php` | Canales `accounts.{teamId}`, `jobs.{jobId}`, `users.{userId}`, `incidents.{incidentId}` (presencia) registrados en [`routes/channels.php`](routes/channels.php). |
| I3 | Valkey/KV | ✅ Cerrado | `tests/Feature/Http/Webhooks/WebhookRateLimitTest.php`, `tests/Feature/Http/Api/ApiRateLimitTest.php` | Throttles `webhooks` (300/min por IP) y `api` (60/min por tenant). |
| 01 | Tenancy | ✅ COMPLETADO | `tests/Feature/Domains/Tenancy/*` | Plan/Subscription/TenantFeature/TenantBranding/UsageMeter/UsageEvent/UsageDailyAggregate/TenantUsageCounter/BillingRate/InvoiceSnapshot/**FileObject**. Scheduler `AggregateUsageJob`. |
| 02 | Access | ✅ COMPLETADO | `tests/Feature/Domains/Access/*` | Role/Permission/UserPreference + pivot. AssignRole / AuthorizeAction / SyncRolePermissions. |
| 03 | Integrations | ✅ COMPLETADO | `tests/Feature/Domains/Integrations/*` | Adapter pattern, webhook handler con throttle `300/min`, policy. |
| 04 | Assets | ✅ COMPLETADO | `tests/Feature/Domains/Assets/*` | AssetType/Asset/Device/ExternalRef/Location+Telemetry. Broadcasting `AssetLocationUpdatedBroadcast` + `AssetStatusChangedBroadcast`. |
| 05 | Drivers | ✅ COMPLETADO | `tests/Feature/Domains/Drivers/*` | Driver + Assignments + Contacts + Documents + RiskProfile + StatusLog. `DriverPolicy` aplicada en los 6 endpoints. |
| 06 | Ingestion | ✅ COMPLETADO | `tests/Feature/Domains/Ingestion/*` | RawEvent + EventSource + Dedup + Attachments. Job `PollExternalProviderJob` en cola `ingestion`. |
| 07 | Normalization | ✅ COMPLETADO | `tests/Feature/Domains/Normalization/*` | EventCategory/Severity/Type/MappingRule + NormalizedEvent. Seeder base. |
| 08 | Context | ✅ COMPLETADO | `tests/Feature/Domains/Context/*`, `tests/Unit/Domains/Context/Support/*` | Snapshots, geofences, perfil operacional, `EnrichContextJob`. PR #1 cerró el core; PR #2 añade el pipeline de media: `EventMediaContext` + `EventMediaRequest` + `EventRelatedIncidentLink`, `AttachImmediateEventMedia` + `RequestDeferredEventMedia`, `ExtractEventMediaJob` + `FetchDeferredEventMediaJob` (cola `context`), `EventMediaController` (`GET /events/{id}/media`, `POST /events/{id}/media/request`), `EventMediaContextPolicy`. Listener `ExtractMediaOnContextBuilt` dispara la extracción tras `EventContextBuilt`; `RefreshContextMediaSnapshot` proyecta la inventario en `media_snapshot_json` y bumpea `context_version`. |
| 09 | AI (core) | ✅ PR #1 | `tests/Feature/Domains/AI/*` | Pipeline rules → heuristics → ai_text → fusión → explicación → acciones. 7 tablas. `EvaluateEventJob` en `ai-evaluation`. Listener `EvaluateOnEventContextBuilt`. `TenantAIProfile` ahora resuelto vía spec 16. **SDK Laravel AI y multimodal diferidos a PR #2.** |
| 10 | Decisions | ✅ COMPLETADO | `tests/Feature/Domains/Decisions/*` | Decision/DecisionRule/EscalationPolicy/RuleSet/DecisionOutcome/DecisionTrace/DecisionOverride. Listener `RunDecisionEngineOnAIEvaluationCompleted`. Broadcasting `DecisionMade`. PR #8. |
| 11 | Incidents | ✅ COMPLETADO | `tests/Feature/Domains/Incidents/*` | Incident + Type/Status/Priority + Comment/Resolution/Evidence/Timeline + EventLink. Listener `CreateIncidentOnDecisionMade` (typed). Canal presencia `incidents.{incidentId}`. PR #13. |
| 12 | Automation | ✅ COMPLETADO | `tests/Feature/Domains/Automation/*` | AutomationWorkflow + WorkflowStep + ActionTemplate + ActionExecution + WorkflowExecution. Listeners `TriggerAutomationOnDecisionMade` / `OnIncidentCreated` / `OnIncidentEscalated` (typed). Broadcasting `ActionExecuted` / `ActionFailed`. PR #10. |
| 13 | Notifications | ✅ COMPLETADO | `tests/Feature/Domains/Notifications/*` | Notification + NotificationChannel + NotificationTemplate + NotificationPreference. Listeners `NotifyOnIncidentCreated` / `NotifyOnIncidentStatusChanged` / `NotifyOnActionExecuted` (typed). Drivers Email/Web reales; SMS/Push/Whatsapp/Slack/Webhook = `NullNotificationDriver`. PR #12. |
| 14 | Audit | ✅ COMPLETADO | `tests/Feature/Domains/Audit/*` | AuditLog/AuditCategory/AuditSeverity. PR #14. |
| 15 | Analytics | ✅ COMPLETADO | `tests/Feature/Domains/Analytics/*` | KpiRecord/AnalyticsSnapshot/ReportDefinition/ReportExecution. `BuildAnalyticsSnapshotJob`, `ExpireOldReports`. **Render PDF/XLSX diferido (`SPEC-15-PDF-DEFERRED`).** PR #9. |
| 16 | TenantConfig | ✅ COMPLETADO | `tests/Feature/Domains/TenantConfig/*` | TenantSetting/TenantRuleOverride/TenantNotificationPolicy/TenantAIProfile/TenantEscalationConfig/TenantScheduleProfile/TenantConfigVersion. Resolvers cubren `TenantConfig`/`TenantAIProfile`/`TenantNotificationPolicy` (singular y plural)/`TenantSchedule`/`TenantRuleOverride`/`TenantDecisionRules`/`TenantAutomationPolicies`/`TenantAnalyticsConfig`. PR #11 + post-spec-16 wiring. |

### 3.1 Huecos críticos cerrados

| Hueco | Cómo se cerró | Archivos clave |
|-------|----------------|----------------|
| TenantConfig (spec 16) bindeaba sólo 5 contratos; otros 4 quedaban en Null impls dispersas | `TenantConfigServiceProvider` ahora bindea `TenantDecisionRulesResolver`, `TenantAutomationPoliciesResolver`, `TenantNotificationPoliciesResolver`, `TenantAnalyticsConfig` a Actions reales en `app/Domains/TenantConfig/Actions/Resolve*`. Bindings Null borrados de Decisions/Automation/Notifications/Analytics; archivos Null orfanados eliminados. | [`app/Domains/TenantConfig/TenantConfigServiceProvider.php`](app/Domains/TenantConfig/TenantConfigServiceProvider.php), [`app/Domains/TenantConfig/Actions/`](app/Domains/TenantConfig/Actions/) |
| Listener fantasma `NotifyOnActionExecutionCompleted` apuntando a evento inexistente | Renombrado a `NotifyOnActionExecuted`, tipado contra `ActionExecuted`, registrado en `NotificationsServiceProvider`. | [`app/Domains/Notifications/Listeners/NotifyOnActionExecuted.php`](app/Domains/Notifications/Listeners/NotifyOnActionExecuted.php) |
| Listeners cross-domain registrados por FQCN-string como workaround (specs 10/11/12 ya existen) | Listeners tipados con clases reales (`DecisionMade`, `IncidentCreated`, `IncidentStatusChanged`, `IncidentClosed`, `ActionExecuted`); providers usan `Event::listen(Event::class, Listener::class)`; helpers de reflection borrados; tests usan eventos reales con factories. | `app/Domains/Automation/AutomationServiceProvider.php`, `app/Domains/Notifications/NotificationsServiceProvider.php`, `app/Domains/Incidents/IncidentsServiceProvider.php`, listeners bajo cada dominio. |
| Contracts y Null impls referenciaban `SPEC-XX-DEFERRED` para specs que ya shipearon | Comentarios reformulados; sin Null impls residuales en este eje. | `app/Contracts/Decisions/DecisionMetricsQuery.php`, `app/Contracts/Incidents/IncidentMetricsQuery.php`, `app/Contracts/Audit/AuditLogQuery.php`. |
| Decision / Incident / Audit metrics queries seguían bindeadas a Null | Cada dominio dueño expone una query DB-backed con scope `team_id` y filtros por ventana temporal: `DbDecisionMetricsQuery`, `DbIncidentMetricsQuery`, `DbAuditLogQuery`. Los bindings viven en cada `*ServiceProvider` del dominio dueño; Analytics ya no bindea contratos cross-domain. | [`app/Domains/Decisions/Queries/DbDecisionMetricsQuery.php`](app/Domains/Decisions/Queries/DbDecisionMetricsQuery.php), [`app/Domains/Incidents/Queries/DbIncidentMetricsQuery.php`](app/Domains/Incidents/Queries/DbIncidentMetricsQuery.php), [`app/Domains/Audit/Queries/DbAuditLogQuery.php`](app/Domains/Audit/Queries/DbAuditLogQuery.php) |
| `ObjectStorage` contract incompleto (I1 §3) | `temporaryUrl()` y `mimeType()` añadidos; firmas alineadas al spec. | [`app/Contracts/ObjectStorage.php`](app/Contracts/ObjectStorage.php), [`app/Infrastructure/Storage/RustFsObjectStorage.php`](app/Infrastructure/Storage/RustFsObjectStorage.php) |
| Webhook público sin throttle / API tenant sin throttle | `RateLimiter::for('webhooks'|'api', ...)` + `throttle:` middleware en las rutas. | [`app/Providers/FortifyServiceProvider.php`](app/Providers/FortifyServiceProvider.php), [`routes/api.php`](routes/api.php) |
| `DriverController` sin authorize (spec 05 §10) | `DriverPolicy` + `$this->authorize(...)` en los 6 endpoints. | [`app/Domains/Drivers/Policies/DriverPolicy.php`](app/Domains/Drivers/Policies/DriverPolicy.php), [`app/Http/Controllers/Drivers/DriverController.php`](app/Http/Controllers/Drivers/DriverController.php) |
| Spec 13 PR #2: drivers SMS/Push/Whatsapp/Slack/Webhook fuera (caían a `NullNotificationDriver`) | Implementados drivers reales: `Webhook` (HTTP+HMAC-SHA256), `Slack` (incoming webhook + Blocks), `Whatsapp`+`Sms` (Twilio via `TwilioMessenger` wrapper), `Push` (FCM via `FcmMessenger` + `FcmSendReport` DTO). El contrato `NotificationDriver::send()` recibe ahora el `NotificationChannel` para leer `config_json`. Cifrado at-rest de secrets vía `EncryptedChannelConfigCast`. Tabla `user_push_tokens` + modelo + relación con `User`. | [`app/Domains/Notifications/Channels/`](app/Domains/Notifications/Channels/), [`app/Domains/Notifications/Support/EncryptedChannelConfigCast.php`](app/Domains/Notifications/Support/EncryptedChannelConfigCast.php), [`app/Domains/Notifications/Models/UserPushToken.php`](app/Domains/Notifications/Models/UserPushToken.php), [`database/migrations/2026_05_07_120000_create_user_push_tokens_table.php`](database/migrations/2026_05_07_120000_create_user_push_tokens_table.php) |

**Diferidos — TODOS CERRADOS (PRs #18–#24, verificado en código el 2026-06-09):**

- ~~`SPEC-09-SDK-DEFERRED`~~ — cerrado: `laravel/ai ^0.6.7` instalado; `SdkEventEvaluationAgent` y `SdkMediaAssessmentAgent` bindeados condicionalmente en [`app/Domains/AI/AIServiceProvider.php`](app/Domains/AI/AIServiceProvider.php) (fallback a `Null*` solo si el SDK no está configurado).
- ~~`SPEC-09-MULTIMODAL-DEFERRED`~~ — cerrado: `ai_media_assessments` + `EvaluateEventMediaJob` shippeados.
- ~~`SPEC-15-PDF-DEFERRED`~~ — cerrado: render real PDF (DomPDF) y XLSX en [`app/Domains/Analytics/Actions/GenerateReport.php`](app/Domains/Analytics/Actions/GenerateReport.php).
- ~~Authz `jobs.{jobId}`~~ — cerrado: modelo `Job` de Tenancy + verificación de membership en [`routes/channels.php`](routes/channels.php).
- ~~Echo frontend wiring~~ — cerrado: `resources/js/echo.ts` + hooks `use-team-broadcasts`/`use-echo-channel`; la bandeja de incidentes ya consume realtime.
- **Policies Tenancy** (Subscription/TenantBranding/TenantFeature) — único pendiente real; crearlas junto con `BillingController`/`BrandingController` (spec 01 §9, aún no existen).
- **Contrato `KeyValueStore`** — YAGNI confirmado: Laravel `Cache::` ya abstrae Valkey.

**Roadmap vivo de next steps (frontend + backend): [`docs/ROADMAP.md`](docs/ROADMAP.md).** Ante discrepancia entre esta tabla de estado y el código, manda el código.

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

- **Webhook público:** `POST /webhooks/{endpoint_url}` con `throttle:webhooks` (300/min por IP). El tenant se resuelve desde `WebhookEndpoint` en DB; firma vía `ValidateWebhookSignature`.
- **Broadcasting:** canales privados en [`routes/channels.php`](routes/channels.php) — `accounts.{teamId}`, `users.{userId}`, `jobs.{jobId}`, presencia `incidents.{incidentId}`. Todo event broadcast debe declarar `broadcastOn()` con `private-accounts.{teamId}` cuando sea tenant-scoped. Existen `AssetLocationUpdatedBroadcast`, `AssetStatusChangedBroadcast`, `UsageUpdatedBroadcast`, `AIEvaluationCompletedBroadcast`, `DecisionMadeBroadcast`, `ActionExecutedBroadcast`/`ActionFailedBroadcast`.
- **Horizon supervisors** ([`config/horizon.php`](config/horizon.php)): `supervisor-high` = ingestion/normalization/decisions/incidents · `-medium` = context/ai-evaluation/automation/notifications/billing/sync · `-low` = default/audit/analytics.
- **Bindings condicionales:** `IngestionServiceProvider` decide `RustFsObjectStorage` vs `NullObjectStorage` según `config('filesystems.disks.rustfs')`. Los `NullImplementations/` existen para tests y para contratos cuyo dueño aún no shippea una implementación DB-backed — úsalos cuando no haya alternativa real.
- **TenantConfig como dueño de resolvers:** `TenantConfigServiceProvider` bindea TODOS los `TenantConfig` contracts. Los dominios consumidores (Decisions/Automation/Notifications/Analytics) no deben bindear sus propios `Null...Resolver` — TenantConfig es la única fuente de verdad.
- **Scheduler:** `AggregateUsageJob` diario 02:00 ([`routes/console.php`](routes/console.php)) y `assets:record-usage-meters` diario (`AssetsServiceProvider`). Specs 14/15 añaden tareas adicionales (audit retention, analytics snapshots).

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
- **Claude PUEDE**: crear commits locales, ramas locales, `git add`, `git status` / `git diff` / `git log`, `git push` a la rama de la PR (no directo a `main`), `gh pr create` (cuerpo del PR redactado por Claude pero autoría de los commits = usuario), `gh pr comment`, `gh pr checks`, `gh run view`, y en general cualquier acción de publicación sobre ramas de trabajo.
- **Claude PUEDE mergear PRs a `main` (`gh pr merge`), pero SIEMPRE pidiendo autorización explícita primero**: antes de mergear, Claude lo anuncia y espera el OK del usuario en ese turno. Con la autorización dada, Claude ejecuta el merge (prefiere `--merge`; usa `--admin` sólo si el usuario lo pide). Si la rama no está al día con `main`, Claude la actualiza con un merge de `main` dentro de la rama (sin `--force`), reespera CI verde, y entonces mergea. El usuario puede otorgar una autorización amplia ("mergea tú los PRs cuando CI esté verde") que aplica hasta que la revoque; sin esa autorización amplia, se pide caso por caso. **Tras CADA merge a `main` (autorizado caso a caso o por autorización amplia), Claude SIEMPRE actualiza el `main` local del checkout principal acto seguido**: `git -C <checkout-principal> checkout main && git pull --ff-only origin main`. No se da por cerrado el merge sin dejar el `main` local sincronizado con el remoto.
- **Claude NUNCA hace**:
  - `git push` directo a `main` / `master` (directo o por `push --force`). Los cambios entran a `main` vía PR mergeado, no por push directo.
  - `gh pr merge` SIN autorización del usuario (ver punto anterior). El merge requiere OK explícito; sin él, no se mergea.
  - `gh release create` / `gh release publish`.
  - `git push --force` o `--force-with-lease` sobre cualquier rama sin petición explícita del usuario en ese turno.
- **Ver CI es obligatorio antes de dar por cerrada una tarea con PR**: después de `git push` Claude espera al workflow (`gh run watch` / `gh pr checks --watch`) y reporta el resultado. Si CI falla por un cambio de Claude, Claude lo arregla y empuja un commit nuevo; no se da por terminada la tarea con CI rojo salvo que el usuario pida explícitamente ignorarlo.
- **Limpieza de worktrees post-merge es obligatoria**: una vez que un PR creado por Claude está mergeado a `main` (verificable con `gh pr view <num> --json mergedAt,state`), Claude DEBE eliminar el worktree asociado y su rama local desde el repo principal: `git worktree remove .claude/worktrees/<slug>` seguido de `git branch -d claude/<slug>`. Si Claude está corriendo dentro del worktree que se acaba de mergear, no puede borrarlo desde dentro — debe avisarlo y dejar el comando preparado para que el usuario lo ejecute (o esperar al siguiente turno fuera del worktree). Al iniciar una tarea nueva, Claude revisa con `git worktree list` y poda los que correspondan a PRs ya mergeados. No dejar worktrees mergeados acumulándose: cada uno consume cientos de MB y contamina `git branch -a`. Excepción al uso prohibido de `git branch -D`: si la rama está mergeada (`git branch --merged main` la lista) puede usar `git branch -d` (lowercase, seguro); sólo si git rechaza con `-d` por sospecha de pérdida y el usuario lo autoriza explícitamente, usar `-D`.
- **Excepción para tareas demostrativas**: si el usuario pide explícitamente en el turno actual publicar código que no pasa thresholds o checks (p.ej. para ver cómo se ve el reporte en GitHub), Claude puede hacer `git push` y `gh pr create` aunque el CI vaya a fallar, pero debe avisarlo en el mismo mensaje.
- **Claude NO usa** `--no-verify`, `--no-gpg-sign`, `git reset --hard`, `git checkout .`, `git clean -fd`, `git branch -D`, `git rebase -i`, ni amends a commits ya publicados, salvo petición explícita del usuario en ese turno.
- Si un hook de pre-commit o pre-push falla, Claude arregla la causa raíz y crea un commit NUEVO; no repite el commit con `--amend` ni salta el hook (salvo `SKIP_COVERAGE=1` en pushes demostrativos si el usuario lo autoriza).

### 6.2 Flujo de PRs y calidad

El repo vive en GitHub bajo `vicbravodev/sam-global-systems`. La rama `main` está protegida por un **ruleset activo** — no se puede `push` directo, todo entra por PR con CI en verde. Las reglas de *quién* puede pushear/crear/mergear PRs son las de §6.1 (manda §6.1 ante cualquier duda); esta sección cubre el naming, el gate de calidad y el ruleset.

**Naming de ramas** — un slug por rama, siempre desde `main` actualizada:

| Tipo de cambio | Prefijo | Ejemplos |
|---|---|---|
| Feature (spec nuevo o endpoint) | `feat/` | `feat/spec-08-context`, `feat/incidents-api` |
| Bug fix | `fix/` | `fix/driver-policy-viewany`, `fix/tenant-isolation-leak` |
| Refactor sin cambio de comportamiento | `refactor/` | `refactor/extract-rate-limiter` |
| Chore / housekeeping | `chore/` | `chore/bump-pint`, `chore/drop-dead-code` |
| Infra / pipelines | `ci/` | `ci/cache-node-modules`, `ci/add-coverage` |
| Docs y specs | `docs/` | `docs/update-spec-05` |
| Tests-only | `test/` | `test/ingestion-idempotency` |

No usar nombres genéricos (`updates`, `patch`, `temp`). Una rama = un cambio cohesivo. (Las ramas autogeneradas `claude/...` y la rutina `claude/night-roadmap` son la excepción operativa.)

**Formato de commits** (conventional-ish, como el historial): `type: subject corto en minúsculas`, cuerpo opcional que explica el *por qué* y referencia specs tocados (`spec 05 §10`). `type` ∈ `feat | fix | chore | refactor | ci | docs | test | perf | style`. Un commit = un cambio atómico. Autoría e identidad: ver §6.1 (sin `Co-Authored-By`, sin `--amend`/`--no-verify`).

**Gate local antes de push (OBLIGATORIO)** — dejar los 4 en verde; si local pasa, CI pasa:

```bash
vendor/bin/pint --dirty --format agent              # PHP style — solo archivos modificados
php artisan test --compact                          # Suite PHPUnit completa
npm run lint:check && npm run format:check          # ESLint + Prettier
npm run types:check                                 # tsc --noEmit
```

Atajo equivalente: `composer ci:check` (ver [`composer.json`](composer.json) scripts). **Nota tipos generados**: `npm run types:check` depende de `resources/js/{routes,actions,wayfinder}` generados por el plugin Vite de Wayfinder; si faltan (repo recién clonado o tras `php artisan route:clear`), correr `npm run build` una vez antes del type-check.

**Ruleset activo en `main`** — lo que la PR debe satisfacer antes de que GitHub permita el merge:

1. **2 status checks verdes**: `Lint & Format` y `PHPUnit` (jobs de [`.github/workflows/ci.yml`](.github/workflows/ci.yml)).
2. **Rama del PR actualizada con `main`** (`strict` policy — si `main` avanzó, mergear `main` dentro de la rama, sin `--force`; ver §6.1).
3. **Hilos de conversación del review resueltos.**
4. **No force-push ni deletion de `main`** (bloqueados siempre).
5. **0 aprobaciones requeridas** (solo-dev), pero se pueden pedir si participa alguien más.
6. **Bypass**: solo el owner (`vicbravodev`). Claude NUNCA hace bypass ni lo sugiere.

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

---

## 8. Rutina recurrente (`claude/night-roadmap`)

Reglas para el agente programado (cloud) que corre en runs recurrentes (~cada 2 h) trabajando el [`ROADMAP.md`](ROADMAP.md) de la raíz (cola de tareas de la rutina — NO confundir con [`docs/ROADMAP.md`](docs/ROADMAP.md), que es el roadmap de producto y sigue mandando como fuente de prioridades). El prompt maestro vive en [`ROUTINE_PROMPT.md`](ROUTINE_PROMPT.md); el entorno se prepara con [`.claude/setup.sh`](.claude/setup.sh).

### 8.1 Stack (detectado, no asumir otro)

Laravel 13 · PHP 8.5 · **PHPUnit 12 (NO Pest)** · Pint (preset `laravel`) · **PHPStan NO instalado** (no instalarlo: la regla §6 prohíbe tocar `composer.json`) · Inertia v3 + **React 19** (no Vue) + TypeScript · Tailwind v4 · Vite 8 + Wayfinder · Tests con **sqlite `:memory:`** (configurado en `phpunit.xml`; no requieren Postgres ni Valkey).

### 8.2 Comandos canónicos (los ÚNICOS válidos)

| Acción | Comando |
|--------|---------|
| Formatear PHP (tras cada cambio) | `vendor/bin/pint --dirty --format agent` |
| Verificar estilo PHP (gate final, como CI) | `vendor/bin/pint --test` *(solo verificación de cierre; para arreglar, usar el de arriba)* |
| Tests (filtrado, durante desarrollo) | `php artisan test --compact --filter=NombreDelTest` |
| Tests (suite completa, gate de cierre) | `php artisan test --compact` |
| Cobertura (umbral local 75/80/95) | `php artisan test --coverage-clover=coverage.xml --compact && php scripts/check-coverage.php coverage.xml --mode=local` *(requiere pcov/xdebug; si no hay driver, reportarlo y seguir — CI la exige igual)* |
| Front: tipos / lint / formato | `npm run types:check && npm run lint:check && npm run format:check` |
| Front: build de producción | `npm run build` |
| Regenerar tipos Wayfinder (tras cambiar rutas/controladores) | `php artisan wayfinder:generate` (también ocurre dentro de `npm run build` vía plugin Vite) |

**No existe `phpstan analyse` en este repo.** Los gates de calidad son: Pint + PHPUnit + cobertura (`scripts/check-coverage.php`) + `tsc` + ESLint + Prettier + build de Vite.

### 8.3 Branch policy (dura)

- Trabajar **única y exclusivamente** en la rama `claude/night-roadmap` (retomarla de `origin/claude/night-roadmap` si existe; si no, crearla desde `main`).
- **Candado anti-concurrencia entre runs:** al arrancar, si el último commit remoto de `claude/night-roadmap` tiene <45 min y NO es un commit `chore(night): cierre ...`, otro run sigue activo → terminar sin tocar nada. Cada run cierra SIEMPRE con un commit `chore(night): cierre de run {YYYY-MM-DD HH:mm}`.
- **NUNCA** push a `main`/`master` ni a ramas de producción. Nunca `--force`. Aplican todas las reglas de §6.1.
- **UN solo PR abierto a la vez** de `claude/night-roadmap` → `main`; los runs siguientes lo actualizan con pushes + comentario de resumen. No mergearlo (el merge siempre lo autoriza el usuario, §6.1).
- Commits pequeños por tarea, firmados solo con la identidad del usuario (§6.1: sin `Co-Authored-By`, sin banners).

### 8.4 Migraciones y datos (dura)

- Migraciones **additive-only**: solo `create table` / `add column` / `add index`. Prohibido `dropColumn`, `dropTable`, `renameColumn`, cambios de tipo destructivos, y `DELETE`/`UPDATE` masivos de datos dentro de migraciones.
- Prohibido `migrate:fresh`, `migrate:reset`, `db:wipe` fuera del sqlite local de la rutina / entorno de tests.
- Toda tabla tenant-scoped nueva cumple §2 (`team_id` + `BelongsToTenant`).

### 8.5 Regla Inertia/tests (dura)

- Cada página Inertia nueva o modificada → feature test con `$response->assertInertia(fn (Inertia\Testing\AssertableInertia $page) => $page->component('...')->has(...))`.
- Cada endpoint nuevo (web o API) → feature test (happy path + authz/policy + aislamiento de tenant cuando aplique).
- Tests en **PHPUnit 12** (clases en `tests/Feature/...`, siguiendo el estilo de los ~750 tests existentes). **No escribir tests Pest** — Pest no está instalado.
- Factories siempre; nunca `Model::create()` manual en tests (§4).

### 8.6 EXIT CRITERIA de un run

Un run termina solo cuando: **(1)** no quedan tareas `- [ ]` en `ROADMAP.md` (todas `- [x]` completadas o `- [!]` bloqueadas y documentadas), **(2)** `php artisan test --compact` completamente verde, **(3)** `vendor/bin/pint --test` limpio, **(4)** `npm run types:check && npm run lint:check && npm run format:check` verdes, **(5)** `npm run build` exitoso — o cuando se alcanza un límite anti-loop de §8.7 o el presupuesto de la sesión. Todo cierre (incluso sin avance) actualiza `MORNING-REPORT.md` y termina con el commit de cierre del §8.3; el siguiente run retoma.

### 8.7 Límites anti-loop (duros)

- Máximo **10 tareas auto-generadas por día calendario** (FASE B), sumando TODOS los runs del día — contar las tareas de las secciones `## Iteración v{N} — auto-generada {fecha}` con fecha de hoy antes de generar más.
- Máximo hasta la sección **"Iteración v5"** en `ROADMAP.md`. Si v5 se completa, la rutina cierra con PR y reporte; NO crear v6.
- **Respetar "Descartadas (won't fix)"**: nunca re-generar una tarea igual o equivalente a una descartada, ni reabrir una `- [!]` bloqueada sin decisión del usuario.
- Una tarea que falla 2 intentos se marca `- [!]`, se mueve a "Bloqueadas / requieren decisión" con explicación, y se continúa con la siguiente; nunca quedarse iterando la misma tarea.
- Prohibido a la rutina: tocar dependencias (`composer.json`/`package.json`), crear directorios nuevos a nivel `app/`, borrar o debilitar tests existentes para "poner verde", bajar umbrales de cobertura (`scripts/coverage-tiers.php`), o editar este CLAUDE.md / `ROUTINE_PROMPT.md`.
