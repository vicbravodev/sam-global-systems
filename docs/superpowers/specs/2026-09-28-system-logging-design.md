# Logging narrativo y seguro en todo el sistema — diseño

Fecha: 2026-09-28 · Rama: `feat/system-logging` · Base: PR #148 (`PipelineTrace`)

## 1. Objetivo

Que cada proceso del sistema deje escrito, en logs JSON estructurados, **qué procesó, cómo lo hizo, por qué tomó cada rama, cómo calculó cada número y cómo terminó**. Ejemplos: usó o no la IA y por qué, qué regla ganó, cómo salió el riesgo o el cobro. Con eso se puede depurar un error y mejorar el sistema leyendo el recorrido de un evento por su `trace_id`, sin reproducirlo.

La misma exigencia vale para **todo** el sistema: pipeline, billing, telemática, seguridad, clientes externos y el resto de dominios. Además queda protegida por guardias automáticas, para que el código nuevo no la pierda.

### Decisiones tomadas

| Pregunta | Decisión |
|---|---|
| Destino de la narrativa | **Solo logs JSON**: ni tabla de pasos en la DB ni UI nueva. |
| Alcance de lo "proactivo" | **Solo logging** en esta iteración. Sin invariantes con alerta ni comando de reporte. |
| Enfoque | API narrativa propia (`SystemLog`), con una red automática de fondo (jobs, HTTP, excepciones). |

### Fuera de alcance

- Tabla `pipeline_steps`, UI de trazas, alertas y agregador externo.
- Arreglar los bugs de lógica encontrados (§8). Aquí solo quedan registrados como `degraded`.
- Cambiar lo que ya se persiste en la DB (`AIInferenceLog`, `DecisionTrace`, `ActionExecutionLog`, `audit_logs`). El log narrativo lo **complementa**: las decisiones que ya están en la DB también se emiten al stream, con sus ids para cruzarlas.

## 2. Estado actual (medido el 2026-09-28)

