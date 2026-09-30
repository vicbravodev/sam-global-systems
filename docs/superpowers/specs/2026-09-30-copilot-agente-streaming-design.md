# SAM Copilot: agente con tools y streaming (laravel/ai 1.0)

Fecha: 2026-09-30 · Estado: aprobado en chat, pendiente revisión del spec

## Problema

Hoy el Copilot es un motor de reglas con un LLM que solo redacta:

```
pregunta → IntentRouter (regex) → PLAN fijo de tools → facts → LLM parafrasea highlights
```

- La intención, la unidad, la categoría y el periodo se resuelven con regex (`IntentRouter`, `AssetResolver`, `CopilotPeriod`). La primera regla que coincide gana.
- Cada intención ejecuta siempre el mismo set de tools. No combina, no compara, no encadena consultas.
- El LLM recibe highlights ya escritos y tiene prohibido salirse de ellos. Con o sin API key la respuesta es casi la misma.
- La memoria entre turnos es solo `previousAssetId` y el texto de las respuestas anteriores. Los datos se pierden.
- La respuesta llega completa al final (sin streaming) y las sugerencias de seguimiento son fijas.

Fallos concretos: "¿qué unidad gastó más combustible?" muestra el selector de unidades; "¿qué pasó anoche?" devuelve el texto fijo de ayuda; "pánicos y combustible de T555" ignora la segunda parte; "la semana pasada" se toma como "últimos 7 días".

## Objetivo

Que el Copilot **decida qué consultar**, encadene consultas, compare y responda en streaming, siempre anclado en datos reales del tenant. Criterios de éxito:

1. Las preguntas de ranking o comparación, las preguntas abiertas por rango de tiempo y las de seguimiento ("¿y la T600?") se responden con datos, no con el selector ni con texto fijo.
2. Las tarjetas aparecen en cuanto termina cada tool y el texto se muestra token a token.
3. Ningún argumento del modelo puede sacar datos de otro tenant ni saltarse los permisos del rol.
4. Sin API key, o si el proveedor falla, se usa el camino determinista actual con la misma interfaz.
5. Tests y logging completos (invariante 5 de `CLAUDE.md`).

Fuera de alcance: acciones que escriben (crear o cerrar incidentes desde el chat), aprobación humana de tools, voz y adjuntos.

## Arquitectura

```
POST /{team}/copilot/stream
  └─ StreamCopilotTurn (Action)
       ├─ cuota / rate limit / permisos (igual que hoy)
       ├─ ¿hay key? ── no ──► DeterministicCopilotTurn (IntentRouter + PLAN + Template) ─┐
       │                                                                                  │
       └─ sí ─► CopilotAgent (HasTools, MaxSteps 6) ─ stream() ─────────────────────────┤
                  ├─ tools(): SdkCopilotTool[] filtradas por permisos (withTools)          │
                  ├─ middleware: CopilotStepGuard (log por paso, forzar respuesta final)   │
                  └─ CopilotTurnCollector (bloques, fuentes, followups, facts)             │
                                                                                          ▼
                                     CopilotStreamProtocol (extiende VercelDataProtocol)
                                     text-delta · tool-input-available · data-copilot-*
                                                                                          │
                                     then(): persiste CopilotMessage + uso + auditoría + log
                                     catch(): persiste parcial + log de fallo
```

### 1. `CopilotAgent` (`app/Infrastructure/AI/Agents/CopilotAgent.php`)

- Implementa `Agent`, `Conversational` y `HasTools`, con los atributos `#[MaxSteps(6)]`, `#[Timeout(45)]`, `#[CacheInstructions]` y `#[CacheToolDefinitions]`.
- Constructor: `CopilotTurnScope` (team id/slug, permisos, super admin, zona horaria del tenant, `now`), historial y `CopilotTurnCollector`.
- Instrucciones (español de México, operador senior):
  - Usa tools para todo dato; nunca inventes cifras, ubicaciones ni nombres.
  - Convierte las expresiones de tiempo a `from`/`to` ISO-8601 con base en `now` y la zona horaria que se le pasan.
  - Si la pregunta es ambigua sobre la unidad, usa `find_assets`.
  - Responde directo primero y después riesgos y siguiente paso. No repitas las tarjetas.
  - Cierra siempre llamando a `suggest_followups`.
- `tools()` devuelve solo las tools cuyo permiso tiene el usuario (ver 2). Las herramientas sin permiso no existen para el modelo.

