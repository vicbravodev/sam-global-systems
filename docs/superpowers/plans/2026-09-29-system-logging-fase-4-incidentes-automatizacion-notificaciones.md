# Logging narrativo — Fase 4: Incidentes + Automatización + Notificaciones — Plan de implementación

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Que el tramo de salida del pipeline deje su línea narrativa en cada rama y en cada número. El tramo cubre la apertura del incidente (carril rápido de emergencias, decisión → incidente, tipo, prioridad, dedup, ráfaga de `device_offline`, SLA), su vida posterior (reevaluación, asignación y on-call, watchdog de SLA, llamada de verificación, resolución externa), la automatización (workflow que casa o no, ejecución, acción completada, fallida, detenida o reintentada) y las notificaciones (destinatarios, selección de canales, envío, reintento, fallback, guard de escalación, status de Twilio, respuesta entrante). Cada número (SLA, ventanas, umbrales, backoff, presupuesto de intentos) se registra término a término, de modo que se pueda rehacer a mano.

**Architecture:** Sobre la fundación de la fase 1 (PR #150), el tramo de entrada de la fase 2 (PR #151) y la IA/decisiones de la fase 3 (PR #152): `App\Support\SystemLog`, `App\Support\LoggableCode`, `Tests\Concerns\AssertsSystemLog` y el catálogo `docs/SAM/logging.md`, que `LoggingConventionsTest` exige que contenga cada código literal. El único componente nuevo es `App\Domains\Automation\Support\ActionFailure` (Task 5). Hay tres patrones:
- **Líneas de cálculo** (`incidents.type.resolved`, `incidents.priority.resolved`, `automation.workflow.not_matched`, `notifications.channels.selected`, `notifications.recipients.resolved`…) se emiten donde se calcula el número. Afirman un cálculo sobre filas que ya existían, que es cierto al emitirse aunque la transacción del llamador revierta.
- **Líneas de hecho persistido** (`incidents.created`, `incidents.sla.calculated`, `incidents.dedup.linked`, `incidents.reevaluation.applied`, `incidents.assignment.resolved`, `automation.workflow.matched`, `notifications.notification.requested`, `notifications.inbound_reply.applied`…) se emiten con `DB::afterCommit(fn () => SystemLog::…)`, registrado justo después de la escritura. Laravel ejecuta el callback en el acto si no hay transacción abierta, lo ejecuta tras el commit de la transacción más externa si la hay, y lo descarta si revierte. Es necesario porque en este tramo casi todo corre dentro de una transacción ajena: `IncidentCreated` se despacha dentro de `CreateIncidentFromEvent::createOrLink`, `DecisionMade` dentro de `EvaluateDecisionRules` e `IncidentStatusChanged` dentro de `EscalateIncident`, y sus listeners son síncronos (ninguno es `ShouldQueue`). `app/Support/NavBadgeCache.php` ya usa el mismo mecanismo.
- **Líneas de un efecto externo** (`notifications.delivery.sent` / `failed`, `incidents.call_verification.placed`) afirman lo que dijo el proveedor. Se emiten directamente, justo después de la llamada: ninguno de esos sitios está en una transacción.

**Tech Stack:** Laravel 13 · PHP 8.5 · PHPUnit 13.

**Spec:** `docs/superpowers/specs/2026-09-28-system-logging-design.md` (§3 esquema, §5 "Fase 4", §8 hallazgos).

## Global Constraints

- Código `dominio.etapa.resultado` (regex `/^[a-z0-9_]+(\.[a-z0-9_]+){2,}$/`), en inglés snake_case. `reason` obligatorio en snake_case cuando `outcome` ≠ `ok`. Nivel: ok/skipped → info, degraded → warning, failed → error.
- **Se reutilizan los códigos de la fase 1**, ampliando sus `reason` en vez de duplicarlos: `incidents.call_verification.skipped` (hoy `no_voice_channel`, `no_phone_contact`), `incidents.call_verification.emergency_override`, `incidents.call_verification.placement_failed`, `automation.recipients.non_member_skipped`, `automation.webhook.connection_failed`, `notifications.delivery.skip_record_failed`, `notifications.inbound_reply.ignored` (hoy `no_keyword`, `unknown_token`), `notifications.inbound_reply.rejected` y `notifications.notification.cancelled`. `billing.messaging_*` no se toca: el costo de Twilio es de la fase 5.
- **Una línea nunca afirma algo que puede ser falso al emitirse.**
  - Todo hecho sobre un registro persistido (incidente creado o vinculado, SLA fijado, prioridad subida, asignación, workflow en marcha, notificación pedida, respuesta aplicada) va por `DB::afterCommit(...)`. Los valores se capturan por valor en el closure en el momento de la escritura, nunca se releen en el callback.
  - Nada anuncia el futuro. Ni "se notificará", ni "se escalará", ni "el job correrá": se registra lo que se **pidió** (`*_requested`, `job_requested: true`, `watchdog_requested: true`). Las colas tienen `after_commit => true` (`config/queue.php`), así que un job pedido dentro de una transacción que revierte nunca sale. El nombre del campo lo refleja.
  - "Enviado" solo con aceptación del proveedor: `notifications.delivery.sent` significa `DeliveryResult->success`. Para Twilio es `accepted` (hay SID, la entrega real llega por callback), y la línea lo dice con `awaiting_provider_confirmation: true` y `delivery_status: 'queued'`. `delivered` solo lo afirma `notifications.provider_status.applied`.
  - Los conteos llevan el alcance en el nombre: `recent_singles_count` (incidentes individuales de `device_offline` del tenant en la ventana), `candidates_count` (teléfonos de la cadena de verificación), `dropped_count_by_reason` (destinatarios descartados en esta notificación), `steps_count` frente a `queued_count` y `awaiting_confirmation_count`.
- **Claves que el redactor no enmascara.** `RedactSensitiveLogData` enmascara toda clave con un segmento `phone`, `email`, `name`, `address`, `token`, `body`, `payload`, `raw`… salvo que el último segmento sea técnico (`id`, `ids`, `count`, `type`, `status`, `class`, `mode`, `variant`, `present`, `length`, `bytes`, `source`, `strategy`, `key`). Por eso:
  - destinatarios: `recipient_id`, `recipient_type`, `recipients_count`, nunca `recipient_address` ni `recipient_name`;
  - teléfonos de verificación: `candidates_count`, `candidate_index`, `candidate_counts_by_source`, nunca `phone` ni `candidates`;
  - token de respuesta: `token_id`, nunca `token` ni el código de 4 caracteres;
  - cuerpo del mensaje: `body_length` si hace falta, nunca `body`, `subject` ni el `payload_json` de la entrega.
- **Nunca en un log:** teléfonos, emails ni nombres de destinatarios, choferes, activos u operadores; `contacts` de `TenantEscalationConfig.steps_json` ni de `voice.verification_contacts`; `title`, `summary` o `description` de un incidente; `decision_reason`; el `reason` de un escalamiento o de una revisión (texto en español); `subject`, `body_preview` ni el contenido renderizado de una notificación o plantilla; el texto de una respuesta SMS/WhatsApp, su remitente ni el código del token; `DeliveryResult->errorMessage`; `ActionExecution.error_message`; `getMessage()` de cualquier excepción del tramo. Los fallos de proveedor van **solo** como código: `provider_error_code` con `LoggableCode::guard`.
- **`error:` solo en excepciones sin texto controlado por terceros.** `ExecuteAction` interpola `target_reference` (puede ser un teléfono, un email o una URL) y ids de usuarios ajenos en sus mensajes, y `HandleVerificationCallAttemptFailure` recibe `'placement_failed: '.$e->getMessage()`. En esos sitios se registra `error_class` (`class_basename`) y el código estable del fallo, nunca `error: $e`.
- **Códigos configurables por el tenant o por el proveedor** pasan por `LoggableCode::guard()`: claves y valores de `trigger_conditions_json`, `notifications.out_of_band_min_severity`, `panic.auto_close_on_external_resolution`, `source_reference_id` de un disparo manual, `NotificationChannel->provider`, `channel_preference` del destinatario, `notification_type`, `decision_code`, `incident_type_code` del contexto, y `provider_status`/`ErrorCode`/`CallStatus` de Twilio. Los enums se registran con `->value`.
- **Nunca el id de otro tenant.** En las ramas que detectan un cruce se registra `team_matches: false` o un conteo, no el id ajeno:
  - `OpenEmergencyIncidentJob` con equipo que no coincide;
  - `ApplyReevaluationToIncident` con `team_mismatch`: no se registra el `incident_id`;
  - `ResolveOnCallOperator` con usuarios no miembros: `non_member_skipped_count`, no los ids;
  - `RunAutomationWorkflowJob` sin workflow disponible para el team: sin `automation_workflow_id`.
  Los `NotificationChannel` y los `AutomationWorkflow` con `team_id` null son de plataforma: sus ids sí se registran. Los ids de `NotificationTemplate` y `ActionTemplate` no se registran: basta con `template_resolved: bool`.
- **Cálculos recomputables:** cada `calc` trae todos los términos, los umbrales y los límites. El test de cada cálculo rehace el resultado con esos términos y lo compara con el resultado registrado **y** con el persistido o con el `delay` del job empujado (`Queue::assertPushed(..., fn ($job) => $job->delay …)`).
- Cada código nuevo tiene:
  - su fila en `docs/SAM/logging.md` (código | outcome | reason posibles | campos clave);
  - al menos un test de feature que recorre la rama real (DB real, factories y fixtures de los tests existentes listados en cada tarea) y la afirma con `assertSystemLogged(code, fn (array $c) => …)`, comprobando `reason` y los campos de `calc` clave.
- Cada archivo de test nuevo o tocado añade el trait `AssertsSystemLog` y llama `assertNoSensitiveDataLogged()` en al menos un test.
- **No cambiar comportamiento.** Solo se añaden líneas y las refactorizaciones mínimas que se nombran en cada tarea:
  - se conservan las firmas públicas; se extienden solo con parámetros opcionales o con métodos públicos nuevos de solo lectura (`explain`, `resolve…`), y el método original delega en el nuevo;
  - los métodos privados pueden cambiar su tipo de retorno para sacar los términos del cálculo;
  - los mensajes de excepción, los `error_message` y las entradas de timeline no cambian;
  - los tests existentes siguen verdes sin tocarlos, salvo para añadir el trait y aserciones nuevas.
- **Rutas repetitivas → `debug: true`:**
  - `incidents.emergency.fast_path` skipped con `not_emergency` y `no_event_type` (corre para cada evento normalizado);
  - `incidents.call_verification.skipped` con `not_panic`, `attempt_already_requested`, `not_pending` y `not_in_flight`;
  - `incidents.call_verification.status_ignored`;
  - `automation.workflow.not_matched`;
  - `automation.trigger.evaluated` con `matched_count = 0`;
  - `notifications.channels.selected` ok (una por destinatario);
  - `notifications.dedup.skipped` con `delivery_exists`;
  - `notifications.status_change.skipped` con `internal_status`;
  - `notifications.retry.skipped` con `not_failed`;
  - `notifications.provider_status.skipped` con `empty_status`, `not_advancing` y `not_a_notification_delivery`.
- Sin directorios nuevos en `app/`, sin cambios en `composer.json`/`package.json`. Commits `type: subject en minúsculas` sin trailers. PHPUnit, sin Pest. No mockear la DB. Nunca `git stash`.
- El worktree `.claude/worktrees/logging-fase-4` no tiene `.env` y no se crea: los tests se corren con `APP_KEY=base64:$(openssl rand -base64 32) php artisan test --compact …`. Los warnings de dotenv son esperados; 0 fallos = verde.

## Review Focus

1. **Ningún hecho persistido antes del commit.** Toda línea de hecho usa `DB::afterCommit`. Hay tests que fuerzan un rollback con `Event::listen(IncidentCreated::class, fn () => throw new RuntimeException('boom'))` y afirman `Incident::count() === 0` más `assertSystemNotLogged(...)` para:
   - `incidents.created` e `incidents.sla.calculated` (Task 2);
   - `incidents.assignment.resolved` (Task 3);
   - `incidents.call_verification.requested` (Task 4);
   - `automation.workflow.matched` (Task 5);
   - `notifications.notification.requested` (Task 6).
   En los mismos tests, `assertSystemLogged('incidents.type.resolved')` sigue en pie: es un cálculo.
2. **SLA, ventanas y demoras recomputables:**
   - `incidents.sla.calculated`: `base_at = max(opened_at, now_at)` y `sla_due_at = base_at + sla_seconds`, con su fuente (`tenant_override` / `priority_catalog`);
   - `incidents.offline_burst.aggregated`: `recent_singles_count + 1 >= effective_threshold`, con `effective_threshold = max(2, configured_threshold)`;
   - `incidents.ack_check.rearmed`: `max(1, next_offset_minutes - current_offset_minutes)` o `retry_minutes`;
   - `notifications.retry.scheduled`: `delays[max(0, min(attempt, count) - 1)]`;
   - `automation.action.retry_scheduled`: `(schedule[attempts] ?? last(schedule)) ?: 0`;
   - `incidents.call_verification.attempt_failed`: `min(cap, max(configured, candidates_count))`.
   Lo fijan los Tasks 2, 3, 4, 5 y 7.
3. **Silencios que dejan de serlo:**
   - selección de canales vacía (`notifications.channels.selected` skipped): hoy no queda ni en la DB;
   - fallback sin canal (`notifications.fallback.exhausted`): hoy el job termina sin rastro;
   - `NotifyEscalationLevel` sin supervisores (`incidents.escalation_level.notified` skipped);
   - on-call sin nadie (`incidents.assignment.resolved` skipped);
   - `CreateIncidentOnDecisionMade` con un desenlace que no abre incidente (`incidents.creation.skipped`).
   Lo fijan los Tasks 1, 3, 6 y 7.
4. **Sin PII ni texto libre:** ningún test encuentra en `json_encode($this->systemLogEntries())` el teléfono de un contacto (`+5215512345678`), el email o el nombre de un usuario, el código del token de respuesta, el cuerpo de la respuesta, el `title` del incidente, el `subject` de la notificación ni el `target_reference` de una acción. Lo fijan los Tasks 2, 4, 5, 6 y 7.
5. **Aislamiento:** ninguna línea lleva el id de otro tenant (Global Constraints). Hay tests que lo afirman en `IncidentDedupAndBurstTest::test_offline_burst_of_another_tenant_never_absorbs_this_tenants_event`, `ApplyReevaluationToIncidentTest::test_reevaluation_never_touches_another_tenants_incident`, `AssignOnCallOnIncidentCreatedTest::test_never_assigns_a_user_outside_the_team` y `RunAutomationWorkflowJobTenantGuardTest`.

---

## Mapa de archivos

| Archivo | Tarea |
|---|---|
| `app/Domains/Incidents/Listeners/OpenEmergencyIncidentOnEventNormalized.php`, `Jobs/OpenEmergencyIncidentJob.php`, `Listeners/CreateIncidentOnDecisionMade.php`, `Jobs/CreateIncidentJob.php` | 1 |
| `app/Domains/Incidents/Actions/CreateIncidentFromEvent.php`, `app/Domains/TenantConfig/Actions/ResolveIncidentSla.php` | 2 |
| `app/Domains/Incidents/Actions/ApplyReevaluationToIncident.php`, `Listeners/ApplyReevaluationOnDecisionMade.php`, `Listeners/AssignOnCallOnIncidentCreated.php`, `Actions/ResolveOnCallOperator.php`, `Jobs/AutoAssignIncidentJob.php`, `Jobs/CheckIncidentAcknowledgementJob.php`, `Actions/NotifyEscalationLevel.php`, `Actions/ApplyExternalResolution.php`, `Jobs/ApplyExternalResolutionJob.php` | 3 |
| `app/Domains/Incidents/Listeners/StartCallVerificationOnIncidentCreated.php`, `Actions/StartIncidentCallVerification.php`, `Jobs/PlaceVerificationCallJob.php`, `Jobs/EvaluateVerificationCallOutcomeJob.php`, `Actions/HandleVerificationCallAttemptFailure.php`, `Actions/EscalateUnverifiableIncident.php`, `app/Http/Controllers/Webhooks/TwilioVoiceController.php` | 4 |
| `app/Domains/Automation/Services/TriggerEscalationWorkflow.php`, `Jobs/RunAutomationWorkflowJob.php`, `Services/RunAutomationWorkflow.php`, `Jobs/ExecuteActionJob.php`, `Actions/ExecuteAction.php`, `Actions/RetryFailedAction.php`, `Support/ActionFailure.php` (nuevo) | 5 |
| `app/Domains/Notifications/Actions/SendNotification.php`, `Listeners/NotifyOnIncidentCreated.php`, `Listeners/NotifyOnIncidentStatusChanged.php`, `Actions/DispatchNotification.php`, `Actions/ResolveRecipients.php`, `Actions/SelectNotificationChannels.php`, `Actions/AttemptDelivery.php` | 6 |
| `app/Domains/Notifications/Listeners/RetryOrFallbackOnNotificationFailed.php`, `Jobs/RetryNotificationDeliveryJob.php`, `Jobs/FallbackNotificationChannelJob.php`, `Support/DeliveryEscalationGuard.php`, `Actions/ApplyTwilioStatusUpdate.php`, `Actions/ProcessInboundReply.php`, `app/Http/Controllers/Webhooks/TwilioStatusCallbackController.php` | 7 |
| `docs/SAM/logging.md` | todas (secciones Incidentes, Automatización y Notificaciones; párrafo "Líneas en debug") |
| Tests: métodos nuevos en los tests existentes del área (listados en cada tarea), que ya traen los fixtures | todas |

---

### Task 1: Carril rápido, decisión → incidente y el job de creación

**Files:**
- Modify: `app/Domains/Incidents/Listeners/OpenEmergencyIncidentOnEventNormalized.php`
- Modify: `app/Domains/Incidents/Jobs/OpenEmergencyIncidentJob.php`
- Modify: `app/Domains/Incidents/Listeners/CreateIncidentOnDecisionMade.php`
- Modify: `app/Domains/Incidents/Jobs/CreateIncidentJob.php`
- Modify: `docs/SAM/logging.md`
- Test: `tests/Feature/Domains/Incidents/{EmergencyFastPathTest,CreateIncidentOnDecisionMadeTest}.php`

Los dos listeners corren dentro de la transacción del llamador (normalización y `EvaluateDecisionRules`), así que sus líneas van por `DB::afterCommit`. Los jobs no están en una transacción y emiten directo.

**`OpenEmergencyIncidentOnEventNormalized::handle`** — sin cambiar la lógica:
- `wentSilentInMotion()` no cambia; su resultado se guarda en `$wasInMotion` y solo se calcula cuando `$type?->code === DetectOfflineAssetsJob::EVENT_TYPE_CODE`, como hoy.

| Rama | Llamada (dentro de `DB::afterCommit`) |
|---|---|
| `event_type_id === null` | `SystemLog::skipped('incidents.emergency.fast_path', reason: 'no_event_type', input: ['normalized_event_id' => $normalized->id], debug: true)` |
| `isEmergencyCode(...)` | `SystemLog::ok('incidents.emergency.fast_path', input: ['normalized_event_id' => $normalized->id, 'event_type_code' => $type?->code, 'category_code' => $type?->category?->code], calc: ['trigger' => 'emergency_code', 'was_in_motion' => null], result: ['priority_code' => IncidentPriorityCode::Critical->value, 'job_requested' => true])` |
| `device_offline` en movimiento | igual, con `calc: ['trigger' => 'offline_in_motion', 'was_in_motion' => true]` y `priority_code` = `IncidentPriorityCode::High->value` |
| `device_offline` parado | `SystemLog::skipped('incidents.emergency.fast_path', reason: 'offline_parked', input: [...], calc: ['was_in_motion' => false])` |
| cualquier otro tipo | `SystemLog::skipped('incidents.emergency.fast_path', reason: 'not_emergency', input: [...], debug: true)` |

**`OpenEmergencyIncidentJob::handle`:**

| Rama | Llamada |
|---|---|
| `$event === null` | `SystemLog::skipped('incidents.emergency.job_skipped', reason: 'event_missing', input: ['normalized_event_id' => $this->normalizedEventId])` |
| `(int) $event->team_id !== $this->teamId` | `… reason: 'team_mismatch', input: ['normalized_event_id' => …], calc: ['team_matches' => false]`. Ni `$this->teamId` ni `$event->team_id`: uno de los dos es ajeno. |
| `findExistingFor(...) !== null` | cambia el `if` por `$existing = $reevaluation->findExistingFor($event)`; si no es null: `… reason: 'incident_exists', input: [...], result: ['incident_id' => $existing->id]` |

La creación la narra el Task 2 (`incidents.created`, con `calc.fast_path = true` leído de `metadata_json.emergency_fast_path`).

**`CreateIncidentOnDecisionMade::handle`** (todas dentro de `DB::afterCommit`):

| Rama | Llamada |
|---|---|
| `$outcome === null` | `SystemLog::skipped('incidents.creation.skipped', reason: 'unknown_outcome', input: ['decision_id' => $decision->id], calc: ['outcome_code' => LoggableCode::guard($decision->outcome?->code)])` |
| `! surfacesToOperators()` | `… reason: 'outcome_not_surfaced', calc: ['outcome_code' => $outcome->value]` |
| `normalized_event_id === null` | `… reason: 'no_normalized_event', calc: ['outcome_code' => $outcome->value]` |
| pedido | `SystemLog::ok('incidents.creation.requested', input: ['decision_id' => $decision->id, 'normalized_event_id' => $decision->normalized_event_id], calc: ['outcome_code' => $outcome->value, 'priority_code' => LoggableCode::guard($context['priority_code'] ?? null), 'priority_source' => …, 'request_review' => isset($context['request_review'])], result: ['job_requested' => true])` |

`priority_source`: `'review_default_medium'` (RequireHumanReview), `'alert_default_low'` (Alert) o `'decision_priority'`. Nunca se registra `REVIEW_REASON` (es texto).

**`CreateIncidentJob::handle`:**

| Rama | Llamada |
|---|---|
| `$event === null` | `SystemLog::skipped('incidents.creation.skipped', reason: 'event_missing', input: ['normalized_event_id' => $this->normalizedEventId])` |
| `$existing !== null` | `SystemLog::ok('incidents.creation.routed_to_existing', input: ['normalized_event_id' => $event->id, 'decision_id' => $this->context['decision_id'] ?? null], result: ['incident_id' => $existing->id, 'decision_found' => $decision !== null])`, antes del `return`. El resultado de `$applyReevaluation->execute` lo narra el Task 3. |
| tras `$createIncidentFromEvent->execute` | `SystemLog::ok('incidents.auto_assign.requested', input: ['incident_id' => $incident->id], result: ['job_requested' => true])`, antes de `AutoAssignIncidentJob::dispatch` |

`flagForReview()` cambia `void` por `?string` (privado) y devuelve el motivo de no marcar, o null si marcó:
- `not_review_decision` (`! is_string($reason) || $decisionId === null`);
- `linked_to_other_incident` (`related_decision_id` o `related_event_id` no coinciden: el evento se vinculó por dedup);
- `not_open` (el estado ya no es `open`).

Tras la llamada: si marcó, `SystemLog::ok('incidents.review.flagged', input: ['incident_id' => $incident->id, 'decision_id' => $decisionId])`; si el motivo es `linked_to_other_incident` o `not_open`, `SystemLog::skipped('incidents.review.flagged', reason: $motivo, input: [...])`. `not_review_decision` no registra nada: es el caso normal. El `$reason` de la revisión nunca se registra.

- [ ] **Step 1: Write the failing tests**
  - `EmergencyFastPathTest` (con `eventOfType`):
    - `test_a_panic_dispatches_the_fast_path_as_soon_as_it_is_normalized` → `incidents.emergency.fast_path` ok, `calc.trigger === 'emergency_code'`, `result.priority_code === 'critical'`;
    - `test_a_unit_that_goes_silent_in_motion_opens_a_high_incident` → `trigger === 'offline_in_motion'`, `was_in_motion === true`, `priority_code === 'high'`;
    - test nuevo: `eventOfType('device_offline', 'maintenance', ['was_in_motion' => false])` → skipped `offline_parked`;
    - `test_non_emergencies_wait_for_the_ai` → skipped `not_emergency` con `level === 'debug'` (lee `systemLogEntries('incidents.emergency.fast_path')[0]['level']`);
    - `test_the_job_opens_a_critical_panic_incident_once` → en la segunda corrida, `incidents.emergency.job_skipped` / `incident_exists` con `result.incident_id` del primero;
    - `test_a_job_whose_team_does_not_match_its_event_does_nothing` → `team_mismatch` con `calc.team_matches === false`, y el JSON de las entradas no contiene el id del team ajeno como `team_id`.
  - `CreateIncidentOnDecisionMadeTest` (con `makeDecision`/`makeAsset`):
    - `test_non_actionable_outcome_is_ignored` y `test_log_only_outcome_is_ignored` → `outcome_not_surfaced` con su `outcome_code`;
    - `test_incident_outcome_dispatches_create_incident_job` → `incidents.creation.requested` con `priority_source === 'decision_priority'`;
    - `test_require_human_review_dispatches_flagged_medium_incident` → `priority_source === 'review_default_medium'`, `request_review === true`; el JSON no contiene `CreateIncidentOnDecisionMade::REVIEW_REASON`;
    - `test_review_job_opens_incident_in_review_status` → `incidents.review.flagged` ok;
    - `test_review_job_does_not_move_an_existing_open_incident_to_review` → `incidents.creation.routed_to_existing`;
    - `test_listener_run_via_job_creates_only_one_incident_for_repeat_dispatch` → la segunda corrida da `routed_to_existing` con el mismo `incident_id`.
  - `assertNoSensitiveDataLogged()` en cada archivo.

- [ ] **Step 2: Run to verify they fail** — `APP_KEY=base64:$(openssl rand -base64 32) php artisan test --compact tests/Feature/Domains/Incidents/EmergencyFastPathTest.php tests/Feature/Domains/Incidents/CreateIncidentOnDecisionMadeTest.php` → FAIL (`No se registró [incidents.emergency.fast_path]`, etc.).
- [ ] **Step 3: Implement** la tabla y el cambio de `flagForReview`.
- [ ] **Step 4: Catalog + run** — en la sección `### Incidentes (incidents)`, filas de `incidents.emergency.fast_path`, `incidents.emergency.job_skipped`, `incidents.creation.skipped`, `incidents.creation.requested`, `incidents.creation.routed_to_existing`, `incidents.auto_assign.requested` e `incidents.review.flagged`. Nota: "`*_requested` = pedido; las colas son `after_commit`, así que un pedido dentro de una transacción que revierte no sale". Corre `tests/Feature/Domains/Incidents` y `tests/Feature/Architecture/LoggingConventionsTest.php` → PASS.
- [ ] **Step 5: Commit** — `feat: log narrativo del carril rápido de emergencias y de la decisión que abre incidente`

---

### Task 2: Apertura del incidente — tipo, prioridad, dedup, ráfaga y SLA

**Files:**
- Modify: `app/Domains/Incidents/Actions/CreateIncidentFromEvent.php`
- Modify: `app/Domains/TenantConfig/Actions/ResolveIncidentSla.php`
- Modify: `docs/SAM/logging.md`
- Test: `tests/Feature/Domains/Incidents/{CreateIncidentFromEventTest,IncidentDedupAndBurstTest,IncidentDedupByTypeTest,IncidentSlaEscalationTest}.php`

**`ResolveIncidentSla` — refactor mínimo.** Nuevo `public function resolve(int $teamId, int $incidentPriorityId): array` que devuelve `array{sla_seconds: ?int, sla_source: 'tenant_override'|'priority_catalog'|'none'}` con la misma cascada. Es `tenant_override` si hay override con `sla_seconds` no null; si no, es `priority_catalog` cuando el catálogo da un valor no null; y `none` en otro caso. `execute()` pasa a `return $this->resolve($teamId, $incidentPriorityId)['sla_seconds'];`.

**`CreateIncidentFromEvent` — refactors privados** (la lógica no cambia):
- `resolveIncidentType()`: acumula `$tried` (los códigos consultados hasta encontrar uno) y registra la línea de cálculo antes de cada `return`.
- `resolvePriority()` → `array{priority: IncidentPriority, source: 'context_code'|'type_default'|'lowest_level_fallback', requested_found: bool}`. `createOrLink` usa `['priority']`.
- `raisePriorityIfHigher()` → `array{raised: bool, previous_priority_code: ?string, previous_level: ?int, candidate_priority_code: string, candidate_level: int}`. La condición y el `update` no cambian.
- `offlineBurst()` → `array{outcome: Incident|bool|null, calc: ?array}`. `calc` es null si el evento no es `device_offline`. Si no:
  - `window_minutes` = el `config(...)` ya leído;
  - `window_start` = `$threshold->toIso8601String()`;
  - `aggregate_found` = `$aggregate !== null`;
  - `recent_singles_count` = `$recentSingles` (null si hubo agregado: no se consulta, como hoy);
  - `configured_threshold` = `(int) config('incidents.offline_burst_threshold', self::OFFLINE_BURST_THRESHOLD)`;
  - `effective_threshold` = `$burstThreshold`.
- `findOpenDuplicate()` no cambia. Su `$threshold` se calcula una vez en `createOrLink` como `$dedupWindowMinutes = (int) config('incidents.duplicate_window_minutes', 30)` y `$dedupWindowStart = $this->windowStart($event, $dedupWindowMinutes)`, y se pasa como parámetro nuevo del privado (misma query).
- SLA: `$now = now();` antes de calcular `$slaDueAt`, y `max(now())` pasa a `max($now)`. El resultado es el mismo. `$sla = $this->resolveIncidentSla->resolve($teamId, $priority->id)` sustituye a `->execute(...)` y `$slaSeconds = $sla['sla_seconds']`.

**Líneas de cálculo** (directas; afirman un cálculo sobre el catálogo):

| Sitio | Llamada |
|---|---|
| `resolveIncidentType`, antes de cada `return` | `SystemLog::ok('incidents.type.resolved', input: ['normalized_event_id' => $event->id], calc: ['requested_code' => LoggableCode::guard($code), 'event_type_code' => $eventTypeCode, 'alias_code' => …, 'category_code' => $eventType?->category?->code, 'category_bucket_code' => …, 'candidates' => $candidates, 'tried' => $tried, 'used_last_resort' => bool], result: ['incident_type_id' => $type->id, 'incident_type_code' => $type->code])` |
| `createOrLink`, tras `resolvePriority` | `SystemLog::ok('incidents.priority.resolved', input: ['normalized_event_id' => $event->id, 'incident_type_id' => $incidentType->id], calc: ['source' => $resolved['source'], 'requested_code' => LoggableCode::guard($context['priority_code'] ?? null), 'requested_found' => $resolved['requested_found'], 'type_default_priority_id' => $incidentType->default_priority_id], result: ['priority_code' => $priority->code, 'priority_level' => (int) $priority->level])` |

`used_last_resort` = el `firstOrFail()` final ("catálogo sin buckets genéricos"). En ese caso `level` sigue siendo info: el incidente se abre igual.

**Líneas de hecho** (todas con `DB::afterCommit`, registradas en el punto indicado):

| Punto | Llamada |
|---|---|
| rama `$existing !== null`, tras `raisePriorityIfHigher` | `SystemLog::ok('incidents.dedup.linked', input: ['normalized_event_id' => $event->id, 'incident_type_id' => $incidentType->id], calc: ['window_minutes' => $dedupWindowMinutes, 'window_start' => $dedupWindowStart->toIso8601String(), 'matched_on' => $matchedOn, 'match_basis' => $matchBasis], result: ['existing_incident_id' => $existing->id, 'link_created' => $link->wasRecentlyCreated, 'priority_raised' => $raise['raised'], 'previous_priority_code' => …, 'new_priority_code' => $raise['raised'] ? $raise['candidate_priority_code'] : null])` |
| rama `$burst instanceof Incident` | `SystemLog::ok('incidents.offline_burst.aggregated', input: ['normalized_event_id' => $event->id], calc: [...$burstCalc, 'branch' => 'linked_to_aggregate'], result: ['aggregate_incident_id' => $burst->id, 'link_created' => $link->wasRecentlyCreated])` |
| tras `Incident::create`, si `$aggregateBurst` | igual, con `branch` = `'opened_aggregate'` y `aggregate_incident_id` = `$incident->id` |
| tras `CheckIncidentAcknowledgementJob::dispatch` (o en su lugar si `$slaDueAt === null`) | ver "SLA" abajo |
| justo antes de `IncidentCreated::dispatch($fresh)` | `SystemLog::ok('incidents.created', …)`, ver abajo |

Definiciones:
- **`$link`:** el `IncidentEventLink` que ya devuelve `LinkEventToIncident::execute` (hoy se descarta).
- **`$matchedOn`:** `'asset'` si `$event->asset_id !== null && $existing->asset_id === $event->asset_id`; si no, `'driver'`. Refleja el `orWhere` de la query sin repetirla.
- **`$matchBasis`:** `'opened_in_window'` si `$existing->opened_at->gte($dedupWindowStart)`; si no, `'linked_event_in_window'` (la rama `orWhereHas('eventLinks…')` de `activeSince`).
- **`recent_singles_count` / umbral:** en `opened_aggregate` el test rehace `recent_singles_count + 1 >= effective_threshold` y `effective_threshold === max(2, configured_threshold)`.
- **Ráfaga por debajo del umbral:** no emite línea propia. `incidents.created.calc.offline_burst` lleva `$burstCalc` con `branch = 'below_threshold'`.

**SLA** (`DB::afterCommit`):
- con `$slaSeconds !== null`: `SystemLog::ok('incidents.sla.calculated', input: ['incident_id' => $incident->id, 'incident_priority_id' => $priority->id, 'team_id' => $teamId], calc: ['sla_seconds' => $slaSeconds, 'sla_source' => $sla['sla_source'], 'opened_at' => $openedAt->toIso8601String(), 'now_at' => $now->toIso8601String(), 'base_at' => $openedAt->copy()->max($now)->toIso8601String(), 'base_source' => $now->gt($openedAt) ? 'now' : 'opened_at', 'late_by_seconds' => max(0, (int) $openedAt->diffInSeconds($now, false)), 'backfill_adjusted' => $now->gt($openedAt)], result: ['sla_due_at' => $slaDueAt->toIso8601String(), 'watchdog_requested' => true])`;
- con `$slaSeconds === null`: `SystemLog::skipped('incidents.sla.calculated', reason: 'no_sla_for_priority', input: [...], calc: ['sla_source' => 'none'], result: ['watchdog_requested' => false])`.

`base_source` y `late_by_seconds` hacen visible que el SLA casi siempre corre desde `now`: el evento llega segundos o minutos después de ocurrir. `backfill_adjusted` es true cuando la base se movió de `opened_at` a `now`.

**`incidents.created`** (`DB::afterCommit`):

```php
SystemLog::ok('incidents.created',
    input: [
        'normalized_event_id' => $event->id,
        'decision_id' => $context['decision_id'] ?? null,
        'source_type' => $sourceType->value,
    ],
    calc: [
        'fast_path' => ($context['metadata']['emergency_fast_path'] ?? false) === true,
        'dedup_checked' => $event->asset_id !== null || $event->driver_id !== null,
        'dedup_window_minutes' => $dedupWindowMinutes,
        'offline_burst' => $burstCalc,          // null si no es device_offline
        'aggregate_burst' => $aggregateBurst,
        'resolved_on_arrival' => ($event->payload_normalized_json['is_resolved'] ?? null) === true,
    ],
    result: [
        'incident_id' => $fresh->id,
        'incident_type_code' => $fresh->type?->code,
        'priority_code' => $fresh->priority?->code,
        'status_code' => $fresh->status?->code,
        'asset_id' => $fresh->asset_id,
        'driver_id' => $fresh->driver_id,
        'usage_event_key' => 'incident_workflows:'.$fresh->id,
    ],
);
```

Nunca `title`, `summary` ni `metadata_json` completo. `DB::afterCommit` se registra **antes** de `IncidentCreated::dispatch`: así, en el commit, `incidents.created` sale antes que las líneas de los listeners (asignación, verificación, automatización y notificación), que también van por `afterCommit`.

- [ ] **Step 1: Write the failing tests**
  - `CreateIncidentFromEventTest` (setUp con `IncidentsSeeder`; `makeAsset`/`makeDriver`):
    - `test_creates_incident_with_correct_type_priority_and_links_event` → `incidents.created` con `result.incident_id`, `priority_code` y `status_code === 'open'`; `incidents.priority.resolved` con `source === 'context_code'`;
    - `test_safety_event_without_matching_incident_type_resolves_to_its_category_bucket` → `incidents.type.resolved` con `category_bucket_code === 'safety_violation'`, `used_last_resort === false` y `tried` que termina en el código elegido;
    - `test_panic_button_event_type_aliases_to_panic_emergency` → `alias_code === 'panic_emergency'`;
    - `test_unknown_event_type_resolves_to_the_generic_other_type` → `result.incident_type_code === 'other'`;
    - `test_sla_due_at_uses_tenant_override_when_present` → `incidents.sla.calculated` con `sla_source === 'tenant_override'` y `sla_seconds === 120`. Se rehace `Carbon::parse($c['calc']['base_at'])->addSeconds($c['calc']['sla_seconds'])->toIso8601String() === $c['result']['sla_due_at'] === $incident->sla_due_at->toIso8601String()` y `base_at === max(opened_at, now_at)`;
    - `test_sla_due_at_falls_back_to_priority_catalog` → `sla_source === 'priority_catalog'`, `sla_seconds === 300`;
    - test nuevo con `priority_code => 'low'` (catálogo `sla_seconds` null) → skipped `no_sla_for_priority`, `watchdog_requested === false`;
    - `test_does_not_create_duplicate_when_open_incident_exists_for_same_asset` → `incidents.dedup.linked` con `matched_on === 'asset'`, `window_minutes === 30` y `existing_incident_id` = el primero;
    - **rollback** (test nuevo): `Event::listen(IncidentCreated::class, fn () => throw new RuntimeException('boom'))`, `execute` lanza. Afirma `Incident::count() === 0`, `assertSystemNotLogged('incidents.created')`, `assertSystemNotLogged('incidents.sla.calculated')` y `assertSystemLogged('incidents.type.resolved')`;
    - texto: el JSON de las entradas no contiene el `title` del incidente creado ni `'Creado automáticamente'`.
  - `IncidentDedupAndBurstTest`:
    - `test_device_offline_burst_collapses_into_one_aggregated_incident` → un `incidents.offline_burst.aggregated` con `branch === 'opened_aggregate'`, `recent_singles_count === 2`, `effective_threshold === 3`, que cumple `recent_singles_count + 1 >= effective_threshold`, y dos con `branch === 'linked_to_aggregate'` y el mismo `aggregate_incident_id`. Los dos primeros `incidents.created` llevan `calc.offline_burst.branch === 'below_threshold'`;
    - `test_a_steady_event_stream_keeps_one_incident_beyond_the_opening_window` → el tercer `incidents.dedup.linked` tiene `match_basis === 'linked_event_in_window'`;
    - `test_late_event_sla_runs_from_now_not_from_when_it_happened` → `base_source === 'now'`, `late_by_seconds >= 36000`, `backfill_adjusted === true`;
    - `test_offline_burst_of_another_tenant_never_absorbs_this_tenants_event` → ninguna entrada `incidents.offline_burst.aggregated` del teamB lleva un `aggregate_incident_id` del teamA.
  - `IncidentDedupByTypeTest`:
    - `test_a_more_severe_supporting_event_raises_the_incident_priority` → `incidents.dedup.linked` con `priority_raised === true` y `previous_priority_code`/`new_priority_code`;
    - `test_a_less_severe_supporting_event_never_lowers_the_priority` → `priority_raised === false` y `new_priority_code === null`.
  - `IncidentSlaEscalationTest::test_create_incident_from_event_sets_sla_and_arms_the_watchdog` → `incidents.sla.calculated.result.sla_due_at` igual al `delay` del `CheckIncidentAcknowledgementJob` empujado.
  - `assertNoSensitiveDataLogged()` en cada archivo.

- [ ] **Step 2: Run to verify they fail** — `APP_KEY=… php artisan test --compact tests/Feature/Domains/Incidents/CreateIncidentFromEventTest.php tests/Feature/Domains/Incidents/IncidentDedupAndBurstTest.php tests/Feature/Domains/Incidents/IncidentDedupByTypeTest.php tests/Feature/Domains/Incidents/IncidentSlaEscalationTest.php` → FAIL.
- [ ] **Step 3: Implement** — `ResolveIncidentSla::resolve`, los retornos privados, `$now` y las líneas.
- [ ] **Step 4: Catalog + run**
  - Filas: `incidents.type.resolved`, `incidents.priority.resolved`, `incidents.dedup.linked`, `incidents.offline_burst.aggregated`, `incidents.sla.calculated` (con las fórmulas) e `incidents.created`.
  - Nota: "líneas de hecho por `DB::afterCommit`: salen tras el commit más externo y nunca si revierte".
  - Corre `tests/Feature/Domains/Incidents`, `tests/Feature/Domains/TenantConfig` y `LoggingConventionsTest` → PASS.
- [ ] **Step 5: Commit** — `feat: log narrativo de la apertura de incidentes con tipo, prioridad, dedup, ráfaga y sla recomputable`

---

### Task 3: Después de abrir — reevaluación, asignación, watchdog de SLA y resolución externa

**Files:**
- Modify: `app/Domains/Incidents/Actions/ApplyReevaluationToIncident.php`
- Modify: `app/Domains/Incidents/Listeners/ApplyReevaluationOnDecisionMade.php`
- Modify: `app/Domains/Incidents/Actions/ResolveOnCallOperator.php`
- Modify: `app/Domains/Incidents/Listeners/AssignOnCallOnIncidentCreated.php`
- Modify: `app/Domains/Incidents/Jobs/AutoAssignIncidentJob.php`
- Modify: `app/Domains/Incidents/Jobs/CheckIncidentAcknowledgementJob.php`
- Modify: `app/Domains/Incidents/Actions/NotifyEscalationLevel.php`
- Modify: `app/Domains/Incidents/Actions/ApplyExternalResolution.php`
- Modify: `app/Domains/Incidents/Jobs/ApplyExternalResolutionJob.php`
- Modify: `docs/SAM/logging.md`
- Test: `tests/Feature/Domains/Incidents/{ApplyReevaluationToIncidentTest,AssignOnCallOnIncidentCreatedTest,AutoAssignIncidentJobTest,IncidentSlaEscalationTest,SlaEscalationRecipientPolicyTest,HumanControlSuppressionTest,ApplyExternalResolutionTest}.php`

**`ApplyReevaluationToIncident::execute`.** Corre dentro de `EvaluateDecisionRules` (vía `ApplyReevaluationOnDecisionMade`) o suelto (vía `CreateIncidentJob`). Todas sus líneas van por `DB::afterCommit`.

| Rama | Llamada |
|---|---|
| `decision->team_id !== incident->team_id` | `SystemLog::skipped('incidents.reevaluation.applied', reason: 'team_mismatch', input: ['decision_id' => $decision->id], calc: ['team_matches' => false])`. Sin `incident_id`: es de otro tenant. |
| incidente no encontrado tras el lock | `… reason: 'incident_missing', input: ['decision_id' => …, 'incident_id' => $incident->id]` |
| raíz con `currentDecisionId >= decision->id` | `… reason: 'decision_not_newer', calc: ['current_decision_id' => $currentDecisionId, 'is_root_event' => true]` |
| `$alreadyApplied` | `… reason: 'already_applied'` |
| aplicado, tras `broadcast(...)` | `SystemLog::ok('incidents.reevaluation.applied', input: ['incident_id' => $incident->id, 'decision_id' => $decision->id, 'decision_code' => LoggableCode::guard($decision->decision_code)], calc: ['is_root_event' => $isRootEvent, 'is_terminal' => $isTerminal, 'previous_decision_id' => $currentDecisionId, 'previous_priority_code' => $previousPriority?->code, 'previous_level' => $previousPriority?->level, 'decision_priority_level' => $decision->priority_level?->value, 'mapped_priority_code' => $newPriority?->code, 'priority_alias_used' => …, 'mapped_level' => $newPriority?->level], result: ['priority_raised' => $raisePriority, 'related_decision_moved' => $isRootEvent, 'false_positive_notice' => ! $isTerminal && $classification === EventClassification::FalsePositive, 'evaluation_version' => $version, 'classification' => $classification?->value])` |

- **`priority_alias_used`:** `$newPriority !== null && $newPriority->code !== $decision->priority_level?->value` (se aplicó `PRIORITY_ALIASES`).
- El test rehace `priority_raised === (! is_terminal && mapped_level !== null && mapped_level > (previous_level ?? 0))`.
- Nunca `decision_reason` ni el título de la línea de tiempo.

`ApplyReevaluationOnDecisionMade::handle`: evento normalizado no encontrado → `SystemLog::skipped('incidents.reevaluation.applied', reason: 'event_missing', input: ['decision_id' => $decision->id])`, por `afterCommit`. Sin incidente para el evento no registra nada: es el caso normal de la primera decisión.

**`ResolveOnCallOperator` — método nuevo, de solo lectura.** `public function explain(int $teamId, ?DateTimeInterface $at = null): array` devuelve `array{user_id: ?int, source: 'shift'|'fallback'|null, reason: ?string, calc: array}` con el cuerpo actual. `execute()` pasa a `return $this->explain($teamId, $at)['user_id'];`.
- `reason` (cuando `user_id` es null): `no_active_profile` (`$profile === null`), `no_shift_rules` (`! is_array($rules)`) o `no_eligible_member` (ningún turno casó con un miembro y no hay fallback miembro).
- `calc`:
  - `profile_present`;
  - `local_time` (`H:i`) y `local_day` (`englishDayOfWeek` en minúsculas), en la zona del perfil. La zona no se registra: es texto del tenant y `LoggableCode` no admite `/`;
  - `shifts_count`;
  - `matched_shift_index` (índice del turno ganador, o null);
  - `shifts_matched_count` (turnos que casaron por horario, sean o no miembros);
  - `non_member_skipped_count` (candidatos que casaron pero no son miembros; **nunca sus ids**);
  - `fallback_configured` (bool);
  - `fallback_is_member` (bool|null).

**`AssignOnCallOnIncidentCreated::handle`** (todas por `DB::afterCommit`):

| Rama | Llamada |
|---|---|
| `currentAssignment()->exists()` | `SystemLog::skipped('incidents.assignment.resolved', reason: 'already_assigned', input: ['incident_id' => $incident->id, 'stage' => 'on_call_listener'])` |
| `$userId === null` | `… reason: $explain['reason'], calc: $explain['calc']` |
| asignado | `SystemLog::ok('incidents.assignment.resolved', input: ['incident_id' => …, 'stage' => 'on_call_listener'], calc: [...$explain['calc'], 'source' => $explain['source']], result: ['assignee_type' => AssigneeType::User->value, 'assignee_user_id' => $userId, 'assignment_id' => $assignment->id, 'role' => 'on_call'])`. `$assignment` es el valor que ya devuelve `AssignIncident::execute`. |
| prioridad no crítica (el `if` que hoy no llama a `notifyAssignee`) | `SystemLog::skipped('incidents.on_call.notified', reason: 'not_critical', input: ['incident_id' => …, 'assignee_user_id' => $userId])` |
| `notifyAssignee`, usuario null o sin email | `… reason: 'user_without_email'`. `notifyAssignee()` (privado) pasa de `void` a `?Notification`: devuelve el retorno de `SendNotification::execute`, o null en este caso. |
| `notifyAssignee`, pedido | `SystemLog::ok('incidents.on_call.notified', input: [...], result: ['notification_id' => $notification->id, 'forced_channel_types' => [ChannelType::Web->value]])` |

**`AutoAssignIncidentJob::handle`** (directo, no está en una transacción):
- incidente no encontrado → `SystemLog::skipped('incidents.assignment.resolved', reason: 'incident_missing', input: ['incident_id' => $this->incidentId, 'stage' => 'auto_assign_job'])`;
- `$alreadyAssigned` → `… reason: 'already_assigned', stage: 'auto_assign_job'`;
- asignado → `SystemLog::ok('incidents.assignment.resolved', …, calc: ['source' => 'default_queue'], result: ['assignee_type' => 'queue', 'assignment_id' => …, 'role' => 'default'])`.

**`CheckIncidentAcknowledgementJob::handle`** (directo). `input` común: `['incident_id' => $this->incidentId, 'level' => $this->level, 'attempt' => $this->attempt]`.

| Rama | Llamada |
|---|---|
| incidente null o sin team | `SystemLog::skipped('incidents.ack_check.skipped', reason: 'incident_missing', input: …)` |
| `acknowledged_at !== null` | `… reason: 'acknowledged'` |
| `isTerminal()` | `… reason: 'terminal'` |
| `isUnderHumanControl` | `… reason: 'human_control'` |
| entrega temprana | `… reason: 'not_due_yet', calc: ['sla_due_at' => $incident->sla_due_at->toIso8601String(), 'now_at' => now()->toIso8601String(), 'seconds_until_due' => (int) now()->diffInSeconds($incident->sla_due_at, false)]` |
| tras el bloque `attempt === 1` y `notifyLevel` | `SystemLog::ok('incidents.ack_check.breached', input: …, calc: ['first_breach' => $this->attempt === 1, 'status_before' => $statusBefore, 'steps_count' => count($steps)], result: ['escalated_now' => $escalatedNow, 'status_after' => $incident->status?->code])` |
| `scheduleNext`, reintento del nivel | `SystemLog::ok('incidents.ack_check.rearmed', input: …, calc: ['mode' => 'retry_same_level', 'step_attempts' => $stepAttempts, 'retry_minutes' => $retryMinutes, 'default_retry_minutes' => self::DEFAULT_RETRY_MINUTES], result: ['next_level' => $this->level, 'next_attempt' => $this->attempt + 1, 'delay_minutes' => $retryMinutes])` |
| `scheduleNext`, siguiente nivel | igual, con `calc: ['mode' => 'next_level', 'step_attempts' => …, 'current_offset_minutes' => $currentOffset, 'next_offset_minutes' => $nextOffset]` y `result: ['next_level' => $nextLevel, 'next_attempt' => 1, 'delay_minutes' => $delayMinutes]` |
| `$next === null` | `SystemLog::skipped('incidents.ack_check.chain_exhausted', reason: 'no_next_level', input: …, calc: ['steps_count' => count($steps), 'step_attempts' => $stepAttempts])` |

- **`$statusBefore`:** `$incident->status?->code` antes de escalar.
- **`$escalatedNow`:** true si se llamó a `EscalateIncident`.
- `scheduleNext` es privado y recibe además el `$incident` para el `input`.
- Nunca `$incident->title` (va en el `subject` del aviso).

**`NotifyEscalationLevel::execute`.** Corre suelto (watchdog) o dentro de una transacción (vía `EscalateUnverifiableIncident` desde un listener de `IncidentCreated`), así que va por `DB::afterCommit`.
- sin supervisores → `SystemLog::skipped('incidents.escalation_level.notified', reason: 'no_supervisors', input: ['incident_id' => $incident->id, 'level' => $level, 'notification_type' => $notificationType], calc: ['step_present' => $step !== null, 'contacts_count' => 0])`. Hoy es un `return` silencioso: un nivel sin contactos y sin admins/supervisores no avisa a nadie;
- pedido → `SystemLog::ok('incidents.escalation_level.notified', input: [...], calc: ['step_present' => …, 'recipients_source' => $contacts !== [] ? 'step_contacts' : 'supervisors', 'contacts_count' => count($contacts), 'recipients_count' => count($payload['recipients']), 'step_channel_types' => $channels, 'urgent' => $urgent ?? null, 'forced_channel_types' => $payload['force_channels'] ?? null], result: ['notification_id' => $notification->id])`. `$notification` es el retorno de `SendNotification::execute`, que hoy se descarta;
- nunca `contacts`, `recipients` ni `subject`/`body`.

**`ApplyExternalResolution::execute`** (por `DB::afterCommit`: `CreateIncidentFromEvent` la llama dentro de su transacción):
- `resolveExternalResolvedAt()` (privado) devuelve `array{at: Carbon, source: 'payload'|'event_occurred_at'}`;
- `external_resolved_at !== null` → `SystemLog::skipped('incidents.external_resolution.applied', reason: 'already_annotated', input: ['incident_id' => $incident->id, 'normalized_event_id' => $event->id])`;
- anotado → `SystemLog::ok('incidents.external_resolution.applied', input: [...], calc: ['resolved_at_source' => …, 'allow_close' => $allowClose, 'was_terminal' => $incident->isTerminal(), 'mode' => $modeForLog], result: ['external_resolved_at' => …->toIso8601String(), 'closed' => $closed])`, emitido al final del método;
- `$modeForLog`: null si no se consultó (por `! $allowClose || isTerminal()`); si se consultó, `LoggableCode::guard($mode)`.

**`ApplyExternalResolutionJob::handle`** (directo):
- evento null o no resuelto → `SystemLog::skipped('incidents.external_resolution.matched', reason: 'not_resolved', input: ['normalized_event_id' => $this->normalizedEventId])`;
- `findOpenIncidents()` (privado) devuelve además `strategy`: `external_event_id`, `asset_window` o `none`. Con resultado vacío → `… reason: 'no_open_incident', calc: ['strategy' => 'none', 'external_event_id_present' => …, 'window_minutes' => $window]`;
- con resultado → `SystemLog::ok('incidents.external_resolution.matched', input: [...], calc: ['strategy' => …, 'window_minutes' => …], result: ['incident_ids' => …, 'incidents_count' => …])`.

- [ ] **Step 1: Write the failing tests**
  - `ApplyReevaluationToIncidentTest` (con `decisionFor`/`runDecision`):
    - `test_v2_escalate_raises_priority_on_the_same_incident` → `incidents.reevaluation.applied` con `priority_raised === true`, y se rehace la condición con `is_terminal`, `mapped_level` y `previous_level`;
    - `test_v2_false_positive_adds_notice_and_keeps_incident_open` → `false_positive_notice === true`, `priority_raised === false`;
    - `test_applying_an_older_decision_is_a_no_op` → `decision_not_newer`;
    - `test_reevaluation_never_touches_another_tenants_incident` → `team_mismatch` sin `input.incident_id`.
  - `AssignOnCallOnIncidentCreatedTest` (con `makeScheduleProfile`/`makeIncident`):
    - `test_assigns_on_call_operator_and_notifies_for_critical_incident` → `incidents.assignment.resolved` con `source === 'shift'`, `matched_shift_index === 0`, `assignee_user_id` = operador; `incidents.on_call.notified` ok. El JSON no contiene el email ni el nombre del operador;
    - `test_respects_shift_windows_and_falls_back` → `source === 'fallback'`, `shifts_matched_count === 0`, `fallback_is_member === true`;
    - `test_does_nothing_without_schedule_profile` → skipped `no_active_profile`;
    - `test_never_assigns_a_user_outside_the_team` → `no_eligible_member` con `non_member_skipped_count === 1`, y el JSON no contiene el id del usuario ajeno como valor de ningún campo `*_user_id`;
    - `test_skips_incidents_that_already_have_an_assignment` → `already_assigned`;
    - `test_assigns_without_directed_notification_for_non_critical` → `incidents.on_call.notified` / `not_critical`;
    - **rollback** (test nuevo): perfil con turno, `Event::listen(IncidentCreated::class, fn () => throw new RuntimeException('boom'))` registrado en el test, y `app(CreateIncidentFromEvent::class)->execute(...)` lanza → `assertSystemNotLogged('incidents.assignment.resolved')`.
  - `AutoAssignIncidentJobTest`: los tres tests → `default_queue`, `already_assigned` y `already_assigned`, con `stage === 'auto_assign_job'`.
  - `IncidentSlaEscalationTest` (con `makeOpenIncident`/`makeEscalationConfig`/`runWatchdog`):
    - `test_acknowledged_incident_never_escalates` → `acknowledged`;
    - `test_terminal_incident_stops_the_chain` → `terminal`;
    - `test_early_delivery_never_escalates_before_the_sla` → `not_due_yet` con `seconds_until_due > 0`;
    - `test_unacknowledged_breach_escalates_notifies_and_rearms` → `incidents.ack_check.breached` con `first_breach === true`, `escalated_now === true`, `status_after === 'escalated'`; `incidents.ack_check.rearmed` con `mode === 'next_level'` y `delay_minutes === max(1, next_offset_minutes - current_offset_minutes)`, igual al `delay` del job empujado con el tiempo congelado;
    - `test_step_attempts_retry_the_same_level_before_advancing` → `mode === 'retry_same_level'`, `next_attempt === 2`, `delay_minutes === retry_minutes`;
    - `test_chain_exhausts_after_the_last_level` → `incidents.ack_check.chain_exhausted`.
  - `SlaEscalationRecipientPolicyTest`:
    - `test_without_escalation_config_only_supervisors_get_web_and_email` → `incidents.escalation_level.notified` con `recipients_source === 'supervisors'` y `forced_channel_types === ['web', 'email']`;
    - `test_configured_contacts_still_get_the_pinned_channels` → `step_contacts` con `contacts_count` > 0, y el JSON no contiene ninguno de los contactos del paso;
    - test nuevo sin admins ni supervisores → skipped `no_supervisors`.
  - `HumanControlSuppressionTest::test_escalation_watchdog_stops_when_incident_is_claimed` → `human_control`.
  - `ApplyExternalResolutionTest` (con `makePanicWithResolutionUpdate`/`setAutoCloseSetting`):
    - `test_annotates_incident_without_closing_by_default` → `applied` con `closed === false`, `mode === 'annotate'`;
    - `test_closes_incident_when_tenant_opted_into_auto_close` → `closed === true`;
    - `test_is_idempotent_and_does_not_duplicate_timeline_entries` → `already_annotated`;
    - `test_event_arriving_already_resolved_creates_annotated_incident_and_never_closes` → `allow_close === false`, `mode === null`;
    - `test_job_finds_incident_through_original_event_external_id` → `matched` con `strategy === 'external_event_id'`.
  - `assertNoSensitiveDataLogged()` en cada archivo.

- [ ] **Step 2: Run to verify they fail** — `APP_KEY=… php artisan test --compact tests/Feature/Domains/Incidents` → FAIL.
- [ ] **Step 3: Implement** — `ResolveOnCallOperator::explain`, los retornos privados y las líneas.
- [ ] **Step 4: Catalog + run**
  - Filas: `incidents.reevaluation.applied`, `incidents.assignment.resolved`, `incidents.on_call.notified`, `incidents.ack_check.skipped`, `incidents.ack_check.breached`, `incidents.ack_check.rearmed` (con la fórmula de la demora), `incidents.ack_check.chain_exhausted`, `incidents.escalation_level.notified`, `incidents.external_resolution.applied` e `incidents.external_resolution.matched`.
  - Nota: "`no_supervisors` y `no_eligible_member` antes eran silenciosos".
  - Corre `tests/Feature/Domains/Incidents`, `tests/Feature/Domains/Notifications` y `LoggingConventionsTest` → PASS.
- [ ] **Step 5: Commit** — `feat: log narrativo de reevaluación, asignación on-call, watchdog de sla y resolución externa`

---

### Task 4: Llamada de verificación

**Files:**
- Modify: `app/Domains/Incidents/Listeners/StartCallVerificationOnIncidentCreated.php`
- Modify: `app/Domains/Incidents/Actions/StartIncidentCallVerification.php`
- Modify: `app/Domains/Incidents/Jobs/PlaceVerificationCallJob.php`
- Modify: `app/Domains/Incidents/Jobs/EvaluateVerificationCallOutcomeJob.php`
- Modify: `app/Domains/Incidents/Actions/HandleVerificationCallAttemptFailure.php`
- Modify: `app/Domains/Incidents/Actions/EscalateUnverifiableIncident.php`
- Modify: `app/Http/Controllers/Webhooks/TwilioVoiceController.php`
- Modify: `docs/SAM/logging.md` (amplía la fila de `incidents.call_verification.skipped`)
- Test: `tests/Feature/Domains/Incidents/{IncidentCallVerificationTest,VerificationCallChainTest,PlaceVerificationCallJobTest,VerificationCallFailureHumanControlTest,HumanControlSuppressionTest}.php`, `tests/Feature/Http/Webhooks/TwilioVoiceWebhookTest.php`

`StartCallVerificationOnIncidentCreated` y `StartIncidentCallVerification` corren dentro de `CreateIncidentFromEvent` (vía `IncidentCreated`) y, en reintentos, sueltas. Sus líneas van por `DB::afterCommit`. Los jobs y el controller emiten directo.

**Refactor mínimo de `StartIncidentCallVerification`** (la lógica y los retornos no cambian):
- Nuevo `public function resolveCandidatesBySource(Incident $incident): array` que devuelve `array{driver: list<?string>, verification_contacts: list<?string>, escalation_steps: list<?string>, supervisors: list<?string>}` con los mismos cuatro bucles de hoy. `resolveCandidates()` pasa a aplanar ese array en ese orden y aplica el mismo `array_values(array_unique(array_filter(...)))`.
- Nuevo `public function attemptBudgetTerms(IncidentCallVerification $verification): array` que devuelve `array{configured_attempts: int, candidates_count: int, max_attempts_cap: int, budget: int}`. `attemptBudget()` pasa a `return $this->attemptBudgetTerms($verification)['budget'];`.

**Listener y `StartIncidentCallVerification::execute`:**

| Sitio / rama | Llamada (`DB::afterCommit`) |
|---|---|
| listener, tipo ≠ `panic_emergency` | `SystemLog::skipped('incidents.call_verification.skipped', reason: 'not_panic', input: ['incident_id' => $incident->id, 'incident_type_code' => $incident->type?->code], debug: true)` |
| listener, `! $enabled` | `… reason: 'disabled_by_tenant', calc: ['setting_key' => StartIncidentCallVerification::SETTING_ENABLED]` |
| `execute`, sin team o terminal | `… reason: 'incident_terminal', input: ['incident_id' => …, 'attempt' => $attempt]` |
| `execute`, existente en vuelo (`attempt === 1`) | `… reason: 'already_in_flight', result: ['verification_id' => $existing->id]` |
| `execute`, existente concluida | `… reason: 'already_concluded', result: ['verification_id' => $existing->id, 'status' => $existing->status->value]` |
| `execute`, reinicio tras supresión | sin línea propia: `requested.calc.restarted_after_suppression = true` |
| `execute`, `$existing->attempt >= $attempt` | `… reason: 'attempt_already_requested', result: ['verification_id' => $existing->id], debug: true` |
| `execute`, sin candidatos | la línea actual `no_phone_contact` gana `calc: ['candidate_counts_by_source' => $counts]` |
| `execute`, `wasRecentlyCreated` | `SystemLog::ok('incidents.call_verification.requested', input: ['incident_id' => $incident->id, 'attempt' => $attempt], calc: ['candidates_from' => $fromMetadata ? 'metadata' : 'resolved', 'candidates_count' => count($candidates), 'candidate_index' => ($attempt - 1) % count($candidates), 'candidate_counts_by_source' => $counts, 'restarted_after_suppression' => …], result: ['verification_id' => $verification->id, 'job_requested' => true])` |

- **`$counts`:** conteo por fuente de `resolveCandidatesBySource()` tras filtrar null/vacíos. Es null cuando `candidates_from = 'metadata'` (reintento: la lista viaja guardada y no se re-resuelve, como hoy).
- **Nunca** `phone`, `candidates`, `contacts` ni la descripción en español que se pasa a `EscalateUnverifiableIncident`.

**`PlaceVerificationCallJob::handle`** (directo). `input` común: `['verification_id' => $verification->id, 'incident_id' => $verification->incident_id, 'attempt' => $verification->attempt]`.

| Rama | Llamada |
|---|---|
| `null` o no `Pending` | `SystemLog::skipped('incidents.call_verification.skipped', reason: 'not_pending', input: ['verification_id' => $this->verificationId], debug: true)` |
| incidente null o terminal | `SystemLog::skipped('incidents.call_verification.closed', reason: 'incident_terminal', input: …)` |
| `isUnderHumanControl` | `… reason: 'human_control'` (`failure_reason = PlaceVerificationCallJob::SUPPRESSED_FAILURE_REASON`) |
| `emergency_override` (existente) | sin cambios |
| canal no disponible | la línea `no_voice_channel` existente gana `calc: ['channel_present' => $channel !== null, 'from_present' => $from !== null, 'credentials_present' => PlatformTwilioConfig::hasCredentials()]` |
| `placement_failed` (existente) | sin cambios. La llamada `$handleFailure->execute($verification, 'placement_failed: '.$e->getMessage())` tampoco cambia: ese texto solo llega a `metadata_json.failure_reason` en la DB, y `HandleVerificationCallAttemptFailure` registra solo su prefijo (ver abajo). |
| llamada colocada | `SystemLog::ok('incidents.call_verification.placed', input: …, calc: ['ring_timeout_seconds' => $config['ring_timeout_seconds'] ?? 25, 'configured_retry_delay_seconds' => $configuredDelay, 'retry_delay_seconds' => $retryDelay, 'min_retry_delay_seconds' => 30], result: ['call_sid' => LoggableCode::guard((string) ($call->sid ?? '')), 'provider_status' => LoggableCode::guard(…$call->status), 'channel_id' => $channel->id, 'safety_net_requested' => true], durationMs: $durationMs)`, tras el `EvaluateVerificationCallOutcomeJob::dispatch` |

- **`$durationMs`:** `SystemLog::elapsedMs($started)` alrededor de `$caller->createCall(...)`, con `$started = hrtime(true)`.
- **`$configuredDelay`:** el `(int) $tenantConfig->resolve(...)` antes del `max(30, …)`. El test rehace `retry_delay_seconds === max(min_retry_delay_seconds, configured_retry_delay_seconds)`.

**`EvaluateVerificationCallOutcomeJob::handle`:**
- no en vuelo → `SystemLog::skipped('incidents.call_verification.skipped', reason: 'not_in_flight', input: ['verification_id' => $this->verificationId], debug: true)`;
- temprana → `SystemLog::skipped('incidents.call_verification.safety_net', reason: 'not_due_yet', input: [...], calc: ['placed_at' => …, 'retry_delay_seconds' => $retryDelay, 'due_at' => $verification->placed_at->copy()->addSeconds($retryDelay)->toIso8601String()])`;
- vencida → `SystemLog::ok('incidents.call_verification.safety_net', input: [...], calc: [...], result: ['failure_code' => 'timeout_without_callback'])`, antes de `$handleFailure->execute`.

**`HandleVerificationCallAttemptFailure::execute`** (directo; lo llaman jobs y el webhook, nunca en una transacción):
- **`$failureCode`:** `LoggableCode::guard(strtok($reason, ':'))`. Es `placement_failed`, `timeout_without_callback` o `call_status`. El resto de `$reason` nunca se registra.
- **`$callStatus`:** si `$failureCode === 'call_status'`, `LoggableCode::guard(trim(substr($reason, strlen('call_status:'))))`.
- `$budget = $this->startVerification->attemptBudgetTerms($verification)`, y `$maxAttempts = $budget['budget']`.

| Rama | Llamada |
|---|---|
| no en vuelo | `SystemLog::skipped('incidents.call_verification.attempt_failed', reason: 'already_answered', input: ['verification_id' => …], calc: ['failure_code' => $failureCode])` |
| incidente terminal | `… reason: 'incident_terminal'` (el intento ya quedó en `no_answer`) |
| `isUnderHumanControl` | `… reason: 'human_control'` |
| queda presupuesto | `SystemLog::ok('incidents.call_verification.attempt_failed', input: ['verification_id' => …, 'incident_id' => …, 'attempt' => $verification->attempt], calc: ['failure_code' => $failureCode, 'call_status' => $callStatus, ...$budget], result: ['next' => 'next_attempt', 'next_attempt' => $verification->attempt + 1])` |
| agotado | igual, con `result: ['next' => 'exhausted_escalated', 'outcome' => CallVerificationOutcome::NoAnswer->value]`, emitido tras `notifyEscalationLevel->execute` |

El test rehace `budget === min(max_attempts_cap, max(configured_attempts, candidates_count))`. Nunca la `description` de la línea de tiempo (lista los teléfonos).

**`EscalateUnverifiableIncident::execute`** (`DB::afterCommit`: corre dentro del listener de creación):
- terminal → `SystemLog::skipped('incidents.call_verification.unverifiable_escalated', reason: 'incident_terminal', input: ['incident_id' => …, 'unverifiable_code' => $reason])`;
- `$alreadyRecorded` → `… reason: 'already_recorded'`;
- escalado → `SystemLog::ok(…, input: [...], result: ['escalated_now' => $escalatedNow, 'status_after' => $incident->status?->code])`.

`$reason` aquí es una constante del código (`no_phone_contact`, `voice_channel_unavailable`). `$description` nunca se registra.

**`TwilioVoiceController`** (directo). `input` común: `['verification_id' => $row->id, 'incident_id' => $row->incident_id, 'attempt' => $row->attempt]`.

| Sitio / rama | Llamada |
|---|---|
| `gather`, ya respondida | `SystemLog::skipped('incidents.call_verification.answered', reason: 'already_answered', input: …)` |
| `gather`, dígito inválido o ausente | `… reason: 'invalid_digit', calc: ['digits_length' => strlen($digits)]` (nunca los dígitos) |
| `gather`, incidente cerrado o inexistente | `… reason: 'incident_closed', result: ['outcome' => $outcome->value]` |
| `confirmReal`, al final | `SystemLog::ok('incidents.call_verification.answered', input: …, result: ['outcome' => CallVerificationOutcome::ConfirmedReal->value, 'acknowledged' => true, 'escalated' => true, 'level_notified' => 0])` |
| `confirmFalseAlarm`, al final | `SystemLog::ok(…, result: ['outcome' => CallVerificationOutcome::ConfirmedFalse->value, 'closed' => true, 'resolution_code' => ResolutionCode::FalsePositive->value])` |
| `status`, en vuelo y status final | sin línea propia: la emite `HandleVerificationCallAttemptFailure` con `failure_code = 'call_status'` |
| `status`, resto | `SystemLog::skipped('incidents.call_verification.status_ignored', reason: $row->status->isInFlight() ? 'status_not_final' : 'not_in_flight', input: …, calc: ['call_status' => LoggableCode::guard($callStatus)], debug: true)` |

Nunca `$row->phone`, `digits_received` ni el TwiML.

- [ ] **Step 1: Write the failing tests**
  - `IncidentCallVerificationTest` (con `enableVerification`/`makePanicIncident`/`handleCreated`):
    - `test_panic_incident_starts_a_verification_call_when_opted_in` → `incidents.call_verification.requested` con `candidates_from === 'resolved'`, `candidate_index === 0` y `candidate_counts_by_source['verification_contacts'] === 1`; el JSON no contiene `'+5215512345678'`;
    - `test_non_panic_incidents_never_trigger_the_call` → skipped `not_panic` en debug;
    - `test_start_is_idempotent_for_the_same_incident` → `already_in_flight` en la segunda;
    - `test_a_concluded_verification_is_never_restarted` → `already_concluded`;
    - `test_without_any_phone_contact_no_call_is_started` → `no_phone_contact` con `candidate_counts_by_source` todo en 0;
    - test nuevo con `setSetting(SETTING_ENABLED, false)` → `disabled_by_tenant`.
  - `VerificationCallChainTest`:
    - `test_the_driver_is_called_first_and_the_company_contact_next` → `candidate_counts_by_source['driver'] >= 1`, y el JSON no contiene el teléfono del chofer;
    - `test_every_contact_is_called_at_least_once_even_beyond_the_attempt_budget` → `attempt_failed` con `budget === min(max_attempts_cap, max(configured_attempts, candidates_count))`.
  - `PlaceVerificationCallJobTest` (con `makeVerification`/`runJob`):
    - `test_places_the_call_with_gather_twiml_and_chains_the_safety_net` → `incidents.call_verification.placed` con `retry_delay_seconds` igual al `delay` del `EvaluateVerificationCallOutcomeJob` empujado, `duration_ms` presente y `call_sid` del fake;
    - `test_fails_without_consuming_attempts_when_no_voice_channel_exists` → `no_voice_channel` con `channel_present === false`;
    - `test_does_not_call_without_platform_twilio_credentials` → `credentials_present === false`;
    - `test_placement_exception_chains_the_next_attempt` → `placement_failed` (existente) y `attempt_failed` con `failure_code === 'placement_failed'` y `next === 'next_attempt'`. El JSON no contiene el mensaje de la excepción del fake;
    - `test_exhausted_attempts_escalate_the_incident_with_no_answer_outcome` → `next === 'exhausted_escalated'`;
    - `test_terminal_incident_cancels_the_call` → `incidents.call_verification.closed` / `incident_terminal`.
  - `VerificationCallFailureHumanControlTest::test_exhausted_attempts_do_not_escalate_a_claimed_incident` → `attempt_failed` / `human_control`.
  - `HumanControlSuppressionTest::test_suppressed_verification_is_terminal_and_does_not_block_future_attempts` → `closed` / `human_control`, y luego `requested` con `restarted_after_suppression === true`.
  - `TwilioVoiceWebhookTest` (con `makeVerification`/`gather`/`postStatus`):
    - `test_digit_1_acknowledges_the_incident_as_real_emergency` → `answered` con `outcome === 'confirmed_real'`;
    - `test_digit_2_closes_the_incident_as_false_alarm` → `confirmed_false`, `closed === true`;
    - `test_invalid_digit_replays_the_prompt` → `invalid_digit` con `digits_length`;
    - `test_second_answer_is_idempotent` → `already_answered`;
    - `test_terminal_incident_answers_politely_without_acting` → `incident_closed`;
    - `test_unanswered_status_chains_the_next_attempt` → `attempt_failed` con `failure_code === 'call_status'` y `call_status === 'no-answer'`;
    - `test_status_callback_after_an_answer_is_a_no_op` → `status_ignored` / `not_in_flight`;
    - en todos, el JSON no contiene el `phone` de la verificación.
  - **rollback** (test nuevo en `IncidentCallVerificationTest`): `enableVerification()`, `Event::listen(IncidentCreated::class, fn () => throw new RuntimeException('boom'))` y `CreateIncidentFromEvent` de un pánico → `assertSystemNotLogged('incidents.call_verification.requested')`.
  - `assertNoSensitiveDataLogged()` en cada archivo.

- [ ] **Step 2: Run to verify they fail** — `APP_KEY=… php artisan test --compact tests/Feature/Domains/Incidents tests/Feature/Http/Webhooks/TwilioVoiceWebhookTest.php` → FAIL.
- [ ] **Step 3: Implement** — `resolveCandidatesBySource`, `attemptBudgetTerms` y las líneas.
- [ ] **Step 4: Catalog + run**
  - Amplía `incidents.call_verification.skipped` con `not_panic` (debug), `disabled_by_tenant`, `incident_terminal`, `already_in_flight`, `already_concluded`, `attempt_already_requested` (debug), `not_pending` (debug) y `not_in_flight` (debug), más el `calc` de `no_voice_channel` y `no_phone_contact`.
  - Filas nuevas: `incidents.call_verification.requested`, `.placed`, `.closed`, `.safety_net`, `.attempt_failed` (con la fórmula del presupuesto), `.unverifiable_escalated`, `.answered` y `.status_ignored`.
  - Corre `tests/Feature/Domains/Incidents`, `tests/Feature/Http/Webhooks` y `LoggingConventionsTest` → PASS.
- [ ] **Step 5: Commit** — `feat: log narrativo de la cadena de llamadas de verificación sin teléfonos ni dígitos`

---

### Task 5: Automatización — disparo, workflow, acción y reintento

**Files:**
- Create: `app/Domains/Automation/Support/ActionFailure.php`
- Modify: `app/Domains/Automation/Services/TriggerEscalationWorkflow.php`
- Modify: `app/Domains/Automation/Jobs/RunAutomationWorkflowJob.php`
- Modify: `app/Domains/Automation/Services/RunAutomationWorkflow.php`
- Modify: `app/Domains/Automation/Jobs/ExecuteActionJob.php`
- Modify: `app/Domains/Automation/Actions/ExecuteAction.php`
- Modify: `app/Domains/Automation/Actions/RetryFailedAction.php`
- Modify: `docs/SAM/logging.md`
- Test: `tests/Feature/Domains/Automation/{TriggerEscalationWorkflowTest,RunAutomationWorkflowTest,RunAutomationWorkflowJobTenantGuardTest,ExecuteActionJobTest,DelayedStepIncidentRecheckTest,ExecuteActionTest,ExecuteActionBridgesTest,WebhookSsrfTest,RetryFailedActionTest}.php`

**`TriggerEscalationWorkflow::execute`.** Lo llaman los tres listeners, dentro de la transacción de la decisión, de la creación o del escalamiento. Todas sus líneas van por `DB::afterCommit`.
- `conditionsMatch()` (privado) → `array{matched: bool, failed_key: ?string, expected: ?string, actual: ?string, conditions_count: int}`, con el mismo recorrido. En el primer fallo:
  - `failed_key` = `LoggableCode::guard((string) $key)`;
  - `expected` = `LoggableCode::guard(is_scalar($expected) ? (string) $expected : null)`;
  - `actual` = lo mismo con `$actual`.
  La clave y el valor esperado son texto del tenant; el guard los deja pasar solo si parecen código. El valor real sale del payload del sistema (códigos de tipo, severidad y desenlace).
- Por workflow que no casa → `SystemLog::skipped('automation.workflow.not_matched', reason: 'condition_mismatch', input: ['automation_workflow_id' => $workflow->id, 'workflow_scope' => $workflow->team_id === null ? 'global' : 'tenant', 'trigger_type' => $triggerType->value, 'source_type' => $sourceType->value], calc: [...], debug: true)`.
- Por workflow que casa, tras el `dispatch` → `SystemLog::ok('automation.workflow.matched', input: [...], calc: ['conditions_count' => …], result: ['job_requested' => true])`.
- Al final → `SystemLog::ok('automation.trigger.evaluated', input: ['trigger_type' => …, 'source_type' => …, 'source_reference_id' => LoggableCode::guard($sourceReferenceId)], calc: ['candidates_count' => $workflows->count()], result: ['matched_workflow_ids' => $dispatched, 'matched_count' => count($dispatched)], debug: $dispatched === [])`.

**`RunAutomationWorkflowJob::handle`:** `$workflow === null` → `SystemLog::skipped('automation.workflow.skipped', reason: 'workflow_unavailable', input: ['source_type' => $this->sourceType])`. Sin `automation_workflow_id`: si no es del team ni global, es de otro tenant.

**`RunAutomationWorkflow::execute`.** Lo llaman el job y `AutomationWorkflowController::trigger`, nunca dentro de una transacción. Se usa el patrón de la fase 3:
- `$existing !== null` → `SystemLog::skipped('automation.workflow.skipped', reason: 'already_ran', input: ['automation_workflow_id' => $workflow->id, 'source_type' => $sourceType->value, 'source_reference_id' => LoggableCode::guard($sourceReferenceId)], result: ['existing_workflow_execution_id' => $existing->id])`.
- `return DB::transaction(function () use (...) {` pasa a `$execution = DB::transaction(function () use (..., &$narrative) {`. Dentro:
  - `dispatchStep()` (privado) devuelve `'queued' | 'awaiting_confirmation' | 'reused'`. Es `reused` si `! $execution->wasRecentlyCreated` (`firstOrCreate` encontró la fila); el job se despacha igual que hoy;
  - se acumulan `$stepOutcomes`, `$cumulativeDelays` (lista de `$cumulativeDelay` por paso) y `$templateResolvedCount`;
  - al final, `$narrative = ['incident_id' => $incidentId, 'steps' => …]`.
- Después de la transacción, `SystemLog::ok('automation.workflow.started', input: ['automation_workflow_id' => $workflow->id, 'workflow_scope' => …, 'source_type' => $sourceType->value, 'source_reference_id' => LoggableCode::guard($sourceReferenceId)], calc: ['steps_count' => …, 'queued_count' => …, 'awaiting_confirmation_count' => …, 'reused_count' => …, 'cumulative_delays_seconds' => $cumulativeDelays, 'template_resolved_count' => …, 'incident_expected' => in_array($sourceType, [Incident, Escalation], true), 'incident_linked' => $incidentId !== null], result: ['workflow_execution_id' => $execution->id, 'status' => $execution->status->value, 'usage_event_key' => "workflow_exec_{$execution->id}"])`, y `return $execution;`.
- **Workflow sin pasos:** no es un "skip". Crea la ejecución, mide `incident_workflows` y termina en `completed`. La línea lo dice con `steps_count = 0` y `status = 'completed'`.
- **`incident_expected && ! incident_linked`:** el incidente no es del team o no existe. Los pasos que lo necesitan fallarán con `no_linked_incident` (ver abajo).

**`ActionFailure` (nuevo, `app/Domains/Automation/Support/ActionFailure.php`)** — el único componente nuevo:

```php
final class ActionFailure extends \RuntimeException
{
    /** @param array<string, int|string|bool|null> $context */
    public function __construct(public readonly string $kind, string $message, public readonly array $context = [])
    {
        parent::__construct($message);
    }
}
```

`ExecuteAction` sustituye cada `throw new \RuntimeException('…')` por `throw new ActionFailure('<kind>', '…')` con **el mismo mensaje**. Así `error_message`, `ActionExecutionLog.message` y `ActionFailed` no cambian, y los tests que esperan `RuntimeException` siguen verdes porque `ActionFailure` hereda de ella. Los `kind`:
- `webhook_url_missing`;
- `webhook_connection_failed`;
- `webhook_redirect` y `webhook_http_error`, los dos con `context: ['http_status' => $response->status()]`;
- `unsupported_notification_action`;
- `no_recipients`;
- `notification_not_delivered`, con `context: ['notification_id' => $notification->id]`;
- `assignee_missing`;
- `no_linked_incident`;
- `incident_not_in_team`.

**`ExecuteAction::execute` / `cancel`** (directo). `input` común: `['action_execution_id' => $execution->id, 'action_type' => $execution->action_type->value, 'execution_mode' => $execution->execution_mode?->value, 'source_type' => $execution->source_type?->value, 'incident_id' => $execution->incident_id]`.

| Rama | Llamada |
|---|---|
| `$blocked !== null`, tras `cancel` | `SystemLog::skipped('automation.action.stopped', reason: 'tenant_blocked', input: …, calc: ['blocked_reason' => $blocked])` |
| éxito | `SystemLog::ok('automation.action.completed', input: …, calc: ['attempt' => $execution->attempts], result: $this->loggableResponse($execution, $response), durationMs: …)` |
| `catch` | `SystemLog::degraded('automation.action.failed', reason: $this->failureReason($exception), input: …, calc: ['attempt' => $execution->attempts], result: ['error_class' => class_basename($exception), ...$context], durationMs: …)` |

- **`durationMs`:** `SystemLog::elapsedMs($started)` alrededor de `dispatchByType`.
- **`loggableResponse()`** (privado): solo `notification_id`, `notification_status`, `recipients_count` (= `$response['recipients']`), `assignment_id`, `status_code` (= `$response['status']` en incidentes) y `http_status` (en webhooks) cuando existen, más `stub` (`deferred_v2`). **Nunca** `body` (lo controla un tercero), `channel` ni el resto.
- **`failureReason()`** (privado):
  - `ActionFailure` → `$e->kind`, y `$context = $e->context`;
  - `UnsafeOutboundUrlException` → `'unsafe_url'`, con `$context = ['unsafe_url_code' => LoggableCode::guard(strtok($e->getMessage(), ':'))]`. El host va detrás de `:` y no se registra;
  - `InvalidArgumentException` → `'invalid_assignee'` (guard de `AssignIncident`; su mensaje lleva un id de usuario que puede ser ajeno);
  - otra excepción → `'unexpected_exception'`.
- **Nunca `error: $exception`.** El mensaje interpola `target_reference` (teléfono, email o URL) e ids ajenos, y ya queda en `error_message` en la DB. La línea existente `automation.webhook.connection_failed` (fase 1) conserva su `error:` porque la excepción de cURL no pasa por el mensaje del tenant.

**`ExecuteActionJob::handle`** (directo):
- **Refactor:** `incidentStopReason()` (privado) pasa a devolver `?array{code: 'incident_terminal'|'human_control', incident_id: int, message: string}`. `message` es el string actual, que se pasa a `cancel()` sin cambios.
- `$execution === null` → `SystemLog::skipped('automation.action.skipped', reason: 'execution_missing', input: ['action_execution_id' => $this->actionExecutionId])`;
- `Completed`/`Cancelled` → `… reason: 'already_'.$execution->status->value, input: [...]`, es decir `already_completed` o `already_cancelled`;
- stop, tras `cancel` → `SystemLog::skipped('automation.action.stopped', reason: $stop['code'], input: [...], calc: ['incident_id' => $stop['incident_id'], 'delayed' => true])`.

**`RetryFailedAction::execute`** (directo; lo llama `ActionExecutionController::retry`):
- `attempts >= maxRetries` → `SystemLog::skipped('automation.action.retry_scheduled', reason: 'retries_exhausted', input: ['action_execution_id' => $execution->id], calc: ['attempts' => $execution->attempts, 'max_retries' => $policies->maxRetries])`;
- programado, tras el `dispatch` → `SystemLog::ok('automation.action.retry_scheduled', input: [...], calc: ['attempts' => $execution->attempts, 'max_retries' => $policies->maxRetries, 'backoff_schedule_seconds' => $backoff, 'backoff_index' => $execution->attempts, 'index_in_schedule' => array_key_exists($execution->attempts, $backoff)], result: ['delay_seconds' => $delaySeconds, 'job_requested' => true])`.

El test rehace `delay_seconds === (backoff_schedule_seconds[attempts] ?? last(backoff_schedule_seconds)) ?: 0`.

- [ ] **Step 1: Write the failing tests**
  - `TriggerEscalationWorkflowTest`:
    - `test_dispatches_workflow_jobs_for_matching_active_workflows` → un `automation.workflow.matched` por workflow que casa, y `automation.workflow.not_matched` con `failed_key` y `actual` del que no;
    - `test_system_wide_workflow_is_picked_up_for_tenant` → `workflow_scope === 'global'`;
    - test nuevo con `trigger_conditions_json = ['severity' => 'texto libre con espacios']` → `calc.expected === null`, y el JSON no contiene `'texto libre con espacios'`;
    - **rollback** (test nuevo): workflow `incident_created` activo, `Event::listen(IncidentCreated::class, fn () => throw new RuntimeException('boom'))` y `CreateIncidentFromEvent` → `assertSystemNotLogged('automation.workflow.matched')`.
  - `RunAutomationWorkflowTest` (setUp con `AutomationMeterSeeder`):
    - `test_creates_workflow_execution_and_dispatches_jobs_per_step` → `automation.workflow.started` con `steps_count`, `queued_count` y `cumulative_delays_seconds` iguales a los `delay` de los `ExecuteActionJob` empujados;
    - `test_idempotent_when_called_twice_for_same_source` → `automation.workflow.skipped` / `already_ran`;
    - `test_steps_with_requires_confirmation_pause_in_pending` → `awaiting_confirmation_count` > 0;
    - `test_steps_never_link_an_incident_of_another_team` → `incident_expected === true`, `incident_linked === false`;
    - test nuevo con `steps_json = []` → `steps_count === 0`, `status === 'completed'`.
  - `RunAutomationWorkflowJobTenantGuardTest` → `workflow_unavailable`, y el JSON no contiene el id del workflow ajeno.
  - `ExecuteActionJobTest`:
    - `test_handle_no_ops_when_execution_missing` → `execution_missing`;
    - `test_handle_skips_completed_execution` → `already_completed`;
    - `test_handle_skips_cancelled_execution` → `already_cancelled`.
  - `DelayedStepIncidentRecheckTest` (con `delayedEmailStep`/`runJob`):
    - `test_step_is_cancelled_when_a_human_claimed_the_incident` → `automation.action.stopped` / `human_control`;
    - `test_step_is_cancelled_when_the_incident_is_terminal` → `incident_terminal`.
  - `ExecuteActionTest`:
    - `test_send_email_action_records_completed_status_and_log` → `automation.action.completed` con `notification_id` y `duration_ms`;
    - `test_failed_webhook_marks_execution_failed_and_dispatches_event` → `automation.action.failed` / `webhook_http_error` con `http_status`, y `error_message` en la DB sin cambios.
  - `ExecuteActionBridgesTest`:
    - `test_send_action_fails_when_no_recipient_resolves` → `no_recipients`, y el JSON no contiene el `target_reference` de la ejecución;
    - `test_incident_actions_fail_without_linked_incident` → `no_linked_incident`;
    - `test_create_ticket_and_update_asset_state_remain_deferred` → `completed` con `stub === true`.
  - `WebhookSsrfTest::test_internal_targets_are_blocked_without_any_request` → `unsafe_url` con `unsafe_url_code` (`reserved_host` / `blocked_ip`…), y el JSON no contiene el host interno.
  - `RetryFailedActionTest`:
    - `test_requeues_failed_action_when_attempts_remain` → `retry_scheduled` ok; se rehace `delay_seconds` con la fórmula y se compara con el `delay` del job empujado;
    - `test_returns_false_when_retry_budget_exhausted` → `retries_exhausted`.
  - Test nuevo con un `TenantCanSend` bloqueado (suscripción suspendida, como en `TenantCanSendGateTest`) → `automation.action.stopped` / `tenant_blocked`.
  - `assertNoSensitiveDataLogged()` en cada archivo.

- [ ] **Step 2: Run to verify they fail** — `APP_KEY=… php artisan test --compact tests/Feature/Domains/Automation` → FAIL.
- [ ] **Step 3: Implement** — `ActionFailure`, los retornos privados y las líneas.
- [ ] **Step 4: Catalog + run**
  - Filas: `automation.trigger.evaluated`, `automation.workflow.matched`, `automation.workflow.not_matched`, `automation.workflow.skipped`, `automation.workflow.started`, `automation.action.skipped`, `automation.action.stopped`, `automation.action.completed`, `automation.action.failed` (tabla de `kind`) y `automation.action.retry_scheduled` (con la fórmula).
  - Notas: "workflow sin pasos = `started` con `status: completed`, se mide igual" y "`RetryActionExecutionJob` no está en `routes/console.php`: hoy solo reintenta el endpoint manual".
  - Corre `tests/Feature/Domains/Automation`, `tests/Feature/Domains/Incidents`, `tests/Feature/Domains/Notifications` y `LoggingConventionsTest` → PASS.
- [ ] **Step 5: Commit** — `feat: log narrativo de workflows y acciones de automatización con códigos de fallo estables`

---

### Task 6: Notificaciones — pedido, destinatarios, canales y envío

**Files:**
- Modify: `app/Domains/Notifications/Actions/SendNotification.php`
- Modify: `app/Domains/Notifications/Listeners/NotifyOnIncidentCreated.php`
- Modify: `app/Domains/Notifications/Listeners/NotifyOnIncidentStatusChanged.php`
- Modify: `app/Domains/Notifications/Actions/ResolveRecipients.php`
- Modify: `app/Domains/Notifications/Actions/SelectNotificationChannels.php`
- Modify: `app/Domains/Notifications/Actions/DispatchNotification.php`
- Modify: `app/Domains/Notifications/Actions/AttemptDelivery.php`
- Modify: `docs/SAM/logging.md`
- Test: `tests/Feature/Domains/Notifications/{SendNotificationTest,DeliveryHardeningTest,IncidentCreatedSeverityThresholdTest,QuietHoursChannelSelectionTest,DispatchNotificationTest,ChannelAwareDispatchTest,RecipientChannelAddressTest,ChannelToggleEnforcementTest}.php`, `tests/Feature/Domains/Automation/EscalationTriggerOnlyOnEscalatedTest.php`

**`SendNotification::execute`.** La llaman listeners dentro de transacciones ajenas, así que la línea de hecho va por `DB::afterCommit`.
- `$existing` → `SystemLog::skipped('notifications.dedup.skipped', reason: 'event_key_exists', input: ['notification_type' => LoggableCode::guard($notificationType), 'source_type' => $sourceType->value], result: ['existing_notification_id' => $existing->id])`;
- `catch (UniqueConstraintViolationException)` → `… reason: 'event_key_race'`, con el id de la fila recuperada;
- creada → `DB::afterCommit(fn () => SystemLog::ok('notifications.notification.requested', input: ['notification_type' => …, 'source_type' => …, 'source_reference_id' => LoggableCode::guard($sourceReferenceId), 'priority' => $priority->value, 'triggered_by_type' => $triggeredByType->value], calc: ['explicit_recipients_count' => is_array($payload['recipients'] ?? null) ? count($payload['recipients']) : 0, 'forced_channel_types' => $forcedTypes], result: ['notification_id' => $notification->id, 'job_requested' => $dispatchJob]))`.

`$forcedTypes`: los valores de `$payload['force_channels']` que pasan `ChannelType::tryFrom`, o null. Nunca `event_key`, `subject`, `body_preview` ni `payload`: `incident_title`, `asset_name`, `driver_name` y `location` viajan dentro.

**`NotifyOnIncidentCreated`** (`DB::afterCommit`):
- **Refactor:** `reachesOutOfBandThreshold()` (privado) → `array{reaches: bool, calc: array}`. `calc`:
  - `severity` = `$severity`;
  - `severity_rank`;
  - `severity_rank_source` = `'priority'` si `$severity` está en `SEVERITY_RANK`; si no, `'default_medium'`;
  - `min_severity` = `LoggableCode::guard($minimum)`;
  - `min_severity_rank`;
  - `min_severity_valid` = `isset(self::SEVERITY_RANK[$minimum])` (si no, se usa el default).
- Por debajo → `SystemLog::skipped('notifications.out_of_band.skipped', reason: 'below_min_severity', input: ['incident_id' => $incident->id, 'setting_key' => self::SETTING_MIN_SEVERITY], calc: $calc, result: ['forced_channel_types' => [ChannelType::Web->value]])`. Por encima, no hay línea propia: `notifications.notification.requested` ya lleva `forced_channel_types = null`.
- `resolveNotificationType()` (privado) devuelve también `type_source`: `'type_specific_template'` o `'generic'`. Va en el `input` de la línea anterior. Nunca el id de la plantilla, que puede ser global.

**`NotifyOnIncidentStatusChanged::handle`** (`DB::afterCommit`: corre dentro de `EscalateIncident` o `CloseIncident`):
- `in_review` → `SystemLog::skipped('notifications.status_change.skipped', reason: 'internal_status', input: ['incident_id' => …, 'new_status' => $newStatus], debug: true)`;
- escalado por SLA → `… reason: 'escalated_by_sla'` (el watchdog ya avisó);
- sin destinatarios → `… reason: 'no_recipients', calc: $recipientCalc`. `recipients()` (privado) devuelve además `calc: ['candidates_count' => …, 'actor_excluded' => bool, 'non_member_dropped_count' => …, 'without_email_dropped_count' => …]`.

**`ResolveRecipients` — método nuevo, de solo lectura.** `public function explain(Notification $notification): array` devuelve `array{descriptors: list<RecipientDescriptor>, source: 'explicit'|'team_members', candidates_count: int, dropped_count_by_reason: array<string, int>}`. `execute()` pasa a `return $this->explain($notification)['descriptors'];`. Los motivos:
- `not_an_array` (entrada explícita que no es array);
- `no_address` (explícita sin `address` string no vacía);
- `no_user` y `no_email` (membresía sin usuario, o usuario sin email).

**`SelectNotificationChannels` — método nuevo, de solo lectura.** `public function explain(Notification $notification, NotificationRecipient $recipient): array` devuelve `array{channels: list<NotificationChannel>, branch: string, calc: array}` con el cuerpo actual. `execute()` pasa a `return $this->explain($notification, $recipient)['channels'];`. Los tests de `QuietHoursChannelSelectionTest` siguen llamando a `execute`.

`branch` (una por `return`):
- `no_team`;
- `no_usable_channels`;
- `critical_forced`;
- `critical_policy`;
- `forced`;
- `muted`;
- `recipient_preference` (el filtro por `channel_preference` dejó algo);
- `allowed_types`.

`calc`:
- `priority`;
- `usable_channel_types`;
- `forced_types` (validados con `ChannelType::tryFrom`);
- `critical_policy_types` (en ramas críticas);
- `quiet_hours_active`;
- `quiet_hours_source` (`user_preference` | `tenant_policy` | `none`): `insideQuietHours()` (privado) devuelve `array{active: bool, source: string}`;
- `silenced_types` (tipos quitados por silencio);
- `muted` (bool|null);
- `allowed_types` y `allowed_types_source` (`user_preference` | `tenant_policy`): `resolveAllowedTypes()` (privado) devuelve también la fuente;
- `recipient_channel_preference` (`LoggableCode::guard`) y `preference_matched`;
- `selected_types` y `selected_channel_ids`.

Las ramas críticas no leen preferencia ni silencio, como hoy: esos campos van null.

**`DispatchNotification::execute`** (directo; corre en `SendNotificationJob`, sin transacción). `input` común: `['notification_id' => $notification->id]`.

| Sitio / rama | Llamada |
|---|---|
| tras resolver | `$explain = $this->resolveRecipients->explain($notification)`. Vacío → `SystemLog::skipped('notifications.recipients.resolved', reason: 'no_recipients', input: …, calc: [source, candidates_count, dropped_count_by_reason])`; si no, `SystemLog::ok('notifications.recipients.resolved', input: …, calc: [...], result: ['recipient_ids' => …, 'recipients_count' => …, 'recipients_reused' => $recipientsExisted])` tras el bucle de `firstOrCreateRecipient` |
| por destinatario | `$selection = $this->selectChannels->explain(...)`. Vacío → `SystemLog::skipped('notifications.channels.selected', reason: $selection['branch'] === 'muted' ? 'muted' : ($selection['branch'] === 'no_usable_channels' ? 'no_usable_channels' : 'no_channel_after_filters'), input: [..., 'recipient_id' => $recipient->id, 'recipient_type' => $recipient->recipient_type->value], calc: [...$selection['calc'], 'branch' => $selection['branch']])`; si no, `SystemLog::ok(…, debug: true)` |
| sin dirección para el canal | `SystemLog::skipped('notifications.delivery.skipped', reason: 'no_address', input: [..., 'recipient_id' => …, 'channel_id' => $channel->id, 'channel_type' => $channel->channel_type->value])`, además del registro en la DB |
| dirección inválida | `… reason: 'invalid_address'`. El texto de `ChannelAddress::invalidReason` no se registra. |
| `createDeliveryOrSkip` null | ver refactor abajo |
| al final, tras `refreshStatus` | `SystemLog::ok('notifications.dispatch.completed', input: …, calc: ['recipients_count' => …, 'deliveries_attempted_count' => $attempted, 'deliveries_skipped_count_by_reason' => $skippedByReason, 'sent_count' => $sent, 'failed_count' => $failed], result: ['notification_status' => $notification->refresh()->status->value])` |

`createDeliveryOrSkip()` (privado) sigue devolviendo `?NotificationDelivery` y registra en cada `null`:
- entrega existente → `SystemLog::skipped('notifications.dedup.skipped', reason: 'delivery_exists', input: [..., 'recipient_id' => …, 'channel_id' => …], debug: true)`;
- `catch (\Throwable $e)` → `SystemLog::degraded('notifications.delivery.create_failed', reason: 'record_failed', input: [...], error: $e)`. Hoy traga cualquier excepción sin rastro. Es un error de DB, sin texto de terceros.

**`AttemptDelivery::execute`** (directo; nunca en una transacción):
- `$started = hrtime(true)` antes de `->send(...)`, y `$durationMs = SystemLog::elapsedMs($started)` después.
- `$stage`: `'fallback'` si `$delivery->fallback_from_delivery_id !== null`, `'retry'` si `$delivery->attempt_number > 1`, y `'first'` en otro caso.
- `input` común: `['delivery_id' => $delivery->id, 'notification_id' => $delivery->notification_id, 'recipient_id' => $delivery->recipient_id, 'channel_id' => $channel->id, 'channel_type' => $channel->channel_type->value, 'provider' => LoggableCode::guard($channel->provider), 'stage' => $stage, 'attempt_number' => $delivery->attempt_number]`.

| Resultado | Llamada, tras `recordAttempt` + `refresh` |
|---|---|
| `$result->success` | `SystemLog::ok('notifications.delivery.sent', input: …, result: ['delivery_status' => $delivery->status->value, 'awaiting_provider_confirmation' => $result->awaitingProviderConfirmation, 'provider_message_id' => LoggableCode::guard($result->providerMessageId), 'provider_status' => LoggableCode::guard($result->providerStatus), 'resource_type' => $result->resourceType?->value, 'segments' => $result->segments, 'charge_recorded' => $result->resourceType !== null && $result->providerMessageId !== null, 'usage_meter_code' => $channel->channel_type->usageMeterCode()], durationMs: $durationMs)` |
| fallo | `SystemLog::degraded('notifications.delivery.failed', reason: $result->permanent ? 'permanent_failure' : 'transient_failure', input: …, result: ['delivery_status' => …, 'provider_error_code' => LoggableCode::guard($result->providerErrorCode), 'permanent' => $result->permanent, 'metered' => false], durationMs: $durationMs)` |

- **Nunca** `errorMessage` (lleva `getMessage()` del SDK, que puede incluir el número), `response`, `address`, `subject` ni `body`.
- `degraded` y no `failed`: la cadena sigue con un reintento o un fallback (Task 7). El fracaso definitivo lo dicen `notifications.fallback.exhausted` y `notifications.dispatch.completed` con `notification_status = failed`.

- [ ] **Step 1: Write the failing tests**
  - `SendNotificationTest`:
    - `test_creates_notification_and_dispatches_job` → `notifications.notification.requested` con `job_requested === true`;
    - `test_idempotent_on_team_id_and_event_key` → `notifications.dedup.skipped` / `event_key_exists`.
  - `DeliveryHardeningTest`:
    - `test_concurrent_create_with_the_same_event_key_returns_the_existing_row` → `event_key_race`;
    - `test_messaging_channel_is_skipped_when_the_address_is_not_a_phone` → `notifications.delivery.skipped` / `invalid_address`;
    - `test_failed_otp_send_is_not_metered` → `notifications.delivery.failed` con `metered === false`.
  - `IncidentCreatedSeverityThresholdTest` (con `setThreshold`/`notifyFor`/`incident`):
    - `test_low_severity_incident_is_in_app_only_by_default` → `notifications.out_of_band.skipped` con `severity_rank === 1`, `min_severity === 'medium'`, `min_severity_rank === 2`, y se rehace `severity_rank < min_severity_rank`;
    - `test_tenant_threshold_high_keeps_medium_in_app_but_not_critical` → skipped para medium y ausente para critical.
  - `QuietHoursChannelSelectionTest` (con `teamWithQuietPolicy`/`travelToLocal`): los tests que hoy llaman a `selectedTypes` pasan también por `explain()` y afirman:
    - `branch === 'allowed_types'`, `quiet_hours_source === 'tenant_policy'` y `silenced_types` que contiene `sms`;
    - `test_user_preference_quiet_hours_apply_without_a_tenant_window` → `quiet_hours_source === 'user_preference'`;
    - `test_critical_priority_ignores_quiet_hours` → `branch === 'critical_policy'`.
  - `DispatchNotificationTest`:
    - `test_email_delivery_creates_records_and_emits_usage` → `notifications.recipients.resolved` con `source === 'team_members'`; `notifications.delivery.sent` con `channel_type === 'email'`, `stage === 'first'`, `awaiting_provider_confirmation === false` y `duration_ms` presente; `notifications.dispatch.completed` con `sent_count === 1`;
    - `test_muted_low_priority_notification_yields_no_deliveries` → `notifications.channels.selected` **skipped** `muted` (el silencio del §5, visible);
    - `test_re_dispatching_same_notification_is_idempotent_and_does_not_resend` → `notifications.dedup.skipped` / `delivery_exists` en debug, y `recipients_reused === true`.
  - `ChannelAwareDispatchTest`:
    - `test_sms_to_recipient_without_phone_is_skipped_not_emailed` → `notifications.delivery.skipped` / `no_address`;
    - `test_notification_with_only_skipped_deliveries_is_cancelled` → `dispatch.completed.notification_status === 'cancelled'`.
  - `RecipientChannelAddressTest::test_explicit_contact_classifies_phone_vs_email` → `explain()` sin descartes; un test nuevo con una entrada sin `address` → `dropped_count_by_reason['no_address'] === 1`.
  - `ChannelToggleEnforcementTest::test_dispatch_never_selects_a_channel_the_tenant_switched_off` → `usable_channel_types` no contiene el canal apagado.
  - `EscalationTriggerOnlyOnEscalatedTest::test_sla_escalation_is_not_notified_twice` → `notifications.status_change.skipped` / `escalated_by_sla`.
  - **rollback** (test nuevo en `DispatchNotificationTest`): `Event::listen(IncidentCreated::class, fn () => throw new RuntimeException('boom'))` registrado después de `NotifyOnIncidentCreated`, y `CreateIncidentFromEvent` → `assertSystemNotLogged('notifications.notification.requested')`.
  - Texto: en `DispatchNotificationTest`, el JSON de las entradas no contiene el email ni el nombre del usuario, el `subject` ni el `body_preview`.
  - `assertNoSensitiveDataLogged()` en cada archivo.

- [ ] **Step 2: Run to verify they fail** — `APP_KEY=… php artisan test --compact tests/Feature/Domains/Notifications tests/Feature/Domains/Automation/EscalationTriggerOnlyOnEscalatedTest.php` → FAIL.
- [ ] **Step 3: Implement** — los dos `explain`, los retornos privados y las líneas.
- [ ] **Step 4: Catalog + run**
  - Filas: `notifications.notification.requested`, `notifications.dedup.skipped` (`event_key_exists`, `event_key_race`, `delivery_exists`), `notifications.out_of_band.skipped`, `notifications.status_change.skipped`, `notifications.recipients.resolved`, `notifications.channels.selected` (tabla de `branch`), `notifications.delivery.skipped`, `notifications.delivery.create_failed`, `notifications.delivery.sent`, `notifications.delivery.failed` y `notifications.dispatch.completed`.
  - Notas: "`sent` = aceptado por el proveedor; en Twilio la entrega llega después (`notifications.provider_status.applied`)" y "selección vacía = antes sin rastro ni en la DB".
  - Corre `tests/Feature/Domains/Notifications`, `tests/Feature/Domains/Automation`, `tests/Feature/Domains/Incidents` y `LoggingConventionsTest` → PASS.
- [ ] **Step 5: Commit** — `feat: log narrativo de destinatarios, selección de canales y envío de notificaciones`

---

### Task 7: Notificaciones — reintento, fallback, guard, status de Twilio y respuesta entrante

**Files:**
- Modify: `app/Domains/Notifications/Support/DeliveryEscalationGuard.php`
- Modify: `app/Domains/Notifications/Listeners/RetryOrFallbackOnNotificationFailed.php`
- Modify: `app/Domains/Notifications/Jobs/RetryNotificationDeliveryJob.php`
- Modify: `app/Domains/Notifications/Jobs/FallbackNotificationChannelJob.php`
- Modify: `app/Domains/Notifications/Actions/ApplyTwilioStatusUpdate.php`
- Modify: `app/Http/Controllers/Webhooks/TwilioStatusCallbackController.php`
- Modify: `app/Domains/Notifications/Actions/ProcessInboundReply.php`
- Modify: `docs/SAM/logging.md`
- Test: `tests/Feature/Domains/Notifications/{RetryAndFallbackTest,ChannelToggleEnforcementTest}.php`, `tests/Feature/Http/Webhooks/{TwilioStatusCallbackTest,TwilioInboundWebhookTest}.php`

**`DeliveryEscalationGuard` — método nuevo, de solo lectura.** `public static function explain(NotificationDelivery $delivery): array` devuelve `array{reason: ?string, calc: array}` con la misma cascada. `blockReason()` pasa a `return self::explain($delivery)['reason'];`. `calc`:
- `ttl_minutes` = `self::TTL_MINUTES`;
- `notification_age_seconds`;
- `incident_id` (si la fuente es un incidente);
- `incident_handled_at_present`;
- `reached_elsewhere`.

**Línea del guard** (común a los tres sitios que lo consultan):

```php
SystemLog::skipped('notifications.escalation_guard.blocked',
    reason: $this->guardReason($reason),  // tenant_missing / subscription_* → 'tenant_cannot_send'
    input: ['delivery_id' => $delivery->id, 'notification_id' => $delivery->notification_id, 'stage' => $stage],
    calc: [...$guard['calc'], 'blocked_reason' => $reason],
);
```

- `stage`: `'listener'`, `'retry_job'` o `'fallback_job'`.
- `guardReason` es un helper estático nuevo en `DeliveryEscalationGuard`, `public static function logReason(string $reason): string`. Pasa `notification_missing`, `expired`, `incident_handled` y `recipient_reached` tal cual, y mapea los de `TenantCanSend` (`tenant_missing`, `subscription_*`) a `'tenant_cannot_send'`, igual que `notifications.notification.cancelled`.

**`RetryOrFallbackOnNotificationFailed::handle`** (directo: `NotificationFailed` se despacha fuera de una transacción, en `AttemptDelivery` y `ApplyTwilioStatusUpdate`):

| Rama | Llamada |
|---|---|
| null o no `Failed` | `SystemLog::skipped('notifications.retry.skipped', reason: 'not_failed', input: ['delivery_id' => $event->deliveryId, 'stage' => 'listener'], debug: true)` |
| guard | línea del guard con `stage: 'listener'` |
| permanente o agotado | `SystemLog::ok('notifications.fallback.requested', input: ['delivery_id' => …, 'channel_type' => $event->channelType], calc: ['trigger' => $delivery->permanent_failure ? 'permanent_failure' : 'retries_exhausted', 'attempt_number' => $delivery->attempt_number, 'max_attempts' => $retry->maxAttempts()], result: ['job_requested' => true])` |
| reintento | `SystemLog::ok('notifications.retry.scheduled', input: [...], calc: ['attempt_number' => $delivery->attempt_number, 'max_attempts' => $retry->maxAttempts(), 'delays_seconds' => $delays, 'step' => $step], result: ['delay_seconds' => $delays[$step], 'next_attempt_number' => $delivery->attempt_number + 1, 'job_requested' => true])` |

El test rehace `delay_seconds === delays_seconds[max(0, min(attempt_number, count(delays_seconds)) - 1)]` y lo compara con `$job->delay`.

**`RetryNotificationDeliveryJob::handle`.** `input` común: `['delivery_id' => $this->deliveryId, 'stage' => 'retry_job']`.
- null o no `Failed` → `SystemLog::skipped('notifications.retry.skipped', reason: 'not_failed', input: …, debug: true)`;
- relaciones faltantes → `… reason: 'relations_missing'`;
- `permanent_failure` → `… reason: 'permanent_failure'`;
- guard → línea del guard con `stage: 'retry_job'`. Para distinguirlo del `permanent_failure`, se separa el `||` actual en dos `if` con el mismo `return`;
- canal ya no usable → `… reason: 'channel_disabled', result: ['delivery_status' => 'cancelled', 'fallback_requested' => true]`;
- sin payload ni dirección → `… reason: 'no_valid_address', result: ['delivery_status' => 'skipped']`.

El envío lo narra `AttemptDelivery` con `stage = 'retry'`.

**`FallbackNotificationChannelJob::handle`.** `input` común: `['failed_delivery_id' => $this->failedDeliveryId]`.
- primaria null o relaciones faltantes → `SystemLog::skipped('notifications.fallback.skipped', reason: 'relations_missing', input: …)`;
- guard → línea del guard con `stage: 'fallback_job'`;
- `$alreadyEscalated` → `… reason: 'already_escalated'`.
- **Recorrido:** se acumula `$walk[] = ['channel_type' => $type->value, 'outcome' => …]`, donde `outcome` vale:
  - `already_used`;
  - `no_usable_channel`;
  - `no_address` (si `$address` es null o vacía);
  - `invalid_address`;
  - `race_lost` (`createFallbackDelivery` devolvió null);
  - `chosen`.
- Elegido, tras `attemptDelivery->execute` → `SystemLog::ok('notifications.fallback.chosen', input: …, calc: ['policy_fallback_types' => array_map(fn (ChannelType $t) => $t->value, $policy->fallbackChannels), 'used_types' => …, 'walk' => $walk], result: ['delivery_id' => $delivery->id, 'channel_type' => $type->value, 'channel_id' => $channel->id])`.
- Sin elegido al terminar el `foreach` → `SystemLog::degraded('notifications.fallback.exhausted', reason: 'no_fallback_channel', input: …, calc: [...mismo calc])`. Hoy el job termina sin rastro y el destinatario se queda sin aviso.

**`ApplyTwilioStatusUpdate::execute`** (directo). `input` común: `['charge_id' => $charge->id, 'source' => $source, 'resource_type' => $charge->resource_type->value]`.
- status vacío → `SystemLog::skipped('notifications.provider_status.skipped', reason: 'empty_status', input: …, debug: true)`;
- cargo que no es de una entrega (verificación, OTP) → `… reason: 'not_a_notification_delivery', calc: ['provider_status' => LoggableCode::guard($providerStatus)], debug: true`.
- **Refactor:** `updateDelivery()` (privado) devuelve `array{outcome: 'superseded_attempt'|'not_advancing'|'advanced', from: ?string, to: ?string, delivery_id: ?int}`:
  - `superseded_attempt` → skipped info (el SID ya no es el del intento actual);
  - `not_advancing` → skipped debug;
  - `advanced` → `SystemLog::ok('notifications.provider_status.applied', input: [..., 'delivery_id' => …], calc: ['provider_status' => LoggableCode::guard($providerStatus), 'provider_error_code' => LoggableCode::guard($errorCode), 'duration_seconds' => $durationSeconds], result: ['from_status' => $from, 'to_status' => $to, 'permanent' => $to === 'failed' ? TwilioErrorCatalog::isPermanent($errorCode) : null])`.
- `failureMessage()` no se registra (es para la DB).

**`TwilioStatusCallbackController::__invoke`:**
- SID o status vacío → `SystemLog::skipped('notifications.provider_status.skipped', reason: 'missing_fields', calc: ['sid_present' => $sid !== '', 'status_present' => $status !== ''])`;
- `$charge === null` → `… reason: 'unknown_sid'`. Sin el SID: es del request, sin tenant resuelto.

**`ProcessInboundReply::execute`.** Es la transacción más externa (la llama `TwilioInboundController`), así que las líneas de hecho van por `DB::afterCommit` dentro del closure. Las líneas existentes `unknown_token` y `unexpected_sender` no cambian. `input` común tras validar: `['token_id' => $token->id, 'incident_id' => $token->incident_id, 'channel_type' => $token->channel_type->value]`.

| Rama | Llamada |
|---|---|
| `isConsumed()` | `SystemLog::skipped('notifications.inbound_reply.ignored', reason: 'already_consumed', input: …, result: ['consumed_action' => LoggableCode::guard($token->consumed_action)])` |
| `isExpired()` | `… reason: 'token_expired'` |
| incidente null o terminal | `DB::afterCommit(fn () => SystemLog::skipped('notifications.inbound_reply.ignored', reason: 'incident_terminal', input: …, result: ['consumed_action' => 'noop_terminal']))` |
| aplicado, tras `recordAuditEntry` | `DB::afterCommit(fn () => SystemLog::ok('notifications.inbound_reply.applied', input: …, calc: ['keyword' => $keyword, 'user_linked' => $token->user_id !== null], result: ['action' => match ($keyword) { 'SI' => 'acknowledge', 'NO' => 'dismiss', 'ESC' => 'escalate' }]))` |

**Nunca** `$fromAddress`, `$body`, `$code` (el token de 4 caracteres), `$token->token`, `$token->address` ni el texto de respuesta. `$keyword` es `SI`/`NO`/`ESC` (constante del patrón).

- [ ] **Step 1: Write the failing tests**
  - `RetryAndFallbackTest` (con `channel`/`recipient`/`failedDelivery`/`runRetry`/`runFallback`/`fireFailureEvent`/`usePolicy`/`bindCapturingDriver`):
    - `test_failed_delivery_event_schedules_retry_with_first_backoff_delay` → `notifications.retry.scheduled` con `step === 0` y `delay_seconds === 30`, recomputado con la fórmula y comparado con `$job->delay`;
    - `test_retry_delay_follows_backoff_schedule_for_later_attempts` → `delay_seconds === 120`;
    - `test_webhook_delivery_uses_webhook_backoff_delay` → `delays_seconds === [30, 120, 600]`;
    - `test_exhausted_retries_dispatch_fallback_job` → `notifications.fallback.requested` con `trigger === 'retries_exhausted'`;
    - `test_permanent_failure_skips_retries_and_goes_to_fallback` → `trigger === 'permanent_failure'`;
    - `test_retry_job_never_resends_a_permanent_failure` → `notifications.retry.skipped` / `permanent_failure`;
    - `test_stale_failure_event_for_delivered_delivery_is_ignored` → `not_failed` en debug;
    - `test_no_retry_or_fallback_once_the_incident_was_acknowledged` → `notifications.escalation_guard.blocked` / `incident_handled` con `stage === 'listener'`;
    - `test_delayed_retry_does_not_send_if_the_incident_got_closed_meanwhile` → `incident_handled` con `stage === 'retry_job'`;
    - `test_suspended_tenant_gets_no_retry_nor_fallback` → `tenant_cannot_send` con `calc.blocked_reason` = `subscription_…`;
    - `test_expired_notifications_are_not_escalated` → `expired` con `notification_age_seconds > ttl_minutes * 60`;
    - `test_fallback_creates_delivery_on_alternate_channel` → `notifications.fallback.chosen` con `walk` que termina en `chosen`, y `notifications.delivery.sent` con `stage === 'fallback'`;
    - `test_fallback_dedups_by_channel_type_for_the_recipient` → `walk` con `already_used`;
    - `test_fallback_is_skipped_when_the_recipient_was_already_reached` → guard `recipient_reached` con `stage === 'fallback_job'`;
    - `test_fallback_without_an_address_for_the_channel_is_recorded_as_skipped` → `walk` con `no_address` y `notifications.fallback.exhausted`;
    - test nuevo con `usePolicy([])` → `fallback.exhausted` / `no_fallback_channel`.
  - `ChannelToggleEnforcementTest`:
    - `test_retry_does_not_resend_on_a_channel_switched_off_after_the_failure` → `retry.skipped` / `channel_disabled`;
    - `test_fallback_skips_a_switched_off_channel_and_links_the_failed_delivery` → `walk` con `no_usable_channel`.
  - `TwilioStatusCallbackTest` (con `postStatus`/`queuedDelivery`):
    - `test_message_progresses_queued_sent_delivered` → `notifications.provider_status.applied` con `from_status`/`to_status` en cada paso;
    - `test_out_of_order_callback_never_moves_a_delivery_backwards` → `not_advancing` en debug;
    - `test_permanent_failure_goes_to_fallback_without_retrying` → `applied` con `to_status === 'failed'`, `permanent === true` y `provider_error_code` del request;
    - `test_events_for_an_older_attempt_do_not_touch_the_current_one` → `superseded_attempt`;
    - `test_unknown_sid_answers_200_without_effects` → `unknown_sid`, y el JSON no contiene el SID enviado.
  - `TwilioInboundWebhookTest` (con `makeToken`/`postReply`):
    - `test_si_reply_acknowledges_the_incident` → `notifications.inbound_reply.applied` con `action === 'acknowledge'`;
    - `test_no_reply_dismisses_the_incident_as_false_positive` → `dismiss`;
    - `test_esc_reply_escalates_the_incident` → `escalate`;
    - `test_expired_token_takes_no_action` → `token_expired`;
    - `test_second_reply_is_idempotent` → `already_consumed`;
    - en todos, el JSON de las entradas no contiene `$token->token`, `$token->address`, el `From` del request ni el `Body`.
  - `assertNoSensitiveDataLogged()` en cada archivo.

- [ ] **Step 2: Run to verify they fail** — `APP_KEY=… php artisan test --compact tests/Feature/Domains/Notifications/RetryAndFallbackTest.php tests/Feature/Domains/Notifications/ChannelToggleEnforcementTest.php tests/Feature/Http/Webhooks/TwilioStatusCallbackTest.php tests/Feature/Http/Webhooks/TwilioInboundWebhookTest.php` → FAIL.
- [ ] **Step 3: Implement** — `DeliveryEscalationGuard::explain`/`logReason`, el retorno de `updateDelivery` y las líneas.
- [ ] **Step 4: Catalog + gate completo**
  - Filas: `notifications.escalation_guard.blocked`, `notifications.retry.skipped`, `notifications.retry.scheduled` (con la fórmula), `notifications.fallback.requested`, `notifications.fallback.skipped`, `notifications.fallback.chosen` (tabla de `walk.outcome`), `notifications.fallback.exhausted`, `notifications.provider_status.skipped`, `notifications.provider_status.applied` y `notifications.inbound_reply.applied`.
  - Amplía `notifications.inbound_reply.ignored` con `already_consumed`, `token_expired` e `incident_terminal`.
  - Añade al párrafo "Líneas en debug" todas las líneas en debug que lista Global Constraints.
  - Run: `vendor/bin/pint --dirty --format agent`, la suite completa (`APP_KEY=… php artisan test --compact`) y `npm run types:check && npm run lint:check && npm run format:check` → todo verde.
- [ ] **Step 5: Commit** — `feat: log narrativo de reintentos, fallback, status de twilio y respuestas entrantes`

---

## Cierre de la fase

- [ ] Revisión de aislamiento con el subagente `tenant-isolation-reviewer`. Comprueba que ninguna línea lleve ids de otro tenant, con atención a:
  - `OpenEmergencyIncidentJob` (`team_mismatch`), `ApplyReevaluationToIncident` (`team_mismatch`), `ResolveOnCallOperator` (`non_member_skipped_count`) y `RunAutomationWorkflowJob` (`workflow_unavailable`);
  - `TriggerEscalationWorkflow`, que carga workflows globales y del team: `automation.workflow.*` solo lista workflows con `team_id` null o del team, porque `availableToTeam` ya lo filtra;
  - `NotifyOnIncidentCreated`, que consulta plantillas globales: ningún id de plantilla en los logs.
- [ ] `git push -u origin feat/logging-incidentes-automatizacion-notificaciones`, PR con el resumen de códigos, `gh pr checks --watch`, merge con la autorización amplia vigente y `git pull --ff-only` en el checkout principal.
- [ ] Siguiente: plan de la fase 5 (Billing/Tenancy + Assets/telemática). Incluye el costo de Twilio de `ReconcileMessagingChargesJob` y `FinalizeMessagingCharge`, que esta fase deja fuera.
