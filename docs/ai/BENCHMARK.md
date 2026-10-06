# Benchmark de entorno para agentes — SAM

Mide si LSP y CodeGraph mejoran precisión, velocidad y consumo de tokens en tareas reales de SAM.
Cada tarea tiene una **clave de respuesta** verificada contra el código el 2026-09-28 (commit `0840071`),
para que la columna "¿correcta?" no sea una opinión.

## Protocolo

1. **Sesión nueva por prueba** (`claude` en la raíz del repo). Si reutilizas una sesión, `/clear` antes de cada tarea.
2. Mismo modelo en todas las corridas (anótalo). Modo de permisos `auto` para que no haya pausas humanas en el tiempo.
3. Pega el prompt **exacto** de la tarea. No añadas pistas.
4. Al terminar, antes de escribir nada más:
   - `/context` → anota el total de tokens en uso.
   - `/cost` → anota costo/tokens (con plan de suscripción puede no mostrar $; entonces usa la columna de tokens del script).
5. Sal de la sesión (`/exit`) y en la terminal corre `docs/ai/bench-stats.sh` → tool calls, tokens y segundos.
6. Evalúa la respuesta contra la clave. Criterio: ✅ completa, 🟡 parcial (anota qué faltó), ❌ incorrecta/inventada.
7. La tarea (c) modifica código: córrela dentro de un worktree desechable (ver tarea c).

Configuraciones: **base** (hoy) · **+LSP** (tras Fase 3) · **+CodeGraph** (tras Fase 4, con LSP; hook apagado con `CODEGRAPH_NO_PROMPT_HOOK=1`) · **+CodeGraph+hook** (igual, con el hook `UserPromptSubmit` de `.claude/settings.json` activo: inyecta `codegraph_explore` en prompts estructurales, ~4k tokens en la prueba).

### Script de métricas

`docs/ai/bench-stats.sh` lee el transcript que Claude Code guarda de cada sesión y cuenta tool calls, tokens y segundos (incluye subagentes).

```bash
# En una terminal normal (fuera de Claude), justo después de salir de la sesión medida:
docs/ai/bench-stats.sh
```

Sin argumentos toma **la sesión más reciente** de este repo (también las de worktrees). Revisa el campo `prompt` de la salida: debe ser el prompt de la tarea; si no, pasa el id (`docs/ai/bench-stats.sh <session-id>`; el id lo ves con `claude --resume`).
Cierra cualquier otra sesión de Claude abierta en este repo mientras mides, o "la más reciente" podría ser otra.

---

## Tareas

### (a) Arquitectura de extremo a extremo

**Prompt:**
> Explícame, paso a paso con archivo y clase, cómo viaja una alerta de Samsara desde que llega al webhook hasta que se envía la notificación al cliente. Incluye en qué colas corre cada job, dónde se resuelve el tenant y qué eventos se saltan la evaluación de IA.