- `PipelineTrace` (PR #148) propaga `trace_id`, `team_id` e ids de etapa por el `Context` de Laravel. **Se conserva tal cual.**
- Hay unas 55 llamadas `Log::` en ~750 archivos; en el pipeline casi todas registran solo fallos. `Tenancy`, `Access`, `TenantConfig` y `Copilot` no tienen ninguna.
- El canal `json` existe pero viene apagado (`LOG_STACK=single`). No hay redacción de datos sensibles en ningún canal.
- `bootstrap/app.php` no personaliza `report()`, así que 401/403/404/validación no se registran.
- Fugas actuales:
  - teléfono + token de respuesta en `ProcessInboundReply`;
  - errores de Twilio con el número de destino en `PlaceVerificationCallJob`;
  - URLs prefirmadas dentro de `getMessage()` de `SecureMediaDownloader`;
  - ~25 sitios con `getMessage()` crudo (incluidos `JobFailureReporter` y `ObjectStorageFailure`), que puede llevar SQL con bindings, IPs o el prompt de la IA;
  - stderr de ffmpeg en `VideoFrameExtractor`.

## 3. Esquema de una línea de log

Cada línea es JSON (Monolog `JsonFormatter`):

```json
{
  "message": "ai.gate.skipped",
  "level_name": "INFO",
  "context": {
    "outcome": "skipped",
    "reason": "skip_category",
    "input": {"type_code": "harsh_brake", "category_code": "safety"},
    "calc": null,
    "result": {"evaluated": false},
    "duration_ms": null
  },
  "extra": {"trace_id": "01k…", "team_id": 7, "raw_event_id": 123, "normalized_event_id": 456}
}
```

| Campo | Regla |
|---|---|
| `message` | Código estable `dominio.etapa.resultado`, en snake_case e inglés. Nunca lleva texto variable. Es la clave por la que se filtra. |
| `outcome` | `ok` \| `skipped` \| `degraded` \| `failed`. `degraded` = siguió, pero peor de lo esperado (fallback a `rules_only`, dato faltante, circuito abierto). |
| `reason` | Código estable (`skip_category`, `quota_exceeded`, `hmac_mismatch`…). Obligatorio cuando `outcome` ≠ `ok`. |
| `input` | Los datos que decidieron la rama: ids, códigos, flags y umbrales de configuración. |
| `calc` | Para cálculos: **cada término, cada umbral y la fórmula** (`{"base": 0.5, "recurrence_boost": 0.1, "geofence_boost": 0.05, "final": 0.65}`). Con él se tiene que poder rehacer el número a mano. |
| `result` | Qué quedó: ids creados, estado final, conteos. |
| `duration_ms` | En operaciones medidas (`SystemLog::measure`). |
| `error` | Solo en `failed`/`degraded` con excepción: la salida de `SafeException::describe()`. |

`extra` lo pone el `Context` (`trace_id`, `team_id`, `parent_trace_id`, ids de etapa); no se repite en `context`.

**Nivel derivado del resultado:** `ok`/`skipped` → `info`, `degraded` → `warning`, `failed` → `error`. Las rutas calientes (ciclo de telemática cada 5 s, por punto GPS) llaman con `debug: true` y bajan a `debug`. La excepción son los resúmenes por ciclo, que se quedan en `info`.

**Prohibido en cualquier campo:** teléfonos, emails, nombres de personas, direcciones postales, tokens, secretos, firmas, URLs con query, payloads crudos de proveedor, texto libre de operadores, prompts o respuestas de la IA y `getMessage()` sin sanear. Coordenadas: solo redondeadas a 3 decimales (~110 m) y solo donde expliquen una decisión (geocerca, movimiento).

## 4. Componentes

Todo va en `app/Support/`, sin directorios nuevos.

### 4.1 `App\Support\SystemLog`

API única para escribir la narrativa:

```php
SystemLog::ok('incident.created', input: [...], calc: [...], result: [...]);
SystemLog::skipped('ai.gate.skipped', reason: 'skip_category', input: [...]);
SystemLog::degraded('ai.evaluation.fallback', reason: 'agent_error', error: $e, input: [...]);
SystemLog::failed('webhook.signature.rejected', reason: 'hmac_mismatch', input: [...]);

$result = SystemLog::measure('samsara.request', fn () => ..., input: [...]); // añade duration_ms, ok/failed
```

- Escribe en el canal por defecto (el stack), así que respeta `LOG_STACK`.
- Valida el código (`/^[a-z0-9_]+(\.[a-z0-9_]+){2,}$/`) y exige `reason` cuando `outcome` ≠ `ok`. En `local`/`testing` un código inválido lanza excepción; en producción la línea se escribe igual, con `schema_violation: true`.
- `error: Throwable` se serializa con `SafeException::describe()`.

### 4.2 `App\Support\SafeException`

`describe(Throwable $e): array` devuelve `{class, code, message, at: "app/…/File.php:123", previous: class|null}`. El `message` pasa por el mismo saneador de §4.3 y se corta a 300 caracteres. Para `QueryException` se usa el SQL **sin bindings** y el SQLSTATE. Sustituye a todo `getMessage()` que hoy va a logs y a `JobFailureReporter`/`ObjectStorageFailure`.

### 4.3 `App\Support\RedactSensitiveLogData` (processor de Monolog)

Se aplica con `tap` a **todos** los canales de `config/logging.php`, incluido `telematics`. Es la red de seguridad: `SystemLog` ya debe ir limpio, y esto atrapa lo que se cuele.

- **Por clave** (recursivo, sin distinguir mayúsculas; coincide si la clave contiene el término): `phone`, `email`, `password`, `secret`, `token`, `signature`, `authorization`, `cookie`, `api_key`, `otp`, `code_hash`, `payload`, `body`, `raw`, `prompt`, `address`, `name` (salvo las claves exactas permitidas `job`, `queue`, `channel`, `connection`, `event_name`, `agent_name`, `class`). El valor se reemplaza por `[redacted]`.
- **Por patrón** en todo string (mensaje, contexto y extra):
  - emails → `[email]`;
  - teléfonos (`+?\d[\d\s().-]{8,}\d`) → `[phone]`;
  - query strings de URLs → `?[redacted]` (host y path se conservan);
  - `Bearer …` y `Basic …` → `[redacted]`.
- No toca ids numéricos ni ULIDs.

### 4.4 Red automática (en `AppServiceProvider`)

| Fuente | Códigos | Campos |
|---|---|---|
| `Queue::after` / `Queue::failing` / `Queue::exceptionOccurred` | `queue.job.finished`, `queue.job.failed`, `queue.job.retrying` | job, queue, connection, attempt, max_tries, duration_ms, `error` saneado. Los jobs en cola `telematics` bajan a `debug`. |
| `Http::globalRequestMiddleware`/`globalResponseMiddleware` + `ConnectionFailed` | `http.client.request.completed`, `http.client.request.failed` | host, path **sin query**, method, status, duration_ms, attempt, `provider` (por host: samsara/twilio/openai/…). Nunca headers ni body. |
| `withExceptions()->report()` en `bootstrap/app.php` | `http.request.denied` (401/403/`AuthorizationException`), `http.request.not_found` solo en rutas de webhook, `http.request.throttled` (429) | route name, method, status, user_id, team_id, reason = clase de la excepción. `ValidationException` no se registra. Se añade `context()` con `route` y `user_id` a todo reporte. |
| Eventos de auth (`Illuminate\Auth\Events\Failed`, `Lockout`, `Login`, `Logout`, `PasswordReset`) | `auth.login.failed`, `auth.login.locked_out`, `auth.login.succeeded`, … | user_id si existe. El email del intento nunca se escribe: si hace falta correlacionar, va un hash sha256 truncado a 12 caracteres. |

Los SDK de IA (`laravel/ai`) y Twilio no pasan por `Http::`. Sus llamadas se envuelven con `SystemLog::measure` en `Infrastructure` (fase 6).

### 4.5 Configuración

- `.env.example` y el default de `config/logging.php`: `LOG_STACK=single,json`. El canal `json` se renombra de `pipeline.json` a `logs/system.json`: ahora cubre todo el sistema.
- El canal `telematics` pasa a `JsonFormatter` (sigue en su archivo por volumen) y recibe el mismo processor.
- Nueva variable `LOG_JSON_STDERR=false`: si vale `true`, `json` escribe a stderr en lugar de a archivo, para un futuro agregador. No añade dependencias.

### 4.6 Guardias que mantienen la exigencia

1. **Test arquitectural** `tests/Feature/Architecture/LoggingConventionsTest.php`:
   - prohíbe `Log::`, `logger(` e `info(` en `app/` y `routes/` fuera de `SystemLog` y de una allowlist explícita (vacía al cerrar la fase 1);
   - prohíbe `->getMessage()` en las mismas líneas que `SystemLog::` o `Log::`;
   - valida con regex que cada código literal cumpla el formato.
2. **Helper de test** `tests/Concerns/AssertsSystemLog.php`. Captura con `Log::listen`, sin mockear la DB:
   - `assertSystemLogged(string $code, ?Closure $where = null)`;
   - `assertSystemNotLogged($code)`;
   - `assertNoSensitiveDataLogged()`, que pasa el saneador sobre todo lo capturado y falla si encuentra algo que redactar. Detecta que `SystemLog` recibió datos prohibidos, no solo que salieron redactados.
3. **Regla en `app/CLAUDE.md`**: toda rama de decisión (return temprano, skip, fallback, umbral) y todo cálculo registra su código `SystemLog`; cada código nuevo lleva un test que lo afirma, y nunca se usa `Log::` directo.
4. **Catálogo vivo**: `docs/SAM/logging.md` lista los códigos por dominio, con su `reason` posibles y los campos de `calc`. Se actualiza en cada fase. Es el único doc nuevo y forma parte de esta aprobación.

## 5. Cobertura por fase

Un PR por fase, cada uno con tests que afirman sus códigos y con `assertNoSensitiveDataLogged()` en los tests del dominio. La lista de referencias `archivo:línea` sale de los inventarios del 2026-09-28; el plan de cada fase la detalla.

### Fase 1 — Fundación
§4 completo. Migrar los ~55 `Log::` existentes a `SystemLog` con códigos. Corregir las fugas de §2. `JobFailureReporter` pasa a delegar en `SystemLog::failed('queue.job.failed', …)`.

### Fase 2 — Pipeline de entrada (Integrations → Ingestion → Normalization → Context)
- **Webhook:**
  - `webhook.endpoint.not_found`;
  - `webhook.event.received` (tamaño y tipo, nunca el payload);
  - `webhook.signature.verified` / `webhook.signature.rejected` con `reason` = `empty` \| `stale_timestamp` \| `hmac_mismatch` y `calc: {skew_seconds, tolerance_seconds, secret_variant}`;
  - `webhook.discarded` (`tenant_deleted`);
  - `webhook.ingested` (`provider_code_fallback`).
- **Ingesta:**
  - `ingestion.raw_event.stored`, con `dedup_key_strategy`, `occurred_at_source` y `parse_failed`;
  - `ingestion.duplicate.detected`, con `path` = `existing` \| `race` \| `expired_reset` y `first_raw_event_id`;
  - `ingestion.media.inline_collected`, con `calc: {urls_found, downloaded, failed}`;
  - `ingestion.usage.not_metered` (meter faltante = hueco de cobro → `degraded`);
  - `ingestion.poll.cycle_completed`, con conteos, `has_more`, reinicio de cursor y `retry_after`.
- **Normalización:**
  - `normalization.type.mapped` / `normalization.type.unmapped`, con `matched_rule_id`, `candidates_count` y `failed_condition_path`;
  - `normalization.asset.resolved`, con `asset_path_used`, `ref_found` y `cross_tenant_rejected`; igual para driver;
  - `normalization.severity.resolved`, con `severity_source`;
  - `normalization.event.discarded_unmonitored`, con `monitoring_state` e `is_emergency`;
  - `normalization.event.emergency_unmonitored_passed`;
  - `normalization.catalog.fallback_used`.
- **Contexto:**
  - `context.location.resolved`, con `location_source` y `calc: {staleness_seconds, max_age_seconds}`;
  - `context.live_location.skipped` / `context.live_location.failed`;
  - `context.snapshot.built`, con versión, conteos, `risk_level` y `within_hours`;
  - `context.media.auto_request_skipped` / `context.media.auto_request_reused`;
  - `media.deferred.*` por cada rama del job de media diferida: `expired`, `no_integration`, `retention_exceeded`, `no_camera`, `polling`, `all_failed`, `closed_via_uploaded`.

### Fase 3 — IA + Decisions (cálculos completos)
- **Gate y cuota:**
  - `ai.gate.skipped`, con `reason` = `skip_type` \| `skip_category`, y `result: {decision: false, incident: false}`;
  - `ai.evaluation.already_exists`;
  - `ai.quota.checked`, con `calc: {tokens_used, tokens_limit, calls_today, calls_limit, is_critical, bypassed}` → `ai.evaluation.rules_only` (`quota_exceeded` \| `agent_error` \| `heuristic_short_circuit`).
- **Evaluación y riesgo:**
  - `ai.evaluation.completed`, con `mode` (`ai_text` \| `hybrid` \| `rules_only`), clasificación, confianza, `model`, tokens de entrada y salida, `cost`, `latency_ms`, `ai_inference_log_id`;
  - `ai.risk.calculated`, con `calc` término a término (base por severidad, nivel de riesgo, recurrencia, geocerca, cada boost de señal, clamp y resultado);
  - `ai.priority.resolved`, con `calc: {risk, thresholds: {critical: 0.85, high: 0.6, medium: 0.3}}`;
  - `ai.false_positive.checked`, con `calc: {confidence, threshold: 0.85}`.
- **Media:**
  - `ai.media_fusion.applied`, con la rama (`no_media` \| `no_verdict` \| `confirms` \| `critical_no_reduce` \| `contradicts`) y los deltas de riesgo y confianza antes y después;
  - `ai.media.assessed`, con resultado, confianza, tokens y costo;
  - `ai.media.reused` / `ai.media.filtered`;
  - las ramas de media que ya se registran se migran a códigos.
- **Reevaluación:** `ai.reevaluation.superseded` / `ai.reevaluation.requested`, con `debounce_s`.
- **Decisions:**
  - `decisions.ruleset.missing`;
  - `decisions.rules.evaluated`, con `calc: {ruleset_id, evaluated_count, matched: [...], stopped_at}`;
  - `decisions.rule.invalid` (`degraded`: condición mal formada u operador desconocido; hoy falla en silencio);
  - `decisions.outcome.resolved`, con `source` = `hard_safety` \| `tenant_rule` \| `global_rule` \| `ai_mapping` \| `log_only_fallback`, `rule_code`, `calc: {risk, confidence, human_review_threshold}` y `decision_trace_id`;
  - `decisions.outcome.floored` (piso de seguridad crítica: original → final);
  - `decisions.outcome.forced_human_review` (la media contradice una decisión que ya actuó);
  - `decisions.escalation_policy.resolved`, con su fuente.

### Fase 4 — Incidentes + Automatización + Notificaciones
- **Incidentes, apertura:**
  - `incidents.emergency.fast_path`, con `type_code`, categoría y `was_in_motion`;
  - `incidents.creation.skipped` (outcome no visible para operadores);
  - `incidents.type.resolved`, con candidatos, elegido y `used_last_resort`;
  - `incidents.priority.resolved`, con su fuente;
  - `incidents.dedup.linked`, con `existing_incident_id`, `calc: {window_minutes, matched_on}` y `priority_raised`;
  - `incidents.offline_burst.aggregated`, con `calc: {recent_singles, threshold}`;
  - `incidents.sla.calculated`, con `calc: {sla_seconds, opened_at, base = max(opened_at, now), sla_due_at, backfill_adjusted}`;
  - `incidents.created`.
- **Incidentes, después de abrir:**
  - `incidents.reevaluation.applied`, con la rama y la prioridad previa y nueva;
  - `incidents.assignment.resolved` (turno o fallback);
  - `incidents.on_call.*`;
  - `incidents.call_verification.*`;
  - `incidents.ack_check.*`, con nivel, intento y `next_delay`.
- **Automatización:**
  - `automation.workflow.matched` / `automation.workflow.not_matched`, con `failed_key`, `expected` y `actual` (valores de configuración, nunca PII);
  - `automation.workflow.skipped` (`already_ran` \| `no_steps` \| `needs_confirmation` \| `no_incident`);
  - `automation.action.completed` / `automation.action.failed` / `automation.action.retry_scheduled`, con `calc: {attempts, max_attempts, backoff_s}` y `automation.action.stopped` (`terminal` \| `human_control` \| `tenant_blocked`).
- **Notificaciones:**
  - `notifications.channels.selected`, con `calc: {priority, forced, quiet_hours, muted, allowed_types, selected}`. Un resultado vacío se registra como `skipped`: hoy no queda ni en la DB;
  - `notifications.out_of_band.skipped` (`calc: {severity, min_severity}`);
  - `notifications.recipients.resolved` (`dropped_count` por motivo);
  - `notifications.dedup.skipped`;
  - `notifications.delivery.sent` / `notifications.delivery.failed` (proveedor, canal, código de error del proveedor, `duration_ms`);
  - `notifications.retry.scheduled` / `notifications.fallback.chosen` / `notifications.escalation_guard.blocked`;
  - `notifications.inbound_reply.*`, sin teléfono ni token.

### Fase 5 — Billing/Tenancy + Assets/telemática
- **Uso y precio:**
  - `billing.usage.recorded` / `billing.usage.duplicate_ignored`, con `meter`, `quantity`, `event_key` y `billing_period_key`;
  - `billing.terms.resolved`, con la fuente de cada campo (tenant o config);
  - `billing.tier.selected`, con `calc: {average_assets, billable_assets, tier, unit_price}`;
  - `billing.asset_day.calculated`, con `calc` de la línea completa (mínimo facturable, tope, días extra, precio, subtotal, FX y markup);
  - `billing.asset_limit.resolved`, con su fuente.
- **Facturas y agregación:**
  - `billing.invoice.generated`, con `calc: {subtotal, overage_total, total, currency, lines}`;
  - `billing.invoice.already_exists` / `billing.invoice.lock_timeout`;
  - `billing.meter.missing`;
  - `billing.aggregate.completed`, con los conteos del fan-out y los omitidos por motivo;
  - `billing.overage.computed`, con `calc: {consumed, included, overage, previous_overage}`;
  - `billing.estimate.calculated`.
- **Cobros especiales y bloqueos:**
  - `billing.emergency_surcharge.charged` / `billing.emergency_surcharge.skipped`;
  - `billing.monitored_day.skipped` (`not_monitored` \| `tenant_not_billable` \| `already_recorded`);
  - `billing.daily_close.completed` (tenants procesados, omitidos y fallidos; tracto-días; cámaras);
  - `billing.tenant.blocked`, con el motivo de `TenantCanSend`;
  - `ai.usage.not_metered`.
- **Telemática** (resúmenes por ciclo en `info`, detalle en `debug`):
  - `telematics.cycle.completed`, que amplía la línea actual con `failure_class`, `retry_after`, `consecutive_failures`, `paused_until` y `max_pages_hit`;
  - `telematics.cycle.paused`;
  - `telematics.circuit.opened` (`degraded`);
  - `telematics.points.dropped`, con conteos por motivo;
  - `telematics.feeds.dispatched`, con conteos por tick;
  - `assets.after_hours.evaluated`, con `calc: {speed, age_s, cooldown_s}` y la rama.
- **Barridos y sincronización:**
  - `assets.offline_sweep.completed`, con `calc: {scanned, raised, deduped, resolved, parked_threshold, moving_threshold}`;
  - `assets.unauthorized_stop_sweep.completed`;
  - `assets.connectivity.polled` (matched/unmatched);
  - `assets.sync.completed` / `assets.sync.external_id_conflict`;
  - `assets.monitoring.changed`;
  - `assets.purge.completed`, con `removed` y `cutoff`.

### Fase 6 — Seguridad/acceso + clientes externos + resto de dominios
- **Seguridad y acceso:**
  - `access.denied`, con `reason` = `permission` \| `subscription` \| `feature` \| `role`, `permission` y `min_role`;
  - `access.role_delegation.denied`;
  - `access.super_admin.forced_team_switch` (`warning`: hoy es silencioso);
  - `access.team.switched`;
  - `access.super_admin.denied`;
  - `webhook.twilio.signature_rejected` / `webhook.twilio.unknown_number`;
  - `billing.receipt.uploaded`.
- **Clientes externos:**
  - Samsara: todo pasa por `client()`, así que la red HTTP de §4.4 lo cubre. Además, el adaptador registra lo que hoy traga en silencio: `samsara.gateways.failed`, `samsara.live_location.failed` y `samsara.test_connection.*`;
  - `media.download.rejected` (`ssrf_blocked` \| `http_error` \| `too_large`, host sin query) / `media.download.completed` (bytes, duración);
  - `ai.agent.called`, con agente, modelo, `latency_ms`, tokens y resultado; nunca el prompt ni la respuesta;
  - `twilio.*.sent` / `twilio.*.failed`, con SID, estado y código de error; nunca los números.
- **Resto de dominios:**
  - `drivers.risk_profile.recalculated` (`calc` del score, el nivel y la tendencia) + resumen del barrido;
  - `analytics.kpis.calculated`, `analytics.snapshot.built`, `analytics.report.generated` / `analytics.report.failed` y `analytics.reports.expired`;
  - `tenant_config.defaults.applied` / `tenant_config.setting.updated`;
  - `copilot.intent.routed`, `copilot.message.answered` (tokens, latencia, herramientas) y `copilot.narration.fallback`.

## 6. Pruebas

- **Por fase:** cada código nuevo tiene al menos un test de feature que recorre la rama real (DB real, sin mocks de DB) y afirma el código, su `reason` y los campos de `calc` clave. En los cálculos (riesgo, SLA, cobro) el test comprueba que los términos de `calc` reconstruyen el `result`.
- **Aislamiento:** los tests de fase afirman que `extra.team_id` de cada línea coincide con el tenant del registro. Ninguna línea de un recorrido lleva datos de otro tenant; se reutiliza la idea de `assertNoTenantLeak`.
- **Redacción:** unit tests del processor para cada clave y patrón, incluidos casos anidados, y la garantía de que no destruye ids ni ULIDs.
- **Guardia:** el test arquitectural de §4.6 en verde.
- **Gate de cada PR:** Pint, la suite completa, lint/format y types (`composer ci:check`).

## 7. Riesgos y mitigaciones

| Riesgo | Mitigación |
|---|---|
| Volumen (telemática cada 5 s por tenant, eventos por minuto) | `debug` en rutas calientes, un resumen por ciclo en `info`, retención de 7 días en archivo y `LOG_JSON_LEVEL` independiente. |
| Coste de CPU del processor | Recorrido recursivo solo sobre arrays pequeños, regex precompiladas y medición en el test de volumen de la fase 1 (1 000 líneas por debajo del presupuesto de la suite). |
| El redactor tapa datos útiles (`name` en claves técnicas) | Allowlist explícita de claves exactas y tests que la cubren. |
| Romper el formato de `telematics.log` que alguien lea a mano | Estamos en pre-prod y los cambios que rompen son aceptables; lo documenta el catálogo. |
| Mezclar tenants en una traza | `PipelineTrace` ya lo impide; los tests de fase lo afirman sobre los logs. |

## 8. Hallazgos que no arregla este trabajo

- `HeuristicRulesRunner` lee `signals.recent_duplicates_count` y `payload.signature`/`event_signature`, que nadie produce, así que el atajo por reglas parece no activarse nunca. En la fase 3 queda visible (`ai.heuristics.evaluated`, con `input` que muestra las claves ausentes). El arreglo va aparte.
- `RuleConditionEvaluator` evalúa como `false`, en silencio, las condiciones mal formadas o con operador desconocido. La fase 3 emite `decisions.rule.invalid` (`degraded`).
- `EnsureTeamMembership` hace `forceSwitchTeam` de un super-admin sin auditarlo. La fase 6 lo registra; decidir si además va a `audit_logs` queda aparte.
- `WebhookEvent.raw_payload`/`signature`, `AIInferenceLog.input_snapshot_json` y `last_error_message` guardan datos crudos en la DB. Están fuera de este alcance (son DB, no logs), pero conviene revisarlos en seguridad.
