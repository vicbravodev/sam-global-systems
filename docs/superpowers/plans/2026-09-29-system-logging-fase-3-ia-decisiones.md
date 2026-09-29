# Logging narrativo — Fase 3: IA + Decisions — Plan de implementación

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Que el tramo de IA y decisiones deje su línea narrativa en cada rama y en cada número. El tramo cubre gate → idempotencia → heurísticas → cuota → agente o fallback → riesgo → fusión del veredicto visual → prioridad → falso positivo → evaluación de media → reevaluación → motor de decisiones (ruleset, reglas, desenlace, piso de seguridad, guard de contradicción, prioridad, escalación). Cada número (riesgo, confianza, prioridad, umbral de revisión humana) se registra término a término, de modo que se pueda rehacer a mano.

**Architecture:** Sobre la fundación de la fase 1 (PR #150) y el tramo de entrada de la fase 2 (PR #151): `App\Support\SystemLog`, `App\Support\LoggableCode`, `Tests\Concerns\AssertsSystemLog` y el catálogo `docs/SAM/logging.md`, que `LoggingConventionsTest` exige que contenga cada código literal. No hay componentes nuevos. Hay dos patrones:
- **Líneas de cálculo** (`ai.risk.calculated`, `ai.heuristics.evaluated`, `ai.quota.checked`, `decisions.rules.evaluated`, `decisions.rule.invalid`) se emiten donde se calcula el número. Afirman un cálculo, que es cierto al emitirse.
- **Líneas de hecho persistido** (`ai.evaluation.completed`, `ai.evaluation.rules_only`, `ai.priority.resolved`, `ai.false_positive.checked`, `ai.media_fusion.*`, `ai.media.assessed`, `decisions.outcome.*`, `decisions.priority.resolved`, `decisions.escalation_policy.resolved`) se emiten **después** de que `DB::transaction()` devuelve. El dato intermedio se lleva fuera de la transacción con un valor de retorno o una captura por referencia, nunca con estado en la clase.

**Tech Stack:** Laravel 13 · PHP 8.5 · PHPUnit 13.

**Spec:** `docs/superpowers/specs/2026-09-28-system-logging-design.md` (§3 esquema, §5 "Fase 3", §8 hallazgos).

## Global Constraints

- Código `dominio.etapa.resultado` (regex `/^[a-z0-9_]+(\.[a-z0-9_]+){2,}$/`), en inglés snake_case. `reason` obligatorio en snake_case cuando `outcome` ≠ `ok`. Nivel: ok/skipped → info, degraded → warning, failed → error.
- **Una línea nunca afirma algo que puede ser falso al emitirse.**
  - Nada que diga "evaluación creada", "decisión tomada", "fallback aplicado" o que lleve un id creado dentro de una transacción se emite dentro de ella: va después de que `DB::transaction()` devuelve.
  - `EvaluateEventWithAI::execute` y `EvaluateDecisionRules::execute` no se llaman dentro de otra transacción (callers: `EvaluateEventJob`, `ReevaluateEventWithNewEvidence`, `RunDecisionEngineJob`, `ReevaluateDecisionJob`). Así, "después de `DB::transaction()`" = "después del commit". Si un implementador encuentra un caller transaccional, se detiene y lo reporta.
  - Nada anuncia el futuro: ni "se creará un incidente", ni "el job correrá", ni "la media se barrerá después". Se registra lo que ya pasó o lo que se pidió.
  - Los conteos llevan el alcance en el nombre: `tokens_used_this_period` (mes de facturación) frente a `calls_today` (desde el inicio del día); `reused_count` frente a `created_count`. Nunca se suman ámbitos distintos en un mismo campo.
- **Claves que el redactor no enmascara.** `RedactSensitiveLogData` enmascara toda clave con un segmento `raw`, `secret`, `token`, `signature`, `payload`, `body`, `prompt`, `name`, `email`, `phone`, `address`… salvo que el último segmento sea técnico (`id`, `ids`, `count`, `type`, `status`, `class`, `mode`, `variant`, `present`, `length`, `bytes`, `source`, `strategy`, `key`). Por eso:
  - Tokens: `input_tokens` / `output_tokens` (`tokens` ≠ `token`), nunca `prompt_tokens` ni `completion_tokens`.
  - Modelo: `model`, nunca `model_name`.
  - Firma de ruido: `signature_source`, nunca `signature` ni `noise_signature_matched`.
  - Nada `raw_*`.
- **Nunca en un log:**
  - prompt ni respuesta del modelo: `AIInputContext`, `explanation_text`, `explanationSummary`, `reasoning_steps`, `key_factors`, `summary_text`, `extracted_signals_json`;
  - feedback del operador ni `AIReevaluationRequest.reason`, el `reason` del request de reevaluación ni el `reason` del `ReevaluateEventJob`. Solo `*_present: bool`;
  - `decision_reason` (texto en español que incluye el `name` de la regla) ni el `name` de reglas, outcomes o políticas;
  - coordenadas, `getMessage()` (las excepciones van como `error: $e`).
- **Códigos configurables por el tenant** pasan por `LoggableCode::guard()`, que devuelve null si no parecen código: `DecisionRule.code`, `DecisionOutcome.code`, el `operator` y el `field` de una condición, y `severity_code`/`category_code`/`event_type_code` cuando vienen del payload. Los valores de enums (`EventClassification`, `EvaluationMode`, `EvaluationPriority`, `DecisionPriority`, `MediaAssessmentResult`, `MediaType`, `ReevaluationTrigger`) se registran con `->value`.
- **Cálculos recomputables:** cada `calc` trae todos los términos, los umbrales y los límites de clamp. El test de cada cálculo rehace el resultado con esos términos y lo compara con el resultado registrado **y** con el persistido.
- Cada código nuevo tiene:
  - su fila en `docs/SAM/logging.md` (código | outcome | reason posibles | campos clave);
  - al menos un test de feature que recorre la rama real (DB real, factories y fixtures de los tests existentes listados en cada tarea) y la afirma con `assertSystemLogged(code, fn (array $c) => …)`, comprobando `reason` y los campos de `calc` clave.
- Cada archivo de test nuevo o tocado añade el trait `AssertsSystemLog` y llama `assertNoSensitiveDataLogged()` en al menos un test.
- **No cambiar comportamiento.** Solo se añaden líneas y las refactorizaciones mínimas que se nombran en cada tarea:
  - se conservan las firmas públicas; se extienden solo con parámetros opcionales o con claves nuevas en arrays de retorno;
  - los métodos públicos nuevos son puros o de solo lectura;
  - los tests existentes siguen verdes sin tocarlos, salvo para añadir el trait y aserciones nuevas.
- **Rutas repetitivas → `debug: true`:**
  - `ai.quota.checked` que no bloquea ni hace bypass;
  - `ai.media_fusion.skipped` con `no_media`;
  - `ai.media.reused` con `already_assessed_same_evaluation`;
  - `ai.media.batch_skipped`;
  - `decisions.escalation_policy.resolved` con `not_required`.
- Sin directorios nuevos en `app/`, sin cambios en `composer.json`/`package.json`. Commits `type: subject en minúsculas` sin trailers. PHPUnit, sin Pest. No mockear la DB. Nunca `git stash`.
- El worktree `.claude/worktrees/logging-fase-3` no tiene `.env`: los tests se corren con `APP_KEY=base64:$(openssl rand -base64 32) php artisan test --compact …`. Los warnings de dotenv son esperados; 0 fallos = verde.

## Review Focus

1. **Ninguna línea de hecho dentro de una transacción.** `ai.evaluation.completed`, `ai.evaluation.rules_only`, `ai.media.assessed` y `decisions.outcome.resolved` se emiten después de `DB::transaction()`. Hay tests que fuerzan un rollback y afirman `assertSystemNotLogged(...)` (Task 2 y Task 6).
2. **Riesgo y confianza recomputables:**
   - `ai.risk.calculated` trae base por severidad, boost de nivel de riesgo, recurrencia, geocerca, cada señal y el clamp;
   - `ai.evaluation.completed` encadena base → delta del agente → delta de fusión → persistido;
   - `ai.media_fusion.applied` trae antes/delta/después con sus límites.
   Los tests rehacen cada número. Lo fijan los Tasks 2 y 3.
3. **Heurística muerta visible (§8):** `ai.heuristics.evaluated` dice qué claves buscó (`payload.signature`/`payload.event_signature`, `signals.recent_duplicates_count`) y cuáles faltaban, sin registrar el valor del payload salvo que sea una de las firmas de ruido constantes. Lo fija el Task 1.
4. **Regla inválida (§8):** `decisions.rule.invalid` (`degraded`) sale por cada regla con una condición mal formada o un operador desconocido, con el path del nodo. Hoy esas reglas evalúan `false` en silencio. Lo fija el Task 5.
5. **Por qué salió esa decisión:** `decisions.outcome.resolved` dice qué fuente ganó (`hard_safety` / `tenant_rule` / `global_rule` / `ai_mapping` / `log_only_fallback`), el cálculo de revisión humana (`confidence < human_review_threshold`), el guard de contradicción de media y el piso crítico. Si hubo piso o revisión forzada, sale además su línea con el código original y el final. Lo fija el Task 6.
6. **Sin texto libre:** ningún test encuentra en `json_encode($this->systemLogEntries())` el texto del feedback del operador, el `reason` del request, el `name` de la regla, el `decision_reason` ni la explicación del modelo. Lo fijan los Tasks 2, 4 y 6.

---

## Mapa de archivos

| Archivo | Tarea |
|---|---|
| `app/Domains/AI/Support/AIEvaluationGate.php`, `Listeners/EvaluateOnEventContextBuilt.php`, `Jobs/EvaluateEventJob.php`, `Support/TenantAIQuota.php`, `Support/HeuristicRulesRunner.php` | 1 |
| `app/Domains/AI/Jobs/ReevaluateEventJob.php` (solo la llamada al gate) | 1 |
| `app/Domains/AI/Actions/CalculateRiskScore.php`, `Actions/DetectFalsePositive.php`, `Actions/EvaluateEventWithAI.php` | 2 |
| `app/Domains/AI/Support/MediaVerdictFusion.php`, `Actions/EvaluateEventWithAI.php` (fusión), `Actions/EvaluateEventMultimodally.php`, `Jobs/EvaluateEventMediaJob.php`, `Listeners/EvaluateMediaOnEventMediaAvailable.php` | 3 |
| `app/Domains/AI/Actions/ReevaluateEventWithNewEvidence.php`, `Jobs/ReevaluateEventJob.php`, `app/Domains/Decisions/Listeners/RequestReevaluationOnMediaAssessmentCompleted.php`, `app/Http/Controllers/AI/AIEvaluationController.php` (`reevaluate`) | 4 |
| `app/Domains/Decisions/Jobs/RunDecisionEngineJob.php`, `Jobs/ReevaluateDecisionJob.php`, `Actions/ApplyTenantRuleSet.php`, `Support/RuleConditionEvaluator.php`, `Actions/EvaluateDecisionRules.php` (early return) | 5 |
| `app/Domains/Decisions/Actions/ResolveDecisionOutcome.php`, `Actions/EvaluateDecisionRules.php` (narrativa post-commit) | 6 |
| `docs/SAM/logging.md` | todas (sección IA y nueva sección Decisiones) |
| Tests: métodos nuevos en los tests existentes del área (listados en cada tarea), que ya traen los fixtures | todas |

---

### Task 1: Gate, idempotencia, heurísticas y cuota

**Files:**
- Modify: `app/Domains/AI/Support/AIEvaluationGate.php`
- Modify: `app/Domains/AI/Listeners/EvaluateOnEventContextBuilt.php`
- Modify: `app/Domains/AI/Jobs/EvaluateEventJob.php`
- Modify: `app/Domains/AI/Jobs/ReevaluateEventJob.php` (solo cambia `shouldEvaluate` por `allows`)
- Modify: `app/Domains/AI/Support/HeuristicRulesRunner.php`
- Modify: `app/Domains/AI/Support/TenantAIQuota.php`
- Modify: `docs/SAM/logging.md`
- Test: `tests/Unit/Domains/AI/Support/AIEvaluationGateTest.php`, `tests/Feature/Domains/AI/{SafetyEventSkipsAIEvaluationTest,EvaluateEventJobTest,EvaluateEventWithAITest,TenantAIQuotaTest}.php`

**Refactor mínimo del gate.**
- Nuevo `public function skipReason(NormalizedEvent $event): ?string`, con la misma lógica que hoy tiene `shouldEvaluate`: devuelve `'skip_type'` si `$typeCode` está en `$this->skipEventTypes`, `'skip_category'` si `$categoryCode` está en `$this->skipCategories`, y null en otro caso.
- `shouldEvaluate()` pasa a `return $this->skipReason($event) === null;`.
- Nuevo `public function allows(NormalizedEvent $event, string $stage): bool`, que llama a `skipReason` y, si hay motivo, registra la línea de la tabla y devuelve false.
- Los tres callers (`EvaluateOnEventContextBuilt::handle`, `EvaluateEventJob::handle`, `ReevaluateEventJob::handle`) cambian `$gate->shouldEvaluate($normalizedEvent)` por `$gate->allows($normalizedEvent, '<stage>')`, con `stage` = `context_listener` | `evaluate_job` | `reevaluate_job`.

| Sitio / rama | Llamada |
|---|---|
| `AIEvaluationGate::allows`, con motivo | `SystemLog::skipped('ai.gate.skipped', reason: $reason, input: ['normalized_event_id' => $event->id, 'event_type_code' => $event->eventType?->code, 'category_code' => $event->eventCategory?->code, 'stage' => $stage], calc: ['config_key' => $reason === 'skip_type' ? 'ai.skip_evaluation_event_types' : 'ai.skip_evaluation_categories'], result: ['evaluated' => false, 'decision_engine_runs' => false])` |
| `EvaluateEventJob::handle`, `$normalizedEvent === null` | `SystemLog::skipped('ai.evaluation.skipped', reason: 'normalized_event_missing', input: ['normalized_event_id' => $this->normalizedEventId])` |
| `EvaluateEventJob::handle`, evaluación existente | cambia `->exists()` por `$existingId = AIEventEvaluation::query()->where('normalized_event_id', $normalizedEvent->id)->value('id')`; si no es null: `SystemLog::skipped('ai.evaluation.already_exists', reason: 'evaluation_exists', input: ['normalized_event_id' => $normalizedEvent->id], result: ['existing_evaluation_id' => $existingId])` |
| `HeuristicRulesRunner::evaluate`, antes de cada `return` | una única línea `SystemLog::ok('ai.heuristics.evaluated', input: ['normalized_event_id' => $event->id], calc: [...], result: ['short_circuit' => $decision !== null, 'rule' => $rule])` (ver definiciones) |
| `TenantAIQuota::blocks`, evento crítico | `SystemLog::ok('ai.quota.checked', input: ['normalized_event_id' => $event->id, 'purpose' => $purpose], calc: ['is_critical' => true, 'bypassed' => true], result: ['blocked' => false])` |
| `TenantAIQuota::blocks`, no crítico | `SystemLog::ok('ai.quota.checked', input: [...], calc: ['is_critical' => false, 'bypassed' => false, ...$usage], result: ['blocked' => $blocked, 'exceeded_by' => $usage['exceeded_by']], debug: ! $blocked)` |

**Por qué `decision_engine_runs` y no `incident: false`:** el motor solo corre con `AIEvaluationCompleted`, así que sin evaluación no hay decisión. El incidente, en cambio, puede abrirlo el fast path de emergencias del dominio Incidents sin pasar por la IA. Afirmar `incident: false` podría ser falso.

**Heurísticas — definiciones** (sin cambiar la lógica):
- Extrae la constante `private const int DUPLICATE_THRESHOLD = 3` y úsala en el `>=`.
- `$signatureSource`: `isset($payload['signature'])` → `'signature'`; si no, `isset($payload['event_signature'])` → `'event_signature'`; si no, null. Replica el `??` actual.
- `$noiseMatch`: `$signatureCandidate` si está en `KNOWN_NOISE_SIGNATURES`, o null. Es una constante del código, no dato libre. **El valor del payload no se registra nunca si no coincide.**
- `$duplicatesPresent = array_key_exists('recent_duplicates_count', $signals)`.
- `calc`:
  - `signature_source` => `$signatureSource`;
  - `noise_match` => `$noiseMatch`;
  - `known_noise_signatures_count` => `count(self::KNOWN_NOISE_SIGNATURES)`;
  - `duplicates_signal_present` => `$duplicatesPresent`;
  - `recent_duplicates_count` => `$duplicatesPresent ? (int) $signals['recent_duplicates_count'] : null`;
  - `duplicate_threshold` => `self::DUPLICATE_THRESHOLD`;
  - `signals_missing` => la lista de `'payload.signature'` (si `$signatureSource === null`) y `'signals.recent_duplicates_count'` (si `! $duplicatesPresent`).
- `$rule`: `'known_noise_signature'`, `'recent_duplicates_in_window'` o null.
- Para una única línea, reescribe el cuerpo con `$decision` y `$rule` en variables y un solo `return $decision` al final.

**Cuota — refactor mínimo** (mantiene el cortocircuito actual: si los tokens ya superan, las llamadas no se consultan):
- `blocks(NormalizedEvent $event, ?TenantAIProfileData $profile = null, string $purpose = 'text'): bool`. El tercer parámetro es opcional. `EvaluateEventWithAI::quotaExceeded` pasa `'text'` y `EvaluateEventMultimodally` pasa `'vision'`.
- `monthlyTokensExceeded`/`dailyCallsExceeded` se sustituyen por un privado `usage(int $teamId, TenantAIProfileData $profile): array`. Corre dentro de `TenantContext::for($teamId, …)` como hoy y devuelve:
  - `billing_period_key` => `now()->format('Y-m')`;
  - `tokens_meters_present` => bool;
  - `tokens_used_this_period` => `?int` (null sin meters);
  - `tokens_limit` => `$profile->monthlyTokenLimit`;
  - `calls_meter_present` => `?bool` (null si no se consultó);
  - `calls_today` => `?int` (null sin meter o si no se consultó);
  - `calls_limit` => `$profile->dailyCallLimit`;
  - `exceeded_by` => `'monthly_tokens'` | `'daily_calls'` | null.
- `exceeded(int $teamId, TenantAIProfileData $profile): bool` conserva su firma: `return $this->usage($teamId, $profile)['exceeded_by'] !== null;`.
- Sin meters, el límite no se aplica, como hoy. La línea lo hace visible con `tokens_meters_present = false`, sin cambiar el resultado.

- [ ] **Step 1: Write the failing tests**
  - `AIEvaluationGateTest` (unit, sin DB, con los helpers `eventWithType`/`eventWithCategory` del archivo): `skipReason` devuelve `skip_type`, `skip_category` y null en los mismos casos que ya prueba `shouldEvaluate`.
  - `SafetyEventSkipsAIEvaluationTest` (con `eventWithCategory`/`contextFor`):
    - `test_listener_skips_dispatch_for_safety_category_event` → `ai.gate.skipped`, `reason === 'skip_category'`, `input.category_code === 'safety'`, `input.stage === 'context_listener'`, `result.decision_engine_runs === false`;
    - `test_listener_skips_dispatch_for_low_value_event_type` → `skip_type`, `calc.config_key === 'ai.skip_evaluation_event_types'`;
    - `test_job_creates_no_evaluation_for_safety_category_event` → `stage === 'evaluate_job'`;
    - `test_reevaluation_job_does_not_evaluate_safety_category_event` → `stage === 'reevaluate_job'`;
    - `test_listener_dispatches_for_emergency_category_event` → `assertSystemNotLogged('ai.gate.skipped')`.
  - `EvaluateEventJobTest`:
    - `test_job_is_idempotent_on_same_normalized_event_id` → en la segunda corrida, `ai.evaluation.already_exists` con `result.existing_evaluation_id` = id de la primera evaluación;
    - `test_job_no_ops_when_event_missing` → `ai.evaluation.skipped` / `normalized_event_missing`.
  - `EvaluateEventWithAITest`:
    - `test_false_positive_short_circuit_via_known_noise_signature` → `ai.heuristics.evaluated` con `calc.signature_source === 'signature'`, `calc.noise_match === 'heartbeat'`, `result.rule === 'known_noise_signature'`;
    - `test_event_evaluates_with_ai_agent_persisting_full_record` (payload `['severity' => 'high']`) → `calc.signature_source === null`, `result.short_circuit === false`, `calc.signals_missing` contiene `'payload.signature'` y `'signals.recent_duplicates_count'` (la heurística muerta del §8, visible).
    - Test nuevo: payload `['severity' => 'low', 'signature' => 'algo-libre-xyz']` → `noise_match === null` y `json_encode($this->systemLogEntries())` no contiene `'algo-libre-xyz'`.
  - `TenantAIQuotaTest` (con `seedTokens`/`seedCalls`/`makeEvent`):
    - `test_tenant_ai_limits_prevent_over_quota_evaluation` → `ai.quota.checked` con `calc.tokens_used_this_period === 6_000_000`, `calc.tokens_limit === 5_000_000`, `calc.calls_today === null` (no consultado), `result.exceeded_by === 'monthly_tokens'`, `result.blocked === true`, `input.purpose === 'text'`;
    - `test_daily_call_limit_falls_back_to_rules_only` → `exceeded_by === 'daily_calls'`, y `calls_today >= calls_limit`;
    - `test_critical_events_bypass_the_token_and_call_quota` → `calc.bypassed === true` y sin `tokens_used_this_period`;
    - `test_vision_is_skipped_over_quota_for_non_critical_events` → `input.purpose === 'vision'` y `blocked === true`;
    - un test sin `AIMeterSeeder` (borra los meters con `UsageMeter::query()->delete()`) → `tokens_meters_present === false` y `blocked === false`.
  - `assertNoSensitiveDataLogged()` en cada archivo.

- [ ] **Step 2: Run to verify they fail** — `APP_KEY=base64:$(openssl rand -base64 32) php artisan test --compact tests/Unit/Domains/AI tests/Feature/Domains/AI/SafetyEventSkipsAIEvaluationTest.php tests/Feature/Domains/AI/EvaluateEventJobTest.php tests/Feature/Domains/AI/EvaluateEventWithAITest.php tests/Feature/Domains/AI/TenantAIQuotaTest.php` → FAIL (`No se registró [ai.gate.skipped]`, etc.).
- [ ] **Step 3: Implement** la tabla y los refactors.
- [ ] **Step 4: Catalog + run** — añade las filas de `ai.gate.skipped`, `ai.evaluation.skipped`, `ai.evaluation.already_exists`, `ai.heuristics.evaluated` y `ai.quota.checked`, con una nota sobre la heurística del §8. Corre `tests/Unit/Domains/AI`, `tests/Feature/Domains/AI` y `tests/Feature/Architecture/LoggingConventionsTest.php` → PASS.
- [ ] **Step 5: Commit** — `feat: log narrativo del gate de ia, heurísticas y cuota del tenant`

---

### Task 2: Riesgo, prioridad, falso positivo y cierre de la evaluación

**Files:**
- Modify: `app/Domains/AI/Actions/CalculateRiskScore.php`
- Modify: `app/Domains/AI/Actions/DetectFalsePositive.php`
- Modify: `app/Domains/AI/Actions/EvaluateEventWithAI.php`
- Modify: `docs/SAM/logging.md` (sustituye la fila actual de `ai.evaluation.rules_only`)
- Test: `tests/Feature/Domains/AI/{CalculateRiskScoreTest,EvaluateEventWithAITest,TenantAIQuotaTest}.php`

**`CalculateRiskScore`:**
- `execute()` conserva firma y retorno. Su cuerpo pasa a un privado `breakdown(NormalizedEvent $event, ?EventContextSnapshot $snapshot): array` que devuelve los términos. `execute()` registra la línea y devuelve `$terms['final']`.
- `severityWeight()` pasa a devolver `array{weight: float, severity_code: ?string, severity_source: 'payload'|'event_severity'|'default'}`:
  - `payload` si `$payload['severity'] ?? $payload['severity_code']` es clave de `SEVERITY_WEIGHTS`;
  - `event_severity` si lo es `$event->eventSeverity?->code`;
  - `default` (0.25) en otro caso.
- Sin snapshot, todos los boosts valen 0.0 y `snapshot_present = false`. El resultado no cambia.

| Sitio | Llamada |
|---|---|
| `CalculateRiskScore::execute` | `SystemLog::ok('ai.risk.calculated', input: ['normalized_event_id' => $event->id, 'snapshot_id' => $snapshot?->id], calc: $terms, result: ['risk_score' => $terms['final']])` |

`$terms` contiene:
- `severity_code` (con `LoggableCode::guard`), `severity_source`, `base`;
- `snapshot_present`;
- `risk_level` (`RiskLevel->value` o null), `risk_level_boost`;
- `recent_events_count`, `recurrence_thresholds` => `['high' => 10, 'medium' => 3]`, `recurrence_boost`;
- `sensitive_geofence` (bool), `geofence_boost`;
- `signal_boosts` => mapa `señal => boost aplicado` (0.0 si no estaba), con las tres claves de `SIGNAL_BOOSTS`;
- `signal_boost_total`;
- `sum` (sin clamp), `clamp` => `[0.0, 1.0]`, `final`.

**`DetectFalsePositive`:**
- `HIGH_CONFIDENCE_THRESHOLD` pasa a `public const float`.
- Nuevo `public function isFalsePositive(AIEventEvaluation $evaluation): bool`, puro, con la condición actual. `execute()` lo usa.
- Sin log dentro: `execute()` corre dentro de la transacción de `persistEvaluation`.

**`EvaluateEventWithAI` — narrativa post-commit.** Cada una de las cuatro ramas de `execute()` pasa de `return DB::transaction(...)` a:

```php
$evaluation = DB::transaction(...);
$this->narrate($evaluation, route: ..., ...);

return $evaluation;
```

| Rama | `route` | `base_confidence` | `risk_after_agent` | `agent_risk_delta` | agente |
|---|---|---|---|---|---|
| `$rulesDecision !== null` | `heuristic` | 0.95 | `$riskScore` | null | null |
| `quotaExceeded` | `quota_exceeded` | 0.5 | `$riskScore` | null | null |
| catch del agente | `agent_error` | 0.4 | `$riskScore` | null | null |
| agente ok | `ai` | `$result->confidenceScore` | `$finalRiskScore` | `$result->riskScoreDelta` | `$result` |

En el catch, la línea actual `ai.evaluation.rules_only` (emitida **antes** de persistir) se sustituye por `SystemLog::degraded('ai.evaluation.agent_failed', reason: 'agent_error', input: ['normalized_event_id' => $event->id], error: $exception)`, que afirma solo que el agente falló. `ai.evaluation.rules_only` pasa a `narrate`, después del commit.

Extrae `private const array PRIORITY_THRESHOLDS = ['urgent' => 0.85, 'high' => 0.6, 'normal' => 0.3]` y úsalo en `priorityFor()`, con el mismo `match`.

`private function narrate(AIEventEvaluation $evaluation, string $route, float $baseRisk, ?float $agentRiskDelta, float $riskAfterAgent, float $baseConfidence, ?array $fusion, ?AIEvaluationResult $result, bool $operatorFeedbackPresent, ?string $heuristicRule): void` emite, en este orden:

| Código | Llamada |
|---|---|
| rama no `ai` | `heuristic`: `SystemLog::skipped('ai.evaluation.rules_only', reason: 'heuristic_short_circuit', …)`. `quota_exceeded`/`agent_error`: `SystemLog::degraded('ai.evaluation.rules_only', reason: $route, …)`. `input: ['normalized_event_id' => $evaluation->normalized_event_id]`, `result: ['evaluation_id' => $evaluation->id, 'heuristic_rule' => $heuristicRule, 'error_class' => …]`. `error_class` es `class_basename` de la excepción solo en `agent_error`: `narrate` la lee de `$evaluation->signals_json['key_factors']['error_class']`, que ya se persiste. |
| `ai.priority.resolved` | `SystemLog::ok('ai.priority.resolved', input: ['evaluation_id' => $evaluation->id], calc: ['risk_score' => $evaluation->risk_score, 'classification' => $evaluation->classification->value, 'actionable' => $evaluation->classification->isActionable(), 'thresholds' => self::PRIORITY_THRESHOLDS], result: ['priority_level' => $evaluation->priority_level->value, 'requires_action' => $evaluation->requires_action])` |
| `ai.false_positive.checked` | `SystemLog::ok('ai.false_positive.checked', input: ['evaluation_id' => …], calc: ['classification' => …, 'confidence' => $evaluation->confidence_score, 'threshold' => DetectFalsePositive::HIGH_CONFIDENCE_THRESHOLD], result: ['is_false_positive' => $this->detectFalsePositive->isFalsePositive($evaluation)])` |
| `ai.evaluation.completed` | ver abajo |

La llamada de `ai.evaluation.completed`:

```php
SystemLog::ok('ai.evaluation.completed',
    input: [
        'normalized_event_id' => $evaluation->normalized_event_id,
        'evaluation_version' => $evaluation->evaluation_version,
        'route' => $route,
        'operator_feedback_present' => $operatorFeedbackPresent,
    ],
    calc: [
        'base_risk' => $baseRisk,
        'agent_risk_delta' => $agentRiskDelta,
        'risk_after_agent' => $riskAfterAgent,
        'fusion_risk_delta' => $fusion['riskDelta'] ?? 0.0,
        'risk_score' => $evaluation->risk_score,
        'base_confidence' => $baseConfidence,
        'fusion_confidence_delta' => $fusion['confidenceDelta'] ?? 0.0,
        'confidence' => $evaluation->confidence_score,
    ],
    result: [
        'evaluation_id' => $evaluation->id,
        'mode' => $evaluation->evaluation_mode->value,
        'classification' => $evaluation->classification->value,
        'priority_level' => $evaluation->priority_level->value,
        'model' => $evaluation->model_used,
        'input_tokens' => $result?->inputTokens,
        'output_tokens' => $result?->outputTokens,
        'cost_estimate' => $result?->costEstimate,
        'latency_ms' => $result?->latencyMs,
        'ai_inference_log_id' => AIInferenceLog::query()->where('evaluation_id', $evaluation->id)->value('id'),
    ],
);
```

Notas:
- **Fusión (`$fusion`):** es el array que ya devuelve `$fuseMedia(...)`. Para que exista fuera de la transacción, en las ramas `quota_exceeded` y `agent_error` saca la llamada `$fuseMedia(EventClassification::Unclear)` a una variable antes de `DB::transaction` y pásala por `use`. `fuse()` es puro (solo arrays), así que el resultado es idéntico. En `heuristic`, `$fusion = null`, como hoy.
- **`$heuristicRule`:** es el prefijo de `$rulesDecision['reason']` antes de `:` (`known_noise_signature` o `recent_duplicates_in_window`).
- **`$operatorFeedbackPresent`:** `$operatorFeedback !== null && $operatorFeedback !== []`. **Nunca el contenido.**
- **`$evaluation->evaluation_mode`** al emitir es el que dejó la transacción. La promoción posterior por media la registra el Task 3.

- [ ] **Step 1: Write the failing tests**
  - `CalculateRiskScoreTest` (con `event()`/`snapshot()` del archivo):
    - test nuevo con severidad `critical`, perfil `RiskLevel::High`, `recent_events_count = 3`, geocerca sensible y `repeated_panic_24h`. Afirma cada término (`base 0.6`, `risk_level_boost 0.2`, `recurrence_boost 0.08`, `geofence_boost 0.15`, `signal_boosts.repeated_panic_24h 0.1`) y que `round(max(0, min(1, base + risk_level_boost + recurrence_boost + geofence_boost + signal_boost_total)), 2) === calc.final === execute()`;
    - en `test_score_is_clamped_to_one`: `calc.sum > 1.0` y `calc.final === 1.0`;
    - en `test_severity_falls_back_to_the_event_severity_relation`: `severity_source === 'event_severity'`;
    - sin snapshot: `snapshot_present === false` y `final === base`.
  - `EvaluateEventWithAITest`:
    - ruta IA → `ai.evaluation.completed` con `input.route === 'ai'`, `result.mode === 'ai_text'`, `result.model === 'null-agent:1.0'`, `input_tokens === 120`, `output_tokens === 60`, `latency_ms === 5`, `ai_inference_log_id === AIInferenceLog::where('evaluation_id', $evaluation->id)->value('id')`. La cadena se rehace: `round(max(0, min(1, base_risk + agent_risk_delta)), 2) === risk_after_agent` y `risk_score === $evaluation->risk_score`;
    - `ai.priority.resolved`: recomputa la prioridad con `calc.thresholds` y `calc.actionable`, y compara con `result.priority_level` y con `$evaluation->priority_level->value`;
    - `ai.false_positive.checked` con `threshold === 0.85`;
    - agente que falla → `ai.evaluation.agent_failed` (con `error.class`) y, después, `ai.evaluation.rules_only` degraded `agent_error` con `result.evaluation_id` y `result.error_class === 'RuntimeException'`. Además, `ai.evaluation.completed` con `route === 'agent_error'` y `base_confidence === 0.4`;
    - heurística → `ai.evaluation.rules_only` **skipped** `heuristic_short_circuit` con `result.heuristic_rule === 'known_noise_signature'`.
    - Rollback: bindea con `$this->app->instance(DetectFalsePositive::class, new class extends DetectFalsePositive { public function execute(AIEventEvaluation $evaluation): bool { throw new RuntimeException('boom'); } })`. `execute` lanza; afirma `AIEventEvaluation::count() === 0`, `assertSystemNotLogged('ai.evaluation.completed')` y `assertSystemNotLogged('ai.priority.resolved')`.
    - Feedback: `app(EvaluateEventWithAI::class)->execute($event, operatorFeedback: ['verdicts' => [['note' => 'texto-operador-xyz']]])` → `operator_feedback_present === true`, y el JSON de las entradas no contiene `'texto-operador-xyz'` ni `'Evaluación determinista'` (la explicación del agente).
  - `TenantAIQuotaTest`: `test_tenant_ai_limits_prevent_over_quota_evaluation` → `ai.evaluation.rules_only` degraded `quota_exceeded`, y `ai.evaluation.completed` con `route === 'quota_exceeded'`.
  - `assertNoSensitiveDataLogged()` en cada archivo.

- [ ] **Step 2: Run to verify they fail** — `APP_KEY=… php artisan test --compact tests/Feature/Domains/AI/CalculateRiskScoreTest.php tests/Feature/Domains/AI/EvaluateEventWithAITest.php tests/Feature/Domains/AI/TenantAIQuotaTest.php` → FAIL.
- [ ] **Step 3: Implement** — `breakdown`, `isFalsePositive`, `narrate` y la reubicación de la línea del catch.
- [ ] **Step 4: Catalog + run**
  - Filas nuevas: `ai.risk.calculated`, `ai.priority.resolved`, `ai.false_positive.checked`, `ai.evaluation.agent_failed` y `ai.evaluation.completed`.
  - Sustituye la fila de `ai.evaluation.rules_only`: outcome `degraded` (`quota_exceeded`, `agent_error`) / `skipped` (`heuristic_short_circuit`), campos `evaluation_id`, `heuristic_rule`, `error_class`.
  - Corre `tests/Feature/Domains/AI`, `tests/Feature/Domains/Decisions` y `LoggingConventionsTest` → PASS.
- [ ] **Step 5: Commit** — `feat: log narrativo del cálculo de riesgo, prioridad y cierre de la evaluación de ia`

---

### Task 3: Fusión del veredicto visual y evaluación de media

**Files:**
- Modify: `app/Domains/AI/Support/MediaVerdictFusion.php`
- Modify: `app/Domains/AI/Actions/EvaluateEventWithAI.php` (solo la fusión)
- Modify: `app/Domains/AI/Actions/EvaluateEventMultimodally.php`
- Modify: `app/Domains/AI/Jobs/EvaluateEventMediaJob.php`
- Modify: `app/Domains/AI/Listeners/EvaluateMediaOnEventMediaAvailable.php`
- Modify: `docs/SAM/logging.md`
- Test: `tests/Feature/Domains/AI/{MediaVerdictFusionTest,EvaluateEventMultimodallyTest,MediaAssessmentNoDoublePayTest,MultimodalImageOnlyTest,EvaluateEventMediaJobTest,TenantAIQuotaTest,VisionRetryAndValidationTest}.php`

**`MediaVerdictFusion` — refactor mínimo.**
- Nuevo método público y puro `explain(array $mediaAssessments, EventClassification $classification, bool $isCriticalEvent = false): array`. Devuelve `array{branch: string, fusion: ?array, assessed: int, confirms: int, contradicts: int, visible_threats: int, dismissive: bool}` y contiene el cuerpo actual de `fuse()`.
- `branch` vale:
  - `no_media` si `$assessed === 0`;
  - `no_verdict` si no hay ni confirmaciones ni contradicciones;
  - `confirms`;
  - `critical_no_reduce`;
  - `contradicts`.
- `dismissive` se calcula solo cuando la fusión se aplica; en `no_media`/`no_verdict` va `false`.
- `fuse()` conserva su firma: `return $this->explain(...)['fusion'];`.

**`EvaluateEventWithAI`:**
- El closure `$fuseMedia` pasa a `$explainMedia = fn (EventClassification $classification): array => $this->mediaVerdictFusion->explain($input->mediaAssessments, $classification, $isCriticalEvent);`.
- Cada rama usa `$explain['fusion']` donde hoy usa el resultado de `$fuseMedia`.
- `narrate()` recibe `?array $explain` en lugar de `?array $fusion` y lee los deltas de `$explain['fusion']`. En las rutas no heurísticas, además, emite **antes** de `ai.priority.resolved`:

| Caso | Llamada |
|---|---|
| `$explain['fusion'] === null` | `SystemLog::skipped('ai.media_fusion.skipped', reason: $explain['branch'], input: ['evaluation_id' => $evaluation->id], calc: ['media_assessed_count' => $explain['assessed']], debug: $explain['branch'] === 'no_media')` |
| fusión aplicada | `SystemLog::ok('ai.media_fusion.applied', input: ['evaluation_id' => $evaluation->id, 'classification' => $evaluation->classification->value, 'is_critical_event' => $isCriticalEvent], calc: ['branch' => $explain['branch'], 'media_assessed_count' => …, 'media_confirms_count' => …, 'media_contradicts_count' => …, 'media_visible_threat_count' => …, 'dismissive' => …, 'confidence_before' => $baseConfidence, 'confidence_delta' => $explain['fusion']['confidenceDelta'], 'confidence_bounds' => [0.05, 0.99], 'confidence_after' => $evaluation->confidence_score, 'risk_before' => $riskAfterAgent, 'risk_delta' => $explain['fusion']['riskDelta'], 'risk_bounds' => [0.0, 1.0], 'risk_after' => $evaluation->risk_score])` |

`narrate()` recibe además `bool $isCriticalEvent`. Los límites de clamp son los literales de `applyFusion()`: extráelos a `private const array CONFIDENCE_BOUNDS = [0.05, 0.99]` y úsalos en los dos sitios.

**`EvaluateEventMultimodally::execute`:**

| Sitio / rama | Llamada |
|---|---|
| `$mediaContexts->isEmpty()` al entrar | `SystemLog::skipped('ai.media.batch_skipped', reason: 'no_media', input: ['evaluation_id' => $evaluation->id], debug: true)` |
| tras el filtro de solo imágenes, si se excluyó alguna | `SystemLog::skipped('ai.media.filtered', reason: 'non_image_media', input: ['evaluation_id' => …], calc: ['received_count' => $receivedCount, 'image_count' => $mediaContexts->count(), 'excluded_count' => $receivedCount - $mediaContexts->count(), 'excluded_media_types' => $excludedTypes])` |
| `$existing !== null` | `SystemLog::skipped('ai.media.reused', reason: 'already_assessed_same_evaluation', input: ['evaluation_id' => …, 'event_media_context_id' => $media->id], result: ['assessment_id' => $existing->id, 'assessment_result' => $existing->result->value], debug: true)` |
| `$prior !== null` (las dos veces) | `SystemLog::skipped('ai.media.reused', reason: 'prior_conclusive_assessment', input: [...], calc: ['checked' => 'before_lock'\|'after_lock'], result: ['assessment_id' => $prior->id, 'prior_evaluation_id' => $prior->evaluation_id, 'assessment_result' => $prior->result->value])` |
| `quota->blocks(...)` | la llamada pasa `purpose: 'vision'`; la línea `ai.media.assessment_skipped` / `quota_exceeded` no cambia |
| tras `$assessment = DB::transaction(...)` del éxito | `SystemLog::ok('ai.media.assessed', input: ['evaluation_id' => …, 'event_media_context_id' => $media->id, 'assessment_type' => $assessmentType->value, 'media_type' => ($media->media_type ?? MediaType::Snapshot)->value], calc: ['remaining_slots_before' => $remainingSlots, 'max_images_per_event' => $this->maxImagesPerEvent()], result: ['assessment_id' => $assessment->id, 'assessment_result' => $output->result->value, 'confidence' => round($output->confidenceScore, 2), 'visible_threat' => ($output->extractedSignals['visible_threat'] ?? null) === true, 'model' => $output->modelUsed, 'input_tokens' => $output->inputTokens, 'output_tokens' => $output->outputTokens, 'cost_estimate' => $output->costEstimate, 'latency_ms' => $output->latencyMs])`, antes del `$remainingSlots--` |
| tras `refreshInferenceMediaCount` y antes de `MediaAssessmentCompleted::dispatch` | `SystemLog::ok('ai.media.batch_completed', input: ['evaluation_id' => …], calc: ['received_count' => $receivedCount, 'image_count' => …, 'remaining_slots_at_start' => $slotsAtStart, 'max_images_per_event' => …], result: ['reused_count' => $reusedCount, 'created_count' => $createdAssessments->count(), 'retry_pending' => $retryableFailure !== null, 'mode_before' => $modeBefore, 'mode_after' => $evaluation->evaluation_mode?->value])` |

Definiciones:
- **`$receivedCount`:** `$mediaContexts->count()` antes del filtro.
- **`$excludedTypes`:** la lista única de `media_type->value` de los excluidos.
- **`$reusedCount`:** contador que se incrementa en las ramas `existing` y `prior`.
- **`$slotsAtStart`:** `$remainingSlots` justo después de calcularlo.
- **`$modeBefore`:** `$evaluation->evaluation_mode?->value` antes de `promoteEvaluationMode`.
- **Sin texto:** nunca `summary_text`, `extracted_signals_json` completo ni `storage_path`.

**`EvaluateEventMediaJob::handle`:**
- evaluación inexistente → `SystemLog::skipped('ai.media.job_skipped', reason: 'evaluation_missing', input: ['evaluation_id' => $this->evaluationId])`;
- `$mediaIds === []` → `reason: 'no_media_ids'`;
- `$mediaContexts->isEmpty()` → `reason: 'media_not_found'`, `calc: ['requested_count' => count($mediaIds)]`.

**`EvaluateMediaOnEventMediaAvailable::handle`:** sin evaluación → `SystemLog::skipped('ai.media.assessment_deferred', reason: 'no_evaluation_yet', input: ['normalized_event_id' => $event->normalizedEvent->id, 'event_media_context_id' => $event->media->id])`. No afirma que otro componente la vaya a barrer: en eventos que el gate salta, no la barre nadie.

- [ ] **Step 1: Write the failing tests**
  - `MediaVerdictFusionTest` (con `evaluatedEvent()`/`makeMedia()`/`verdicts()`):
    - `explain()` devuelve cada `branch` con los mismos casos que ya prueban `fuse()`: vacío, inconcluso, confirma, crítico que contradice y contradice;
    - `test_reevaluation_with_contradicting_media_degrades_confidence…` → `ai.media_fusion.applied` con `branch === 'contradicts'`, `media_contradicts_count === 2`, `media_assessed_count === 3`; se rehace `round(max(0.05, min(0.99, confidence_before + confidence_delta)), 2) === confidence_after === (float) $reevaluation->confidence_score`, y lo mismo con el riesgo usando `risk_bounds`;
    - `test_reevaluation_without_media_assessments_is_unchanged` → `ai.media_fusion.skipped` / `no_media`;
    - `test_inconclusive_only_assessments_leave_the_evaluation_unchanged` → `no_verdict`;
    - `test_reevaluation_of_a_critical_event_with_contradicting_media_keeps_its_risk` → `branch === 'critical_no_reduce'`, `risk_delta === 0.0` y `is_critical_event === true`.
  - `EvaluateEventMultimodallyTest`:
    - `test_multimodal_pipeline_persists_assessment_per_media` → `ai.media.assessed` con `input_tokens === 250`, `output_tokens === 80` (`NullMediaAssessmentAgent`) y `assessment_result`; `ai.media.batch_completed` con `created_count` igual a las medias;
    - `test_multimodal_pipeline_is_idempotent_per_evaluation_and_media` → en la segunda corrida, `ai.media.reused` / `already_assessed_same_evaluation` y `batch_completed.created_count === 0`;
    - `test_empty_media_collection_is_a_no_op` → `ai.media.batch_skipped`.
  - `MediaAssessmentNoDoublePayTest::test_media_assessed_under_a_previous_version_is_reused_not_paid_again` → `ai.media.reused` / `prior_conclusive_assessment` con `prior_evaluation_id` de la versión anterior.
  - `MultimodalImageOnlyTest` → `ai.media.filtered` con `excluded_media_types` que contiene `video` y `audio`.
  - `EvaluateEventMediaJobTest::test_listener_no_ops_when_no_evaluation_yet` → `ai.media.assessment_deferred`. Test nuevo del job con un `evaluationId` inexistente → `ai.media.job_skipped` / `evaluation_missing`.
  - `TenantAIQuotaTest::test_vision_is_skipped_over_quota_for_non_critical_events` → `ai.quota.checked` con `purpose === 'vision'`. Ya lo cubre el Task 1; aquí se verifica que el parámetro llegue.
  - `VisionRetryAndValidationTest` (fixture `makeEvaluationWithMedia`): las líneas existentes (`assessment_skipped`, `rejected`, `retry`, `unavailable`) siguen saliendo, y `batch_completed.retry_pending === true` en `test_retryable_error_is_rethrown_before_the_final_attempt`.
  - En cada archivo, `assertNoSensitiveDataLogged()`, y que el JSON de las entradas no contenga el `summary_text` del assessment creado.

- [ ] **Step 2: Run to verify they fail** — `APP_KEY=… php artisan test --compact tests/Feature/Domains/AI` → FAIL.
- [ ] **Step 3: Implement** — `explain`, la fusión en `narrate` y las líneas de media.
- [ ] **Step 4: Catalog + run** — filas de `ai.media_fusion.applied`, `ai.media_fusion.skipped`, `ai.media.batch_skipped`, `ai.media.filtered`, `ai.media.reused`, `ai.media.assessed`, `ai.media.batch_completed`, `ai.media.job_skipped` y `ai.media.assessment_deferred`; añade las líneas `debug` al párrafo "Líneas en debug". Corre `tests/Feature/Domains/AI`, `tests/Feature/Domains/Decisions` y `LoggingConventionsTest` → PASS.
- [ ] **Step 5: Commit** — `feat: log narrativo de la fusión del veredicto visual y la evaluación de media`

---

### Task 4: Reevaluación

**Files:**
- Modify: `app/Domains/AI/Actions/ReevaluateEventWithNewEvidence.php`
- Modify: `app/Domains/AI/Jobs/ReevaluateEventJob.php`
- Modify: `app/Domains/Decisions/Listeners/RequestReevaluationOnMediaAssessmentCompleted.php`
- Modify: `app/Http/Controllers/AI/AIEvaluationController.php` (`reevaluate`)
- Modify: `docs/SAM/logging.md`
- Test: `tests/Feature/Domains/AI/{ReevaluateEventTest,AIEvaluationControllerTest}.php`, `tests/Feature/Domains/Decisions/RequestReevaluationOnMediaAssessmentCompletedTest.php`

| Sitio / rama | Llamada |
|---|---|
| `ReevaluateEventJob::handle`, evento inexistente | `SystemLog::skipped('ai.reevaluation.skipped', reason: 'normalized_event_missing', input: ['normalized_event_id' => $this->normalizedEventId, 'trigger_type' => $this->triggerType])` |
| `ReevaluateEventJob::handle`, gate | ya lo cubre `allows($normalizedEvent, 'reevaluate_job')` (Task 1) |
| `ReevaluateEventWithNewEvidence::openRequest`, tras el `update` del request previo | `SystemLog::ok('ai.reevaluation.superseded', input: ['normalized_event_id' => $event->id, 'trigger_type' => $trigger->value], result: ['superseded_request_id' => $existing->id, 'previous_status' => $previousStatus])`, con `$previousStatus = $existing->status->value` capturado antes del `update` |
| `ReevaluateEventWithNewEvidence::execute`, tras marcar el request `Completed` | `SystemLog::ok('ai.reevaluation.completed', input: ['normalized_event_id' => $event->id, 'trigger_type' => $trigger->value, 'trigger_reference_id' => $triggerReferenceId, 'reason_present' => $reason !== null && $reason !== ''], result: ['reevaluation_request_id' => $request->id, 'evaluation_id' => $evaluation->id, 'evaluation_version' => $evaluation->evaluation_version])` |
| `RequestReevaluationOnMediaAssessmentCompleted::handle`, `normalized_event_id === null` | `SystemLog::skipped('ai.reevaluation.not_requested', reason: 'no_normalized_event', input: ['evaluation_id' => $evaluation->id])` |
| `reopenPipeline`, `! $decisionExists` | `… reason: 'decision_pending', input: ['evaluation_id' => …, 'normalized_event_id' => …]`. El motor que aún no corrió ya leerá el hecho `media_assessment`. |
| todas las medias ya evaluadas en otra versión | `… reason: 'media_already_assessed', calc: ['media_context_count' => $mediaContextIds->count(), 'assessed_elsewhere_count' => $assessedElsewhere->unique()->count()]` |
| incidente terminal | `… reason: 'incident_terminal', result: ['incident_id' => $incident->id, 'incident_status_id' => $incident->incident_status_id]` |
| pedido | antes del `ReevaluateEventJob::dispatch(...)`: `SystemLog::ok('ai.reevaluation.requested', input: ['normalized_event_id' => …, 'evaluation_id' => $evaluation->id, 'trigger_type' => ReevaluationTrigger::MediaArrived->value, 'trigger_reference_id' => $latest?->id, 'requested_by' => 'media_assessment'], calc: ['debounce_s' => $debounce, 'new_media_count' => $mediaContextIds->diff($assessedElsewhere)->count()], result: ['latest_assessment_result' => $latest?->result?->value])`. Extrae `$debounce = $this->debounceSeconds()` y pásalo a `->delay($debounce)`. |
| `AIEvaluationController::reevaluate`, antes del `dispatch` | `SystemLog::ok('ai.reevaluation.requested', input: ['normalized_event_id' => $evaluation->normalized_event_id, 'evaluation_id' => $evaluation->id, 'trigger_type' => ReevaluationTrigger::ManualReviewRequested->value, 'requested_by' => 'operator', 'reason_present' => is_string($reason) && $reason !== ''], calc: ['debounce_s' => 0])` |

Notas:
- **`ai.reevaluation.requested` dice "pedido", no "encolado".** `ReevaluateEventJob` es `ShouldBeUniqueUntilProcessing`: si ya hay uno pendiente para el mismo `(evento, trigger)`, este pedido se absorbe en él y no se encola otro. El catálogo lo dice. Por eso ni `result` ni el nombre afirman un job nuevo.
- **Nunca se registran** el `reason` del request, el del job (`'Deferred media assessed: …'`) ni el del operador: solo `reason_present`.
- **`incident_status_id`:** `Incident::status()` es una relación `BelongsTo IncidentStatus` (no un enum), y `isTerminal()` ya la cargó. Se registra el id del estado, nunca su `name`.

- [ ] **Step 1: Write the failing tests**
  - `RequestReevaluationOnMediaAssessmentCompletedTest` (con `makeAssessedEvaluation`/`assessNewMedia`/`handle`), un `assertSystemLogged` por test existente:
    - `test_dispatches_reevaluation_when_decision_already_ran` → `ai.reevaluation.requested` con `calc.debounce_s === 60` (default de `ai.reevaluation.media_debounce_seconds`), `trigger_reference_id === $assessment->id` y `new_media_count === 1`;
    - `test_no_ops_when_decision_has_not_run_yet` → `decision_pending`;
    - `test_no_ops_when_incident_is_terminal` → `incident_terminal`;
    - `test_no_ops_when_media_was_already_assessed_under_previous_evaluation` → `media_already_assessed`;
    - con `config(['ai.reevaluation.media_debounce_seconds' => 5])` → `debounce_s === 5`.
  - `ReevaluateEventTest`:
    - `test_reevaluation_deduplicates_pending_requests` → `ai.reevaluation.superseded` con `superseded_request_id` = el primer request y `previous_status === 'pending'`;
    - `test_reevaluation_increments_version` → `ai.reevaluation.completed` con `evaluation_version === 2`;
    - test nuevo: `(new ReevaluateEventJob(999_999, ReevaluationTrigger::MediaArrived->value))->handle(app(ReevaluateEventWithNewEvidence::class), app(AIEvaluationGate::class))` → `ai.reevaluation.skipped`;
    - con `reason: 'motivo-libre-xyz'` → `reason_present === true`, y el JSON de las entradas no contiene `'motivo-libre-xyz'`.
  - `AIEvaluationControllerTest::test_reevaluate_endpoint_dispatches_job` → `ai.reevaluation.requested` con `requested_by === 'operator'` y `reason_present === true`; el JSON de las entradas no contiene `'manual trigger'`.
  - `assertNoSensitiveDataLogged()` en cada archivo.

- [ ] **Step 2: Run to verify they fail** — `APP_KEY=… php artisan test --compact tests/Feature/Domains/AI/ReevaluateEventTest.php tests/Feature/Domains/AI/AIEvaluationControllerTest.php tests/Feature/Domains/Decisions/RequestReevaluationOnMediaAssessmentCompletedTest.php` → FAIL.
- [ ] **Step 3: Implement** la tabla.
- [ ] **Step 4: Catalog + run** — filas de `ai.reevaluation.skipped`, `ai.reevaluation.superseded`, `ai.reevaluation.completed`, `ai.reevaluation.not_requested` y `ai.reevaluation.requested` (con la nota de coalescencia). Corre `tests/Feature/Domains/AI`, `tests/Feature/Domains/Decisions` y `LoggingConventionsTest` → PASS.
- [ ] **Step 5: Commit** — `feat: log narrativo de reevaluaciones pedidas, absorbidas y completadas`

---

### Task 5: Motor de decisiones — entrada, ruleset y reglas

**Files:**
- Modify: `app/Domains/Decisions/Jobs/RunDecisionEngineJob.php`
- Modify: `app/Domains/Decisions/Jobs/ReevaluateDecisionJob.php`
- Modify: `app/Domains/Decisions/Actions/EvaluateDecisionRules.php` (solo el early return)
- Modify: `app/Domains/Decisions/Actions/ApplyTenantRuleSet.php`
- Modify: `app/Domains/Decisions/Support/RuleConditionEvaluator.php`
- Modify: `docs/SAM/logging.md` (sección nueva `### Decisiones (decisions)`)
- Test: `tests/Feature/Domains/Decisions/{RunDecisionEngineJobTest,EvaluateDecisionRulesTest}.php`

**`RuleConditionEvaluator` — método nuevo, puro.** `matches()` y `evaluateLeaf()` no cambian.
- Firma: `public function problems(array $conditions, string $path = '$'): array`, que devuelve `list<array{path: string, problem: string, operator: ?string, field: ?string}>`. Recorre el árbol con la **misma** semántica que `matches()`:
  - `$conditions === []` → `[]`;
  - `isset($conditions['all']) && is_array($conditions['all'])`, o lo mismo con `any` → recursión por cada hijo con path `"{$path}.all.{$i}"` / `"{$path}.any.{$i}"`. Un hijo que no es array → `malformed_condition` en su path;
  - `isset($conditions['field'], $conditions['operator'])` → `[]` si `(string) $conditions['operator']` está en `self::OPERATORS`. Si no, `unknown_operator` con `operator => LoggableCode::guard($conditions['operator'])` y `field => LoggableCode::guard($conditions['field'])`;
  - cualquier otro nodo → `malformed_condition` (es la rama `return false` silenciosa de `matches()`).
- Nunca registra `value` (puede ser texto del tenant).

**`ApplyTenantRuleSet::execute`:**

| Sitio / rama | Llamada |
|---|---|
| `$ruleSet === null` | `SystemLog::degraded('decisions.ruleset.missing', reason: 'no_active_ruleset', input: ['ai_evaluation_id' => $eval->id, 'default_ruleset_code' => LoggableCode::guard($this->rulesResolver->resolve($teamId)->defaultRuleSetCode)], result: ['falls_back_to' => 'ai_mapping'])`. `ResolveDecisionOutcome` con `$matchedRules` vacío va a la rama de mapeo de la IA. |
| en el `foreach`, antes de `matches()` | `$evaluated++`; `$problems = $this->conditionEvaluator->problems($rule->conditions_json ?? [])`; si no es `[]`: `SystemLog::degraded('decisions.rule.invalid', reason: $problems[0]['problem'], input: ['rule_id' => $rule->id, 'rule_code' => LoggableCode::guard($rule->code), 'ruleset_id' => $ruleSet->id, 'rule_team_id' => $rule->team_id], calc: ['problems' => $problems, 'problems_count' => count($problems)], result: ['invalid_nodes_evaluate_as' => false])` |
| rompe por `stop_processing` | guarda `$stoppedAt = $rule` antes del `break` |
| tras el bucle | `SystemLog::ok('decisions.rules.evaluated', input: ['ai_evaluation_id' => $eval->id], calc: ['ruleset_id' => $ruleSet->id, 'ruleset_scope' => $ruleSet->team_id !== null ? 'tenant' : 'global', 'candidate_count' => $rules->count(), 'evaluated_count' => $evaluated, 'matched_rule_ids' => $matched->pluck('id')->all(), 'matched' => $matched->map(fn (DecisionRule $r) => LoggableCode::guard($r->code))->all(), 'stopped_at' => $stoppedAt !== null ? LoggableCode::guard($stoppedAt->code) : null, 'facts' => [...]], result: ['matched_count' => $matched->count()])` |

- **`facts` en `decisions.rules.evaluated`:** solo `classification`, `risk_score`, `confidence_score`, `priority_level`, `media_assessment`, `has_context_snapshot` y `event_type_code` (este con `LoggableCode::guard`) de `$facts`. Son los que deciden las reglas por defecto. Nunca el mapa completo.
- **Por qué estas líneas van dentro de la transacción:** afirman el cálculo de reglas sobre filas preexistentes (ruleset y reglas), no la decisión. Siguen siendo ciertas si la transacción se revierte.
- `ApplyTenantRuleSet` recibe `LoggableCode` por `use`; `$this->rulesResolver` ya está inyectado.

**Entrada del motor:**

| Sitio / rama | Llamada |
|---|---|
| `RunDecisionEngineJob::handle` / `ReevaluateDecisionJob::handle`, `$eval === null` | `SystemLog::skipped('decisions.engine.skipped', reason: 'evaluation_missing', input: ['ai_evaluation_id' => $this->aiEvaluationId, 'stage' => 'engine_job'\|'reevaluate_job'])` |
| `RunDecisionEngineJob::handle`, decisión existente | cambia `->exists()` por `->value('id')`; si hay: `SystemLog::skipped('decisions.decision.already_exists', reason: 'decision_exists', input: ['ai_evaluation_id' => $eval->id, 'stage' => 'engine_job'], result: ['decision_id' => $existingId])` |
| `EvaluateDecisionRules::execute`, `$existing !== null` | `SystemLog::skipped('decisions.decision.already_exists', reason: 'decision_exists', input: ['ai_evaluation_id' => $eval->id, 'stage' => 'evaluate_rules'], result: ['decision_id' => $existing->id])` |

- [ ] **Step 1: Write the failing tests**
  - `RunDecisionEngineJobTest`:
    - `test_job_no_ops_when_evaluation_missing` → `decisions.engine.skipped`;
    - `test_job_is_idempotent_for_same_evaluation_id` → `decisions.decision.already_exists` con `stage === 'engine_job'`.
  - `EvaluateDecisionRulesTest` (setUp con `DecisionOutcomeSeeder`/`IncidentsSeeder`):
    - `test_panic_event_high_risk_creates_incident_decision_via_safety_rule` → `decisions.rules.evaluated` con `matched === ['safety-high-risk']`, `stopped_at === 'safety-high-risk'`, `ruleset_scope === 'global'` y `evaluated_count === 1`;
    - `test_idempotent_when_called_twice_for_same_evaluation` → `decisions.decision.already_exists` con `stage === 'evaluate_rules'`;
    - test nuevo sin `RuleSet` → `decisions.ruleset.missing` con `default_ruleset_code === 'default'`, y la decisión se crea igual;
    - test nuevo con `DecisionRule::factory()` y `conditions_json = ['all' => [['field' => 'risk_score', 'operator' => 'between', 'value' => 1]]]` → `decisions.rule.invalid` / `unknown_operator` con `calc.problems[0]['path'] === '$.all.0'` y `operator === 'between'`;
    - otra regla con `['foo' => 'bar']` → `malformed_condition` con path `'$'`;
    - en los dos, la regla no está en `decisions.rules.evaluated.calc.matched`, y el comportamiento (decisión creada, código) es el mismo que sin la regla;
    - test nuevo con `'name' => 'Nombre libre xyz'` en la regla → el JSON de las entradas no contiene `'Nombre libre xyz'`.
  - `assertNoSensitiveDataLogged()` en cada archivo.

- [ ] **Step 2: Run to verify they fail** — `APP_KEY=… php artisan test --compact tests/Feature/Domains/Decisions` → FAIL.
- [ ] **Step 3: Implement** — `problems()` y la tabla.
- [ ] **Step 4: Catalog + run** — sección nueva `### Decisiones (decisions)` con las filas de `decisions.engine.skipped`, `decisions.decision.already_exists`, `decisions.ruleset.missing`, `decisions.rule.invalid` y `decisions.rules.evaluated`, y una nota: "`decisions.rule.invalid` = hallazgo §8, antes silencioso; la regla sigue evaluando `false` en ese nodo". Corre `tests/Feature/Domains/Decisions` y `LoggingConventionsTest` → PASS.
- [ ] **Step 5: Commit** — `feat: log narrativo del motor de decisiones, ruleset y reglas inválidas`

---

### Task 6: Desenlace, prioridad y escalación de la decisión

**Files:**
- Modify: `app/Domains/Decisions/Actions/ResolveDecisionOutcome.php`
- Modify: `app/Domains/Decisions/Actions/EvaluateDecisionRules.php`
- Modify: `docs/SAM/logging.md`
- Test: `tests/Feature/Domains/Decisions/{EvaluateDecisionRulesTest,CriticalSeverityFloorTest,MediaContradictionGuardTest,FalseAlarmDecisionMatrixTest}.php`

**`ResolveDecisionOutcome` — el array de retorno gana la clave `explain`.** Actualiza los tres `@param`/`@return` `array{…}` añadiendo `explain: array<string, mixed>`. Las claves existentes no cambian. Sin log dentro: corre en la transacción de `EvaluateDecisionRules`.
- Extrae `private const float ESCALATE_RISK_THRESHOLD = 0.85` y `private const float INCIDENT_RISK_THRESHOLD = 0.6`, y úsalas en `mapClassificationToOutcome()` con el mismo `match`.
- **`resolve()`:** tras calcular `$requiresHumanReview`, arma `$explain = ['confidence' => $confidence, 'human_review_threshold' => $policy->humanReviewConfidenceThreshold, 'review_by_confidence' => $requiresHumanReview, 'risk' => (float) ($eval->risk_score ?? 0.0)]`. Cada `return` añade `'explain' => [...$explain, 'source' => …]`:
  - `hard_safety`;
  - `tenant_rule`;
  - `global_rule`;
  - `ai_mapping`, más `'ai_outcome_code' => $aiOutcomeCode->value` y `'ai_mapping_thresholds' => ['escalate' => self::ESCALATE_RISK_THRESHOLD, 'incident' => self::INCIDENT_RISK_THRESHOLD]`;
  - `log_only_fallback`, más `'ai_outcome_code' => $aiOutcomeCode->value` y `'ai_outcome_missing' => true`.
- **`guardMediaContradiction()`:** en cada `return $resolved` temprano, antes pon `$resolved['explain']['guard_check'] = 'outcome_not_terminal' | 'media_not_contradicting' | 'no_prior_actionable_decision'`. En los dos últimos añade `'latest_media_result' => $latest?->value`, guardando antes `$latest = $this->latestMediaAssessmentResult($eval)`: una sola llamada, como hoy. El array forzado lleva `'explain' => [...$resolved['explain'], 'guard_check' => 'forced_review', 'guard_from_code' => $resolved['outcome']->code, 'latest_media_result' => MediaAssessmentResult::ContradictsEvent->value]`.
- **`applyCriticalSeverityFloor()`:** igual con `floor_check`:
  - `'outcome_creates_incident'`;
  - `'rule_chose_review'` (si `$ruleChoseReview`; no se llama a `isCriticalSeverity`, como hoy);
  - `'not_critical'`;
  - `'floored'`, con `'floor_from_code' => $resolved['outcome']->code`.

**`EvaluateDecisionRules::execute` — narrativa post-commit.**
- Declara `$narrative = []` y cambia `return DB::transaction(function () use ($eval, $context) {` por `$decision = DB::transaction(function () use ($eval, $context, &$narrative) {`. El closure sigue devolviendo `$decision`.
- Dentro del closure, sin cambiar la lógica:
  - `$reviewByResolver = $resolved['requiresHumanReview']` antes de la línea que lo amplía;
  - `$reviewByOutcome = $resolved['outcome']->code === DecisionOutcomeCode::RequireHumanReview->value`;
  - `$mappedPriority = $this->mapPriority(...)`, `$priority = $mappedPriority` y `$criticalBump = true` dentro del `if` del piso de prioridad;
  - `$policy = $this->resolveEscalationPath->execute(...)`;
  - `$steps` ya existe.
- Al final del closure asigna `$narrative = ['applied' => $applied, 'resolved' => $resolved, 'review_by_resolver' => $reviewByResolver, 'review_by_outcome' => $reviewByOutcome, 'mapped_priority' => $mappedPriority, 'critical_bump' => $criticalBump, 'policy' => $policy, 'trace_steps_count' => count($steps)]`.
- Después: `$this->narrate($eval, $decision, $narrative); return $decision;`.

`private function narrate(AIEventEvaluation $eval, Decision $decision, array $narrative): void` emite, en orden:

| Condición | Llamada |
|---|---|
| `explain.guard_check === 'forced_review'` | `SystemLog::ok('decisions.outcome.forced_human_review', input: ['ai_evaluation_id' => $eval->id], calc: ['from_code' => LoggableCode::guard($explain['guard_from_code']), 'latest_media_result' => 'contradicts_event', 'prior_actionable_decision' => true], result: ['decision_id' => $decision->id, 'decision_code' => $decision->decision_code])` |
| `explain.floor_check === 'floored'` | `SystemLog::ok('decisions.outcome.floored', input: ['ai_evaluation_id' => …], calc: ['from_code' => LoggableCode::guard($explain['floor_from_code']), 'to_code' => DecisionOutcomeCode::Incident->value, 'floor_severity_codes' => ResolveDecisionOutcome::CRITICAL_SEVERITY_FLOOR_CODES], result: ['decision_id' => …])` |
| siempre | `SystemLog::ok('decisions.outcome.resolved', input: ['ai_evaluation_id' => $eval->id, 'ruleset_id' => $decision->ruleset_id, 'classification' => $eval->classification->value], calc: [...$explain sin guard_from_code/floor_from_code, 'review_by_resolver' => …, 'review_by_outcome' => …], result: ['decision_id' => $decision->id, 'decision_code' => LoggableCode::guard($decision->decision_code), 'source_type' => $resolved['sourceType']->value, 'rule_id' => $resolved['sourceRule']?->id, 'rule_code' => LoggableCode::guard($resolved['sourceRule']?->code), 'requires_human_review' => $decision->requires_human_review, 'trace_steps_count' => $narrative['trace_steps_count']])` |
| siempre | `SystemLog::ok('decisions.priority.resolved', input: ['decision_id' => …], calc: ['ai_priority_level' => $eval->priority_level->value, 'requires_human_review' => $decision->requires_human_review, 'mapped' => $narrative['mapped_priority']->value, 'critical_bump' => $narrative['critical_bump']], result: ['priority_level' => $decision->priority_level->value])` |
| escalación | ver abajo |

La línea de escalación usa `$rulePolicyId = $resolved['sourceRule']?->escalation_policy_id`:
- `$policy !== null` → `SystemLog::ok('decisions.escalation_policy.resolved', input: ['decision_id' => …], calc: ['policy_source' => $rulePolicyId === $policy->id ? 'source_rule' : 'team_default_for_escalate', 'rule_policy_id' => $rulePolicyId], result: ['escalation_policy_id' => $policy->id])`;
- `$policy === null` y `$decision->decision_code === 'ESCALATE'` → `SystemLog::degraded(…, reason: 'no_active_team_policy', …)`. Un ESCALATE sin política no escala a nadie;
- `$policy === null` y `$rulePolicyId !== null` → `SystemLog::degraded(…, reason: 'rule_policy_unavailable', calc: ['rule_policy_id' => $rulePolicyId])`;
- en otro caso → `SystemLog::skipped(…, reason: 'not_required', debug: true)`.

**Sin texto:** nunca `$resolved['reason']` / `decision_reason` (incluye el `name` de la regla).

**Sin `decision_trace_id`:** `GenerateDecisionTrace` crea una fila por paso, así que no hay un id único. La correlación con la DB va por `decision_id` (las trazas cuelgan de él) y `trace_steps_count`.

- [ ] **Step 1: Write the failing tests** — un `assertSystemLogged` por fuente, reutilizando los tests existentes:
  - `EvaluateDecisionRulesTest`:
    - `test_panic_event_high_risk…` → `source === 'hard_safety'`, `rule_code === 'safety-high-risk'`, `source_type === 'rule'`;
    - `test_tenant_rule_overrides_global_rule` → `tenant_rule`;
    - `test_low_confidence_forces_human_review` → `ai_mapping`, con `review_by_confidence === true` y recomputado `calc.confidence < calc.human_review_threshold`; `decisions.priority.resolved` con `mapped === 'high'`;
    - `test_fallback_outcome_when_no_rules_match` → `ai_mapping` con `ai_outcome_code === 'LOG_ONLY'`;
    - test nuevo que borra el outcome `ALERT` (`DecisionOutcome::where('code', 'ALERT')->delete()`) con `RealEvent`, riesgo 0.5 y confianza 0.95 → `log_only_fallback` con `ai_outcome_missing === true`;
    - `test_escalation_triggered_dispatches_event` → `decisions.escalation_policy.resolved` con `policy_source === 'source_rule'`;
    - test nuevo con una regla ESCALATE sin política y sin `EscalationPolicy` del team → `no_active_team_policy`;
    - `test_decision_trace_records_steps` → `trace_steps_count` igual a `DecisionTrace::where('decision_id', …)->count()`.
  - `CriticalSeverityFloorTest` (con `panicEvaluation()`/`decide()`):
    - `test_critical_panic_classified_false_positive_with_high_confidence_becomes_incident` → `decisions.outcome.floored` con `from_code === 'IGNORE'` y `to_code === 'INCIDENT'`, y `decisions.outcome.resolved` con `floor_check === 'floored'`;
    - `test_explicit_tenant_rule_routing_critical_panic_to_human_review_is_respected` → `floor_check === 'rule_chose_review'` y sin `floored`;
    - `test_medium_severity_false_positive_is_still_ignored` → `not_critical`;
    - un caso de prioridad `Low` crítico → `decisions.priority.resolved.critical_bump === true`.
  - `MediaContradictionGuardTest` (con `makeReevaluatedEvent`/`attachAssessment`):
    - `test_contradicting_media_with_prior_actionable_decision_requires_human_review` → `decisions.outcome.forced_human_review` con `from_code` y `guard_check === 'forced_review'`;
    - `test_without_prior_actionable_decision_the_downgrade_stands` → `no_prior_actionable_decision`;
    - `test_confirming_media_does_not_trigger_the_guard` → `media_not_contradicting` con `latest_media_result === 'confirms_event'`.
  - `FalseAlarmDecisionMatrixTest::test_resolved_and_parked_at_base_degrades_to_human_review` → `source === 'tenant_rule'` (o el real que produzca el fixture: afírmalo leyendo `$decision`) y `review_by_outcome === true`.
  - Rollback: en `EvaluateDecisionRulesTest`, bindea con `$this->app->instance(GenerateDecisionTrace::class, new class extends GenerateDecisionTrace { public function execute(Decision $decision, array $steps): void { throw new RuntimeException('boom'); } })`. `execute` lanza; afirma `Decision::count() === 0` y `assertSystemNotLogged('decisions.outcome.resolved')`, pero `assertSystemLogged('decisions.rules.evaluated')`: es un cálculo, sigue siendo cierto.
  - Texto: en el test de `hard_safety` da a la regla `'name' => 'Nombre libre xyz'` → el JSON de las entradas no contiene `'Nombre libre xyz'` ni `'Regla de seguridad obligatoria'`.
  - `assertNoSensitiveDataLogged()` en cada archivo.

- [ ] **Step 2: Run to verify they fail** — `APP_KEY=… php artisan test --compact tests/Feature/Domains/Decisions` → FAIL.
- [ ] **Step 3: Implement** — `explain` en `ResolveDecisionOutcome`, y la captura y `narrate` en `EvaluateDecisionRules`.
- [ ] **Step 4: Catalog + gate completo**
  - Filas: `decisions.outcome.resolved` (con la tabla de `source` y de `guard_check`/`floor_check`), `decisions.outcome.floored`, `decisions.outcome.forced_human_review`, `decisions.priority.resolved` y `decisions.escalation_policy.resolved`.
  - Nota: "sin `decision_trace_id`: las trazas cuelgan de `decision_id`".
  - Run: `vendor/bin/pint --dirty --format agent`, la suite completa (`APP_KEY=… php artisan test --compact`) y `npm run types:check && npm run lint:check && npm run format:check` → todo verde.
- [ ] **Step 5: Commit** — `feat: log narrativo del desenlace, piso de seguridad, prioridad y escalación de decisiones`

---

## Cierre de la fase

- [ ] Revisión de aislamiento con el subagente `tenant-isolation-reviewer`. Comprueba que ninguna línea lleve ids de otro tenant. Presta atención a `ApplyTenantRuleSet`, que carga reglas globales y del team: `decisions.rules.evaluated` solo lista reglas con `team_id` null o del team del evento, porque la query ya lo filtra.
- [ ] `git push -u origin feat/logging-ia-decisiones`, PR con el resumen de códigos, `gh pr checks --watch`, merge con la autorización amplia vigente y `git pull --ff-only` en el checkout principal.
- [ ] Siguiente: plan de la fase 4 (Incidentes + Automatización + Notificaciones).