### 2. Tools del SDK: `app/Domains/Copilot/Tools/Sdk/`

Base abstracta `SdkCopilotTool implements Laravel\Ai\Contracts\Tool`:

- `name()` / `description()` en español, pensadas para que el modelo elija bien.
- `schema(JsonSchema $schema)` declara los argumentos. **Nunca** incluye `team_id`, `user_id` ni permisos.
- `handle(Request $request)`:
  1. `$request->validate(rules)`. Si es inválido, devuelve `{"error": "argumentos inválidos", "detalle": ...}` al modelo (para que se corrija) y registra `copilot.tool.invalid_args`.
  2. Resuelve la unidad por `asset_code` con `AssetResolver`, siempre con `where('team_id', $scope->teamId)`. Si no la encuentra, devuelve `{"error": "unidad no encontrada"}`.
  3. Arma `CopilotToolContext` desde el `CopilotTurnScope` del servidor y el periodo validado.
  4. Ejecuta dentro de `TenantContext::for($scope->teamId, ...)` la `CopilotTool` existente o la nueva.
  5. Envía bloques, fuentes y facts al `CopilotTurnCollector` (con el `toolCallId` para correlacionar).
  6. Devuelve al modelo `facts` + `highlights` en JSON compacto (tope de 6 KB; se trunca con aviso `"truncado": true`).
  7. Registra `copilot.tool.ran` (o `copilot.tool.denied` si la tool interna devuelve denegado).
  8. Cualquier excepción de la tool (incluido el `abort_if(404)` de las tools actuales) se captura: devuelve `{"error": "no pude consultar <etiqueta>"}` al modelo y registra `copilot.tool.failed`. Una tool rota nunca tumba el turno.
- `permission(): string` indica el permiso que la filtra en `tools()`.

Adaptadores de las 10 tools existentes (sin cambiar su lógica): `asset_summary`, `asset_location`, `asset_engine`, `asset_fuel`, `asset_media`, `asset_activity`, `panic_kpis`, `open_incidents`, `driver_ranking` y `fleet_overview`. Argumentos comunes: `asset_code?`, `from?`, `to?` (ISO-8601; por defecto los últimos 7 días; máximo 90 días) y `category?`.

Tools nuevas (`app/Domains/Copilot/Tools/`):

| Tool | Argumentos | Qué devuelve | Permiso |
|---|---|---|---|
| `find_assets` | `query`, `category?`, `limit≤10` | unidades que coinciden (código, nombre, categoría, último visto) | `assets.view` |
| `rank_assets` | `metric` ∈ {`fuel_used_pct`, `distance_km`, `idle_hours`, `incidents`, `events`, `panics`}, `from`, `to`, `category?`, `event_type?`, `order` (desc/asc), `limit≤10` | ranking, promedio de flota y `outlier: true` (> promedio + 1.5·desv. estándar) | `assets.view` (+ `incidents.view` para `incidents`/`events`/`panics`) |
| `search_events` | `from`, `to`, `asset_code?`, `event_type?`, `severity?`, `limit≤25` | conteo por tipo y severidad + lista de eventos recientes | `incidents.view` |
| `asset_timeline` | `asset_code`, `from`, `to` | eventos, incidentes y tramos de ralentí de la unidad en orden cronológico | `incidents.view` |
| `suggest_followups` | `questions: string[2..3]` (≤ 80 caracteres cada una) | nada útil al modelo; guarda los followups en el collector | ninguno |