**Clave:** ver [Clave (a)](#clave-a) al final.

### (b) Todos los llamadores de una función central

**Prompt:**
> Lista todos los lugares del código de aplicación (app/, no tests) que registran uso facturable a través de `App\Domains\Tenancy\Actions\RecordUsageEvent`, directa o indirectamente. Distingue llamadas reales de bindings, comentarios o wrappers sin uso.

**Clave (17 archivos con uso real):**

| Archivo | Nota |
|---|---|
| `app/Domains/AI/Actions/EvaluateEventMultimodally.php` | |
| `app/Domains/AI/Actions/EvaluateEventWithAI.php` | inyectado por constructor |
| `app/Domains/Analytics/Actions/GenerateReport.php` | |
| `app/Domains/Assets/Actions/RecordMonitoredAssetDay.php` | |
| `app/Domains/Assets/Commands/RecordAssetUsageMeters.php` | |
| `app/Domains/Automation/Actions/ExecuteAction.php` | |
| `app/Domains/Automation/Services/RunAutomationWorkflow.php` | |
| `app/Domains/Context/Actions/RequestDeferredEventMedia.php` | |
| `app/Domains/Copilot/Actions/RecordCopilotUsage.php` | |
| `app/Domains/Incidents/Actions/CreateIncidentFromEvent.php` | |
| `app/Domains/Incidents/Actions/CreateManualIncident.php` | |
| `app/Domains/Incidents/Jobs/PlaceVerificationCallJob.php` | |
| `app/Domains/Ingestion/Actions/IngestSafetyEvent.php` | |
| `app/Domains/Notifications/Actions/RecordMessagingUsage.php` | |
| `app/Domains/Tenancy/Listeners/ChargeUnmonitoredEmergency.php` | |
| `app/Infrastructure/AI/Listeners/AIUsageListener.php` | |
| `app/Domains/Tenancy/Actions/RegisterUsageEvent.php` | **trampa:** wrapper que en `app/` no usa nadie (solo su test) |

**No son llamadores:** `TenancyServiceProvider.php` (binding singleton) y `AIServiceProvider.php` (solo un comentario).
Bonus (no penaliza omitirlo): indirectos vía wrappers en uso — `SetAssetMonitoring` → `RecordMonitoredAssetDay`; `AttemptDelivery`/`FinalizeMessagingCharge`/`SendPhoneOtp` → `RecordMessagingUsage`; `SendCopilotMessage` → `RecordCopilotUsage`.
Penaliza: incluir esos dos como llamadores, omitir `AIUsageListener` (vive fuera de `app/Domains`), o no detectar que `RegisterUsageEvent` está muerto.

### (c) Cambio pequeño con impacto en varios archivos

**Preparación (worktree desechable, nada toca `main`):**
```bash
git worktree add --detach .claude/worktrees/bench-c main
cd .claude/worktrees/bench-c && claude      # vendor/ y .env: skill worktree-bootstrap si vas a correr tests
```

**Prompt:**
> Renombra `App\Support\TenantContext::withoutTenant()` a `acrossTenants()` en todo el repo (código, tests y documentación que la mencione). No dejes alias de compatibilidad. Al terminar, demuestra que no quedan referencias al nombre viejo y corre `php artisan test --compact --filter=TenantContext`.

**Clave:**
- Definición + 2 referencias internas en `app/Support/TenantContext.php` (línea ~103 la firma, ~85 `self::withoutTenant(...)` — **trampa: no lleva el prefijo `TenantContext::`**, ~54 docblock).
- 18 archivos en `app/` + 2 en `tests/` con `TenantContext::withoutTenant` (lista: `git grep -l 'TenantContext::withoutTenant'`).
- La tabla de patrones de `CLAUDE.md` (§2.1) lo menciona.
- Verificación: `git grep -n withoutTenant` → 0 resultados; tests de `TenantContext` verdes.

**Limpieza tras cada corrida:** `cd <raíz> && git worktree remove --force .claude/worktrees/bench-c`.

### (d) Auditoría de aislamiento por tenant

**Prompt:**
> Audita dónde y cómo se aplica el aislamiento por tenant en el acceso a datos de SAM: el mecanismo (scope global, contexto en colas, policies, rutas), los puntos donde se desactiva a propósito, y cualquier lugar donde un `withoutGlobalScopes()` no vaya acompañado de un filtro explícito por `team_id`. Dame conteos y ejemplos con archivo:línea.

**Clave (conteos en `app/`):**
- Mecanismo: trait `App\Concerns\BelongsToTenant` (scope global + auto `team_id`), usado por **60 modelos** (5 lo declaran combinado, `use HasFactory, BelongsToTenant;` — un grep de `use BelongsToTenant` da 55 y es incorrecto); `App\Support\TenantContext` (tenant en `Context` de Laravel, viaja en el payload del job), usado en **115 archivos**; `currentTeamId()` lee primero el contexto y luego el usuario autenticado; rutas `/{current_team}/...` con `EnsureTeamMembership` + Policies.
- Patrones: `set()` en la entrada de jobs/listeners, `for($teamId, …)` en Actions, `withoutTenant()` para fan-out de plataforma (20 archivos incl. tests).
- `withoutGlobalScopes()`: **55 líneas** en `app/` + 1 en `routes/channels.php` (2 son comentarios → 54 llamadas reales). La respuesta debe clasificarlas (lookup de entrada de job, escritura de ingesta, filas de plataforma `team_id` null) y señalar las que no tengan `where('team_id', …)` en la misma cadena — si reporta alguna, verificarla a mano.
- Penaliza: dar conteos inventados, confundir `currentTeam()` con el mecanismo en colas, u omitir `TenantContext`.

---

## Resultados

| Tarea | Config | Tokens (in+cache / out) · costo `/cost` | Contexto `/context` | Tool calls | Tiempo (s) | ¿Correcta? |
|---|---|---|---|---|---|---|
| (a) arquitectura | base | 55k+483k cache / 8,0k · **$0,70** | 65k | 10 | 90 | ✅ 13/14 pasos (automatización sólo a nivel listener) |
| (a) arquitectura | +LSP | 44k+581k cache / 9,3k · **$0,65** | 56k | 14 | 90 | ✅ 14/14 pasos |
| (a) arquitectura | +CodeGraph | 71k+464k cache / 7,0k · **$0,80** | 71k | 8 (2 `codegraph_explore`) | 70 | ✅ 13/14 (omite automatización) |
| (a) arquitectura | +CodeGraph+hook | 64k+943k cache / 9,4k · **$0,88** | 76k | 16 (1 `codegraph_explore`) | 104 | ✅ 14/14 |
| (b) llamadores | base | 45k+297k cache / 5,9k · **$0,54** | 56k | 6 | 59 | ✅ 16 + wrapper muerto, indirectos incluidos, sin falsos positivos |
| (b) llamadores | +LSP | 36k+216k cache / 6,6k · **$0,47** | 49k | 5 | 59 | ✅ + 2 hallazgos extra (tokens IA contados dos veces, clave OTP no idempotente) |
| (b) llamadores | +CodeGraph | 45k+155k cache / 4,6k · **$0,48** | 45k | 4 | 41 | ✅ |
| (b) llamadores | +CodeGraph+hook | 38k+179k cache / 4,4k · **$0,43** | 51k | 4 | 42 | ✅ |
| (c) rename | base | 44k+449k cache / 3,0k · **$0,50** | 55k | 9 | 57 | ✅ 0 refs viejas (incl. `self::`), CLAUDE.md, tests verdes |
| (c) rename | +LSP | 29k+239k cache / 2,4k · **$0,33** | 41k | 6 | 36 | ✅ 0 refs, 21 archivos, tests 9/9 |
| (c) rename | +CodeGraph | 45k+270k cache / 3,1k · **$0,47** | 45k | 7 | 45 | ✅ |
| (c) rename | +CodeGraph+hook | 34k+339k cache / 3,1k · **$0,40** | 47k | 8 | 48 | ✅ (además corrió `StaleCurrentTeamTest`) |
| (d) aislamiento | base | 65k+1.005k cache / 14,2k · **$1,01** | 76k | 16 | 152 | ✅ conteos correctos (60 modelos, 54 `withoutGlobalScopes`), 1 hallazgo real verificado |
| (d) aislamiento | +LSP | 66k+2.018k cache / 18,7k · **$1,30** | 78k | 34 | 245 | ✅ 60 modelos, 56 (=54 + 2 seeders), 2 hallazgos reales · **editó la memoria** (revertido) |
| (d) aislamiento | +CodeGraph | 77k+753k cache / 13,9k · **$1,04** | 77k | 16 | 134 | ✅ 60 modelos, 53 en `app/` |
| (d) aislamiento | +CodeGraph+hook | 73k+1.800k cache / 17,6k · **$1,30** | 85k | 29 | 194 | 🟡 **55 modelos** (cayó en la trampa del grep) |

**Cómo se midió (2026-09-28):** headless, `claude -p "<prompt>" --output-format json --permission-mode auto --max-budget-usd 5`, sesión nueva por tarea, las 4 en paralelo. Modelo `claude-opus-5-5`. Costo = `total_cost_usd` del JSON; tokens = `usage` (cache_write + cache_read / output); contexto = tokens de entrada del último turno (equivale al total de `/context`); tool calls y tiempo = `docs/ai/bench-stats.sh`. Ninguna corrida delegó a subagentes. (c) corrió en el worktree `bench-c-base` con `vendor/` real. **Total base: $2,75.**
Runner reutilizable: ver "Re-ejecutar" abajo.

**Corridas +LSP / +CodeGraph / +CodeGraph+hook (2026-09-28, commit `46623fc`):** mismo método; las 4 tareas de cada config en paralelo y una config tras otra. (c) en worktrees `bench-c-{lsp,cg,cghook}` con `vendor/` real y los CLAUDE.md actuales. +LSP = `--disallowedTools mcp__codegraph__codegraph_explore "Bash(codegraph:*)"` + `CODEGRAPH_NO_PROMPT_HOOK=1`; +CodeGraph = hook apagado; +CodeGraph+hook = todo activo. Ojo: la base usó el CLAUDE.md anterior a la Fase 2.
**Totales:** base $2,75 · +LSP $2,75 · +CodeGraph $2,79 · +CodeGraph+hook $3,01.

**Lectura de los datos (n=1 por celda):**
- **La varianza domina.** (d)+LSP y (d)+CodeGraph usaron las mismas herramientas (solo Bash) y aun así dieron 34 vs 16 tool calls y $1,30 vs $1,04. Diferencias menores a ~2× entre configs no son señal.
- **LSP: 0 usos** de la herramienta `LSP` en las 12 corridas. En tareas de navegación no aporta; su valor esperado son los diagnósticos pasivos tras editar, que este benchmark no mide.
- **CodeGraph MCP: 3 usos en 8 corridas** (todos en (a)). El modelo prefiere grep aunque CLAUDE.md lo oriente al grafo. Calidad igual a la base; costo igual.
- **Hook:** +8 % de costo total, no redujo tool calls ((d) 16 → 29) y es la única celda 🟡. Además se dispara con avisos automáticos de tareas en segundo plano e inyecta ~16 KB irrelevantes (`CommandPalette`, `AssetSummaryTool`…). **Recomendación: quitarlo.**
- **Decisión (2026-09-28):** CodeGraph retirado por completo (MCP, hook, CLI, índice, `codegraph.json`, secciones de CLAUDE.md; respaldos `*.bak-codegraph-2026-09-28`). Se conservan los plugins LSP (costo de contexto ~0, diagnósticos pasivos).

Referencia — corridas interactivas del usuario (misma config base, con latencia humana en el tiempo): (a) 26 tool calls · 658 s · ✅ · (b) 5 · 271 s · ✅ · (c) 8 · 247 s · ✅. Hallazgo colateral: la corrida interactiva de (c) **editó la memoria persistente** para reflejar el rename del worktree (revertido a mano) — en re-ejecuciones de (c), revisar `memory/` al terminar.

### Re-ejecutar

```bash
# desde la raíz; P = prompt de la tarea (sección "Prompt" de arriba), CFG = base|lsp|codegraph
claude -p "$P" --output-format json --permission-mode auto --max-budget-usd 5 > /tmp/bench-a-$CFG.json
jq '{cost: .total_cost_usd, turns: .num_turns, ms: .duration_ms, usage}' /tmp/bench-a-$CFG.json
docs/ai/bench-stats.sh "$(jq -r .session_id /tmp/bench-a-$CFG.json)"
```

Contexto fijo al arrancar, medido el 2026-09-28 **antes** de cualquier cambio: **56,4k tokens** — memoria 18,3k · tools 16,9k · skills 7,1k · agentes propios 6,9k · MCP 1,7k.
Ese mismo día, antes de correr la línea base, ya se aplicaron: agentes propios archivados (−6,9k) y la §8 de CLAUDE.md movida a `ROUTINE_PROMPT.md` (retirado el 2026-10-05). La fila "base" mide ese estado; anota el total de `/context` de una sesión vacía como nuevo punto de partida.

---

## Clave (a)

Traza verificada contra el código. Los listeners de la cadena son síncronos (`Event::listen` en cada `*ServiceProvider`); los saltos entre dominios son jobs.

| # | Paso | Cola | Cómo se encadena |
|---|---|---|---|
| 1 | `WebhookController::handle` — resuelve `WebhookEndpoint` por url y **fija el tenant**: `TenantContext::set($endpoint->tenantIntegration->team_id)`; responde 202 | HTTP | ruta `POST webhooks/{endpoint_url}` |
| 2 | `HandleWebhook::execute` — guarda `WebhookEvent` (Received) | — | llamada directa |
| 3 | `ProcessWebhookEventJob` — valida firma HMAC (`ValidateWebhookSignature` → adapter) y llama `RawEventIngestion::ingest` | `ingestion` | dispatch |
| 4 | `RawEventIngestionService::ingest` → `StoreRawEvent` (+`RawEventReceived`) → `QueueRawEventForProcessing` | — | directa |
| 5 | `ProcessRawEventJob` — dedup, `RawEventProcessed` | `ingestion` | dispatch |
| 6 | `NormalizeEventJob` → `NormalizeRawEvent` — mapea tipo, resuelve activo/conductor, descarta activo apagado no-emergencia, `EventUnmapped` si no hay regla; si no, `EventNormalized` | `normalization` | listener `NormalizeOnRawEventProcessed` → dispatch |
| 7 | **Rama emergencia:** `OpenEmergencyIncidentJob` → `CreateIncidentFromEvent` sin esperar contexto/IA (emergency, panic_button, collision, rollover, device_offline en movimiento) | `incidents` | listener `OpenEmergencyIncidentOnEventNormalized` |
| 8 | `EnrichContextJob` → `BuildEventContext` → `EventContextBuilt` | `context` | listener `EnrichContextOnEventNormalized` |
| 9 | **Gate IA:** `AIEvaluationGate::shouldEvaluate` — se salta si la categoría está en `ai.skip_evaluation_categories` (`config/ai.php:201` = `safety`, `maintenance`) o el tipo en `ai.skip_evaluation_event_types`. Los safety events de Samsara **terminan aquí** (salvo que el paso 7 ya abriera incidente) | — | listener `EvaluateOnEventContextBuilt` |
| 10 | `EvaluateEventJob` → `EvaluateEventWithAI` → `AIEvaluationCompleted` | `ai-evaluation` | dispatch afterCommit |
| 11 | `RunDecisionEngineJob` → `EvaluateDecisionRules` → `DecisionMade` | `decisions` | listener `RunDecisionEngineOnAIEvaluationCompleted` |
| 12 | `CreateIncidentJob` — sólo outcomes que `surfacesToOperators()` (INCIDENT/ESCALATE, REQUIRE_HUMAN_REVIEW, ALERT); si ya existe incidente (paso 7) aplica reevaluación → `CreateIncidentFromEvent` → `IncidentCreated` | `incidents` | listener `CreateIncidentOnDecisionMade` |
| 13 | Automatización: `TriggerEscalationWorkflow` → `RunAutomationWorkflowJob` → `ExecuteActionJob` → `ExecuteAction` (acciones SendSms/Whatsapp/Email/Push llaman `SendNotification`) | `automation` | listeners `TriggerAutomationOn{DecisionMade,IncidentCreated}` |
| 14 | Notificación: `NotifyOnIncidentCreated` (umbral `notifications.out_of_band_min_severity`; debajo → sólo Web) → `SendNotification` (idempotente por `event_key`) → `SendNotificationJob` → `DispatchNotification` → `AttemptDelivery` → driver SMS/WhatsApp → `TwilioMessenger::createMessage` (`messages->create`) | `notifications` | listener sobre `IncidentCreated` |

**Tenant:** se resuelve una sola vez en el paso 1 y viaja en el `Context` de Laravel dentro del payload de cada job. `TenantContext::set` en WebhookController, CreateIncidentJob, OpenEmergencyIncidentJob, AutoAssignIncidentJob, SendNotificationJob; `TenantContext::for` en ProcessRawEventJob, NormalizeEventJob, EnrichContextJob, EvaluateEventJob, RunDecisionEngineJob, ExecuteActionJob. `ProcessWebhookEventJob` y `RunAutomationWorkflowJob` no lo fijan: dependen del contexto rehidratado.

**Criterio:** ✅ si nombra ≥12 de los 14 pasos en orden, la rama de emergencia, el gate de IA con `safety`, las colas correctas y que el tenant viaja en el `Context`. Penaliza inventar clases o afirmar que el tenant se obtiene de `currentTeam()`/usuario autenticado en los jobs.