Datos reales disponibles (verificado): el combustible es **% de tanque** (`TelemetryType::Fuel`, `unit` normalmente `%`); se consume sumando las caídas y excluyendo recargas, con la misma lógica que `AssetFuelTool`, que se extrae a un helper compartido. Los km salen del delta del odómetro. **Ralentí (`idle_hours`)** se calcula con la telemetría de motor, en el helper compartido `IdleTimeCalculator`:
  - Fuente principal: `TelemetryType::Ignition` guarda `engineStates` de Samsara tal cual (`Off` / `On` / `Idle`) y **solo cuando cambia**, así que cada lectura abre un tramo que dura hasta la siguiente. Horas de ralentí = suma de los tramos `Idle` recortados al rango `[from, to]`. El estado vigente al inicio del rango se toma de la última lectura anterior a `from`.
  - Respaldo (proveedores que solo reportan `On`/`Off`): tramos `On` que se cruzan con puntos GPS (`AssetLocationSnapshot.speed`) a < 3 km/h durante ≥ 3 minutos continuos.
  - Tramo abierto al final: si la última lectura es `Idle` y la unidad no reporta hace más de 30 min (`last_seen_at`), el tramo se corta en `last_seen_at`, para no inflar el ralentí de unidades sin señal.
  - La respuesta indica la fuente (`engine_state` o `ignition_speed`) para que el modelo la pueda aclarar.
  - Tarea de verificación en el plan: confirmar en la DB de dev que llegan lecturas `Idle` (`asset_telemetry_snapshots` con `telemetry_type = 'ignition'`).
  - `asset_engine` y `asset_timeline` también reportan los tramos de ralentí del rango (≥ 10 min en la línea de tiempo).
  - Retención de telemetría: 90 días (`PurgeOldAssetTelemetryJob`), igual que el rango máximo de las tools. Permisos verificados en las tools actuales: `assets.view`, `incidents.view`, `drivers.view`, `context.view` (media). No existe `events.view`: los eventos se leen con `incidents.view`, igual que `AssetActivityTool`.

### 3. `CopilotTurnCollector`

Objeto por turno (no singleton) con: `blocks` (en orden de ejecución), `sources`, `tools` (nombre, etiqueta, duración, ok/denied/error), `facts digest` (resumen compacto de lo consultado para la memoria) y `followups`. Tiene un listener opcional `onBlocks(callable)` que usa el protocolo de streaming para emitir las tarjetas en cuanto la tool termina.

### 4. `CopilotStepGuard` (middleware de agente, nuevo en 1.0)

- Por cada `PendingStep`: `SystemLog::ok('copilot.step.started', ...)` con el número de paso y las tools disponibles (debug).
- Si `$step->isFinalStep`, usa `withToolChoice('none')` para forzar la respuesta final y registra `copilot.step.budget_reached`.
- Si los tokens acumulados del turno superan `copilot.max_turn_tokens` (config, por defecto 60 000), fuerza la respuesta final igual que arriba.

### 5. Streaming: `CopilotStreamProtocol extends VercelDataProtocol`

- Emite los mensajes estándar del protocolo de Vercel (`start`, `text-delta`, `tool-input-available`, `tool-output-available`, `finish-step`, `finish`) y además:
  - `data-copilot-blocks` `{toolCallId, tool, label, blocks}` cuando el collector recibe bloques.
  - `data-copilot-followups` `{questions}`.
  - `data-copilot-message` `{answer: CopilotMessagePresenter::message(...), conversation, quota}` después de guardar (último antes de `finish`).
- El `tool-output-available` que se envía al navegador **no** incluye el JSON de facts (solo `{ok: true}`). El navegador ya recibe las tarjetas por `data-copilot-blocks` y los facts crudos no se exponen.
- Error enmascarado: `{"type":"error","errorText":"No pude completar la respuesta."}` en español.

Endpoint: `POST /{team}/copilot/stream` (`throttle:copilot`, misma policy y request que `messages.store`). Devuelve `text/event-stream`. **Se mantiene** `POST copilot/messages` (JSON) como camino alterno para clientes sin streaming y para los tests existentes; internamente usa la misma Action sin stream.

### 6. Orquestación: `StreamCopilotTurn` / `SendCopilotMessage`

- `SendCopilotMessage` se reorganiza en: preparar el turno (conversación, mensaje del usuario, historial, scope), correr el turno (agente o determinista) y terminar el turno (guardar respuesta, uso, auditoría y log). Preparar y terminar se comparten entre el endpoint JSON y el de stream.
- El historial deja de ser solo texto: cada turno anterior del asistente se pasa como `content + "\n[datos consultados: <facts digest>]"`, con un límite de 6 turnos y ~4 KB. El digest se guarda en `context_json.facts_digest`.
- El uso de tokens es la suma de todos los pasos (`$response->usage`, `TextUsage`). El costo se calcula con `ModelPricing` corregido (ver 7).
- Las columnas de `CopilotMessage` no cambian: `intent` pasa a ser la intención inferida (la de la primera tool, o `general`), `tools_json` guarda la lista real de tools con su duración, y `blocks_json` / `sources_json` salen del collector.
- `catch()`: si el proveedor falla **antes** del primer text-delta, se reintenta el turno completo por el camino determinista, en el mismo stream, y se registra `copilot.turn.fallback`. Si falla **después**, se guarda lo generado con `context_json.partial = true` y se registra `copilot.turn.failed`.

### 7. `ModelPricing` con caché

`estimateCost()` acepta `TextUsage`: `uncachedInputTokens() × input + cacheReadInputTokens × cached_input (config; por defecto 10 % de input) + outputTokens × output`. La firma actual (ints) se conserva para las llamadas existentes. Esto corrige la sobreestimación que trajo la 1.0, donde `inputTokens` ya incluye los tokens cacheados. Evaluación de eventos y de media también pasan `TextUsage`.

### 8. Frontend

- `use-copilot-chat.ts`: `send()` hace `fetch` a `/stream` con `Accept: text/event-stream` y lee `response.body` con un lector SSE propio (`copilot-stream.ts`, sin dependencias) que interpreta cada línea `data:` como JSON. El estado del mensaje en curso incluye `text`, `blocks`, `activeTools` (etiqueta de cada tool en curso), `followups` y `status`.
- `copilot-message.tsx`: mientras el mensaje está en curso muestra chips de estado ("Consultando combustible de T555…") a partir de `tool-input-available` con una etiqueta en español por tool, las tarjetas según llegan y el texto con cursor.
- Los followups se renderizan como botones de sugerencia bajo la respuesta (reusa el estilo de las sugerencias actuales).
- `AbortController`: el botón de detener cancela el fetch. El servidor detecta la desconexión, termina el stream y guarda lo que llevaba como parcial.
- Tipos en `resources/js/types/copilot.ts` y regenerar Wayfinder.

### 9. Respaldo determinista

`DeterministicCopilotTurn` envuelve el flujo actual (`AnswerCopilotQuestion` + `TemplateCopilotNarrator`) y lo emite por el mismo protocolo: `data-copilot-blocks` por tool, el texto en un solo `text-delta` y `data-copilot-message`. No se borra ni `IntentRouter` ni `PLAN`. `SdkCopilotNarrator` se retira porque el agente lo reemplaza, y `CopilotNarrator` se queda solo con la implementación de plantilla.

## Logging (`docs/SAM/logging.md`, sección copiloto)

Nunca se registra el texto de la pregunta ni de la respuesta, ni los argumentos libres (`query`). Solo largos, códigos y conteos.

| Código | Outcome | Campos |
|---|---|---|
| `copilot.turn.started` | ok (debug) | `team_id`, `conversation_id`, `channel`, `mode` (`agent`/`deterministic`), `question_length`, `history_turns` |
| `copilot.turn.completed` | ok | `team_id`, `message_id`, `mode`; calc `steps`, `tools` (nombres), `tool_count`, `blocks_count`, `followups_count`; result `model`, `input_tokens`, `cached_input_tokens`, `output_tokens`, `cost_estimate`, `latency_ms`, `first_token_ms` |
| `copilot.turn.fallback` | degraded | reason `no_provider_key` (skipped, debug) · `agent_error_before_output`; `error` (clase) |
| `copilot.turn.failed` | failed | reason `agent_error_mid_stream` · `client_disconnected` (skipped); `message_id` (parcial), `error` |
| `copilot.step.started` | ok (debug) | `step`, `tools_available` |
| `copilot.step.budget_reached` | skipped | reason `max_steps` · `max_turn_tokens`; `step`, `tokens_so_far` |
| `copilot.tool.ran` | ok | `tool`, `tool_call_id`, `asset_id?`, `period_days?`; result `duration_ms`, `rows`, `truncated` |
| `copilot.tool.denied` | skipped | reason `missing_permission`; `tool`, `permission` |
| `copilot.tool.failed` | degraded | reason `tool_exception`; `tool`, `tool_call_id`, `error` (clase) |
| `copilot.tool.invalid_args` | skipped | reason `validation_failed` · `asset_not_found`; `tool`, `fields` (solo nombres de campo) |
| `copilot.narration.fallback` | se retira junto con `SdkCopilotNarrator` |

## Tests (`tests/Feature/Domains/Copilot/`)

`Agent::fake()` en 1.0 ejecuta el loop real con tools, así que los turnos multipaso se prueban de punta a punta.

- **CopilotAgentTurnTest:** turno fake de 3 pasos (`rank_assets` → `asset_timeline` → texto) que persiste el mensaje con las tools en orden, bloques, followups, tokens sumados, costo, auditoría y `copilot.turn.completed`.
- **CopilotAgentTenantLeakTest (bloqueante):** el modelo fake pide `asset_code` de otro tenant en cada tool con unidad, y `rank_assets` / `search_events` con datos en ambos tenants. Se verifica con `assertNoTenantLeak($teamB, ...)` y que el resultado sea "no encontrada".
- **CopilotToolPermissionsTest:** un viewer sin `incidents.view` no ve `open_incidents` en `tools()`; una tool interna que devuelve denegado registra `copilot.tool.denied`.
- **CopilotToolFailureTest:** una tool que lanza excepción devuelve error al modelo, el turno termina y se registra `copilot.tool.failed`.
- **CopilotToolValidationTest:** fechas inválidas, rango > 90 días, `limit` fuera de rango y métrica desconocida devuelven error al modelo y registran `copilot.tool.invalid_args` sin tocar la DB.
- **IdleTimeCalculatorTest:** tramos `Idle` recortados al rango, estado heredado antes de `from`, tramo abierto con unidad sin señal, respaldo `On` + velocidad < 3 km/h ≥ 3 min, y un semáforo de 1 min que no cuenta.
- **Tests unitarios de cada tool nueva** (`FindAssetsToolTest`, `RankAssetsToolTest` con outliers, combustible con recargas y ralentí, `SearchEventsToolTest`, `AssetTimelineToolTest`): happy path, vacío y bordes.
- **CopilotStepGuardTest:** en el último paso se fuerza `tool_choice none`; tope de tokens; `copilot.step.budget_reached`.
- **CopilotStreamEndpointTest:** respuesta `text/event-stream`, orden de mensajes (`data-copilot-blocks` antes del `text-delta` final; `data-copilot-message` antes de `finish`), `tool-output-available` sin facts, throttle, policy y conversación ajena → 404.
- **CopilotFallbackTest:** sin key → modo determinista por el stream; error del proveedor antes de emitir → fallback y `copilot.turn.fallback`; error a mitad del stream → parcial guardado y `copilot.turn.failed`.
- **CopilotMemoryTest:** el segundo turno recibe el digest de los datos del primero en el historial.
- **ModelPricingTest:** tokens cacheados a su tarifa y compatibilidad con la firma de ints.
- Cada código de log con `assertSystemLogged` + `assertNoSensitiveDataLogged` (la pregunta contiene un nombre y un código de unidad que no deben aparecer).
- Los tests existentes de Copilot (`SendCopilotMessageTest`, `CopilotAccessTest`, `IntentRouterTest`, etc.) siguen verdes; los que prueban el narrador SDK se migran al agente.
- Frontend: `npm run types:check && npm run lint:check && npm run format:check` y `npm run build`.

## Costo

`gpt-5.4` (US$2.50 / 15.00 por 1 M tokens). Turno típico de 2–4 pasos: ~10–14 K tokens de entrada + ~600 de salida ≈ **US$0.03–0.05** (hoy ~US$0.01). `#[CacheInstructions]` / `#[CacheToolDefinitions]` y la caché automática de OpenAI lo reducen en conversaciones largas. La cuota por consultas no cambia (1 pregunta = 1 consulta); el tope de tokens por turno limita los casos extremos.

## Riesgos

- **php-fpm ocupado durante el stream (hasta 45 s):** mismo orden que hoy (20 s de timeout sin stream). Se mitiga con el rate limit actual (20/min por usuario). Se documenta para el despliegue: los proxies no deben hacer buffering (el SDK ya envía los headers).
- **El modelo elige mal la tool:** descripciones claras, `find_assets` para ambigüedades y tests con casos reales en `IntentRouterTest` reutilizados como evals manuales.
- **Datos crudos en el navegador:** se evita porque los facts nunca van en `tool-output-available`.

## Commits del PR

1. `chore: actualiza laravel/ai a 1.0 (mcp 1.0, boost 2.10)` (ya hecho, sin commitear)
2. `docs: regla de tests y logging para toda feature nueva`
3. `fix: el costo de ia cobra los tokens cacheados a su tarifa`
4. `feat: tools del sdk para copilot con scope de tenant y permisos`
5. `feat: tools de ranking, búsqueda de eventos y línea de tiempo`
6. `feat: copilot como agente multipaso con guardas por paso`
7. `feat: streaming de copilot con protocolo vercel y respaldo determinista`
8. `feat: chat de copilot en streaming con estado de tools y seguimientos`
9. `docs: spec y códigos de log de copilot`
