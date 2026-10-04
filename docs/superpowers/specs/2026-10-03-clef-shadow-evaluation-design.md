# Clef en sombra + base etiquetada — diseño

Fecha: 2026-10-03 · Rama: `feat/clef-shadow-evaluation` · Estado: propuesta (fase 1)

## 1. Objetivo y criterio de éxito

Medir, con datos propios y comparables, si los modelos de decisión de Cloudflare
(**Clef** 27B y **Clef-flash** 9B, Workers AI) pueden asumir la parte de *decisión*
de la IA de SAM que hoy hace GPT-5.4, para bajar costo y latencia y tener una
confianza calibrada, **sin perder eventos reales**.

La fase 1 no cambia nada para operadores ni clientes: Clef corre en sombra y sus
resultados sólo se guardan y se reportan.

Éxito de la fase 1 = existe un reporte reproducible que compara GPT, Clef y
Clef-flash contra (a) GPT y (b) una base de verdad etiquetada por humanos.

### Hechos de partida (dev, 2026-10-03)

- GPT-5.4: ~2,800 tokens de entrada y ~400 de salida por evaluación, ~$0.011 y ~7 s de latencia media.
- Clef: $0.24/M de tokens de entrada (doc oficial), ~0.2 s. Clef-flash: $0.09/M, ~0.04 s.
- Hay **0 veredictos de operador** en la base, y el 98 % de las evaluaciones de dev son `real_event` o `unclear` (replays de pánicos). Por eso se incluye el etiquetado (§6).

### La sombra es temporal (decisión 2026-10-03)

No queremos dos modelos corriendo a la par de forma permanente. La sombra es una
medición con fecha de fin, no una arquitectura:

- Corre hasta tener **≥ 300 veredictos** cruzados con Clef, o **4 semanas** desde que se active, lo que pase primero.
- `ai.clef.shadow_until` (fecha en config/env): pasada esa fecha, el listener deja de despachar (`skipped`, `reason: shadow_expired`). Si se olvida apagarla, igual se detiene sola.
- Al cerrar la medición se toma una de dos decisiones, y en ambas la sombra desaparece:
  - **Clef pasa:** fases 2 y 3, y Clef reemplaza a GPT en la decisión. El listener de sombra se borra.
  - **Clef no pasa:** se borran el listener, el job y el cliente, y la tabla queda como registro histórico (o se elimina con una migración).

## 2. Alcance

**Dentro:**
1. Evaluación en sombra de cada evaluación de IA oficial (texto e imágenes) con Clef y Clef-flash.
2. Backfill sobre el historial usando el contexto ya guardado en `ai_inference_logs.input_snapshot_json`.
3. Comando de etiquetado humano a ciegas para construir la base de verdad.
4. Reporte de comparación por consola.

**Fuera (fases posteriores, cada una con su propio spec):**
- Fase 2: `MediaAssessmentAgent` respaldado por Clef.
- Fase 3: `EventEvaluationAgent` "Clef primero" (Clef decide; GPT sólo redacta lo que escala).
- Pantalla de métricas en el super-admin.

## 3. Arquitectura

```
AIEvaluationCompleted ──► DispatchClefShadowEvaluation (listener)
                              │  gate: ai.clef.enabled, sample_rate, mode ∈ {ai_text, hybrid}
                              ▼
                     ShadowEvaluateWithClefJob(teamId, evaluationId)   cola: default (supervisor low)
                              │  TenantContext::set + valida team
                              ▼
                     ClefEventDecider ──► ClefClient ──► Workers AI /ai/run/@cf/cloudflare/{model}
                              │  (una llamada por modelo configurado)
                              ▼
                     ai_shadow_evaluations (una fila por evaluación × modelo)

ai:clef-backfill ──► mismo job, desde ai_inference_logs
ai:label-events  ──► RecordOperatorVerdict (base de verdad)
ai:clef-report   ──► ClefShadowComparisonQuery ──► tabla en consola
```

Principio rector: **la evaluación oficial no depende de Clef en nada.** El job corre
en una cola de baja prioridad y, si falla, sólo marca su propia fila.

### Ubicación del código

| Pieza | Ruta |
|---|---|
| Cliente HTTP, schema de preguntas, decider | `app/Infrastructure/AI/Clef/` (subdirectorio nuevo, aprobado 2026-10-03) |
| Modelo, job, listener, query, DTO | `app/Domains/AI/{Models,Jobs,Listeners,Queries,Data}` |
| Comandos | `app/Domains/AI/Commands/` (mismo patrón que `app/Domains/Assets/Commands/`) |
| Config | `config/services.php` (`cloudflare`), `config/ai.php` (`clef`) |

## 4. Componentes

### 4.1 Config

```php
// config/services.php
'cloudflare' => [
    'account_id' => env('CLOUDFLARE_ACCOUNT_ID'),
    'auth_token' => env('CLOUDFLARE_AUTH_TOKEN'),
],

// config/ai.php
'clef' => [
    'enabled' => env('AI_CLEF_SHADOW_ENABLED', false),
    'models' => ['clef', 'clef-flash'],
    'sample_rate' => env('AI_CLEF_SHADOW_SAMPLE_RATE', 1.0),   // 0..1
    'send_images' => env('AI_CLEF_SHADOW_SEND_IMAGES', true),  // cliente informado (2026-10-03)
    'shadow_until' => env('AI_CLEF_SHADOW_UNTIL'),               // fecha ISO; vacía = sin despachar
    'max_images' => 4,
    'timeout_seconds' => 15,
    'pricing_per_million_input' => ['clef' => 0.24, 'clef-flash' => 0.09],
],
```

Si faltan credenciales con `enabled=true`, el listener registra `skipped` (`reason: missing_credentials`) y no despacha nada.

### 4.2 `ClefClient`

- `run(string $model, array|string $state, array $questions, array $imagesBase64 = []): ClefResponse`.
- `Http::withToken()->timeout()->post(...)`. Sin reintentos internos: los reintentos los hace el job.
- Los errores salen como `ClefRequestFailedException` con status y clase. El cuerpo crudo nunca va a logs ni a la DB (`SafeErrorMessage`).
- Valida la respuesta: deben venir todas las preguntas pedidas en `answers`. Si falta alguna, se lanza excepción (`malformed_response`).

### 4.3 `ClefQuestionSchema`

Construye las preguntas a partir de los enums, para no duplicar fuentes de verdad.

| id | tipo | origen |
|---|---|---|
| `classification` | `choice` | casos de `EventClassification` evaluables por el modelo (`real_event`, `false_positive`, `noise`, `duplicate`, `unclear`), con criterios en español |
| `severity` | `score` | `["Ninguna", "Baja", "Media", "Alta", "Crítica"]` → se mapea a `risk_score` 0..1 |
| `needs_human_now` | `noul` | "¿Un operador debe revisarlo de inmediato?" |
| `media_result` | `choice` | sólo con imágenes: casos de `MediaAssessmentResult` |
| `driver_visible`, `passenger_detected`, `visible_threat`, `cabin_appears_normal`, `vehicle_moving` | `choice` `si/no/no_visible` | sólo con imágenes; refleja los nullable de `MediaInspectorAgent` |
| `persons_visible` | `choice` `0/1/2/3+` | sólo con imágenes |

Las instrucciones se derivan del prompt de `EventClassifierAgent` (misma semántica, versión condensada). La versión del schema se guarda en cada fila (`schema_version`) para que los reportes no mezclen versiones.

### 4.4 `ClefEventDecider`

- Entrada: `AIInputContext` (el mismo que vio GPT; ya trae los datos personales redactados) y, si `send_images`, hasta `max_images` imágenes del evento. Las imágenes se leen del disco `rustfs` y se validan con la misma lógica de `SdkMediaAssessmentAgent::buildAttachments` (se extrae a un helper compartido, sin cambiar su comportamiento).
- Salida: `ClefDecision` (DTO) con la clasificación elegida, las probabilidades por opción, `risk_score`, `needs_human_now`, las respuestas de media, tokens, latencia y costo estimado.

### 4.5 Tabla `ai_shadow_evaluations`

| columna | tipo |
|---|---|
| `id` | bigint |
| `team_id` | fk teams, index |
| `ai_event_evaluation_id` | fk, cascade |
| `normalized_event_id` | fk, index |
| `model` | string (`clef`, `clef-flash`) |
| `schema_version` | smallint |
| `source` | string (`live`, `backfill`) |
| `status` | string (`success`, `failed`) |
| `classification` | string nullable |
| `classification_probabilities_json` | jsonb nullable |
| `risk_score` | decimal(3,2) nullable |
| `needs_human_probability` | decimal(4,3) nullable |
| `media_answers_json` | jsonb nullable |
| `images_sent` | smallint |
| `input_tokens`, `latency_ms` | int nullable |
| `cost_estimate` | numeric(8,5) nullable |
| `error_code` | string nullable (clase o `malformed_response`, nunca el mensaje crudo) |
| `retryable` | bool: fallo transitorio (429, 5xx, timeout) que el siguiente job o backfill reintenta |
| timestamps | |

Índice único `(ai_event_evaluation_id, model, schema_version)`: idempotencia del job y del backfill. Modelo `AIShadowEvaluation` con `BelongsToTenant`.

### 4.6 Listener y job

- `DispatchClefShadowEvaluation` escucha `AIEvaluationCompleted`. Despacha sólo si `enabled`, hoy ≤ `shadow_until`, hay credenciales, el `evaluation_mode` es `ai_text` o `hybrid` (las rutas `rules_only` no tienen contra qué comparar) y el muestreo lo permite. Cada rama registra su `skipped` con razón.
- `ShadowEvaluateWithClefJob(int $teamId, int $evaluationId, string $source = 'live')`:
  - entra por id sin scope, valida `team_id` y hace `TenantContext::set`;
  - cola `default` (supervisor `low`): nunca compite con `ai-evaluation`;
  - `tries=3`, backoff `[30, 120, 600]`;
  - por cada modelo sin fila previa (idempotente), llama y persiste. Si un modelo falla, el otro se guarda igual;
  - en el intento final persiste `status=failed` con `error_code`.
- **Sin cobro ni cuota:** no llama a `RecordUsageEvent` ni toca `TenantAIQuota`. Es costo de plataforma.

### 4.7 `ai:clef-backfill`

`php artisan ai:clef-backfill {--team=} {--since=} {--limit=500} {--sync}`

- Recorre `ai_event_evaluations` en modo `ai_text`/`hybrid` (por defecto, las que tienen veredicto de operador primero) y despacha el job con `source=backfill`. El contexto se reconstruye desde `ai_inference_logs.input_snapshot_json`, que es exactamente lo que vio GPT.
- Fan-out por tenant con `TenantContext::withoutTenant` y `TenantContext::for` por fila.
- Muestra antes el costo estimado (tokens del snapshot × precio) y pide confirmación (`--force` para saltarla).

## 5. Reporte: `ai:clef-report`

`php artisan ai:clef-report {--team=} {--days=30} {--model=}`

`ClefShadowComparisonQuery` cruza `ai_event_evaluations` (GPT) con `ai_shadow_evaluations` (Clef) por `schema_version` vigente.

| Métrica | Definición |
|---|---|
| Concordancia con GPT | % de eventos donde Clef y GPT eligen la misma clasificación, más una matriz de confusión |
| **Recall de reales** (métrica de seguridad) | De los veredictos `confirmed`, % que el modelo clasificó como accionable (`real_event` o `unclear`) |
| Descarte correcto (métrica de ahorro) | De los veredictos `false_positive`, % que el modelo descartó (`false_positive`, `noise` o `duplicate`) |
| Acierto estricto | `OperatorVerdict::agreesWith()` sobre la clasificación del modelo |
| Calibración | Brier score de P(`real_event`) frente al veredicto (sólo Clef; GPT sólo tiene confianza autodeclarada y se reporta aparte) |
| Costo y latencia | Suma y promedio de costo; latencia p50 y p95 |

Desglose por tipo de evento y por modelo (`gpt`, `clef`, `clef-flash`). Cada métrica muestra su `n`, y con `n < 30` se marca "muestra insuficiente".

**Criterios para pasar a la fase 2 o 3** (decisión humana con el reporte en mano, con ≥ 300 veredictos):
1. Recall de reales de Clef ≥ al de GPT. No negociable.
2. Acierto estricto de Clef ≥ al de GPT − 2 puntos.
3. Para la fase 2, además: concordancia del `media_result` con el `MediaInspectorAgent` ≥ 85 %, revisando a mano las discrepancias.

## 6. Base de verdad: `ai:label-events`

`php artisan ai:label-events {--team=} {--user=} {--limit=100} {--since=}`

- **Muestra estratificada:** por la clasificación de GPT y por el tipo de evento, para no etiquetar 100 pánicos. Sólo eventos sin veredicto. Se puede retomar: si se corta, el siguiente run continúa con los pendientes.
- **A ciegas:** no muestra la clasificación de GPT ni la de Clef, para no sesgar al etiquetador.
- **Muestra:** tipo y categoría de evento, hora local, activo, chofer (sólo su id interno), ubicación, velocidad y telemetría del contexto, eventos recientes del activo, y URLs temporales (15 min) de sus imágenes en RustFS.
- **Opciones:** `[r] real` → `confirmed`, `[f] falso positivo` → `false_positive`, `[s] saltar`, `[q] salir`.
- **Graba** con `RecordOperatorVerdict` (`userId` = `--user`, obligatorio y miembro del team; nota `etiquetado:baseline`). Así queda en la misma columna que alimenta las reevaluaciones y las métricas, con su entrada de auditoría.
- **Recomendación de uso:** etiquetar sobre el team con integración real (no sobre el de replays), después de correr el backfill.

## 7. Errores y degradación

| Falla | Comportamiento |
|---|---|
| Credenciales ausentes | listener `skipped` (`missing_credentials`), nada se despacha |
| Timeout o 5xx de Cloudflare | reintento del job; en el intento final, fila `failed` + `SystemLog::failed` |
| 4xx (schema inválido, imagen rechazada) | sin reintento; fila `failed` con `error_code` |
| Respuesta sin todas las respuestas | `malformed_response`, fila `failed` |
| Imagen inválida o faltante | se omite esa imagen y se registra; si no queda ninguna, se evalúa sólo texto (`images_sent=0`) |
| Evaluación oficial | **nunca se ve afectada** |

## 8. Logging (códigos nuevos en `docs/SAM/logging.md`)

- `ai.clef_shadow.dispatched` · `ai.clef_shadow.skipped` (`disabled`, `missing_credentials`, `shadow_expired`, `rules_only_mode`, `not_sampled`, `already_evaluated`)
- `ai.clef_shadow.completed`: `input` con ids y modelo; `calc` con tokens, precio por millón, costo, latencia, `images_sent` y si coincide con GPT; `result` con la clasificación y su probabilidad.
- `ai.clef_shadow.image_skipped` · `ai.clef_shadow.failed` (con `error`)
- `ai.clef_backfill.planned` (calc: filas, tokens y costo estimado) · `ai.label.recorded`

Nunca se registran el estado enviado, las imágenes, URLs firmadas ni el token.

## 9. Tests (`tests/Feature/Domains/AI/Clef/`)

- `ClefClientTest`: request correcto (headers, modelo, preguntas, imágenes), respuesta válida, errores 4xx/5xx/timeout y respuesta incompleta, con `Http::fake`.
- `ClefQuestionSchemaTest`: cada caso del enum aparece como opción; las preguntas de media sólo aparecen con imágenes.
- `ShadowEvaluateWithClefJobTest`:
  - happy path con los dos modelos;
  - un modelo falla y el otro persiste;
  - idempotencia;
  - un team que no coincide aborta;
  - no se registran `UsageEvent` ni se consume la cuota;
  - la evaluación oficial queda intacta.
- `DispatchClefShadowEvaluationTest`: cada gate.
- **Fuga de tenant** (`AssertsTenantIsolation`): el job de un team no lee ni escribe filas de otro; el reporte y el etiquetado filtrados por `--team` no cruzan datos.
- `ClefBackfillCommandTest`, `LabelEventsCommandTest` (estratificación, a ciegas, se retoma, graba con `RecordOperatorVerdict`), `ClefReportCommandTest` (métricas con datos controlados, incluida la marca de `n` insuficiente).
- Logging: `assertSystemLogged` en cada código y `assertNoSensitiveDataLogged`.

## 10. Riesgos y decisiones abiertas

- **Subprocesador nuevo:** las imágenes de cabina van a Cloudflare. El cliente actual está informado y de acuerdo (2026-10-03), así que `send_images=true` por defecto. Cada cliente nuevo debe quedar informado en su contrato.
- **Modelo recién lanzado** (2026-10-01): el rendimiento en español y con telemetría de flotas es desconocido. Justo eso es lo que mide esta fase.
- **Precios:** los de la doc oficial se fijan en config; si cambian, el reporte usa los de config al momento del cálculo.
- **Volumen de veredictos:** el criterio de paso exige ≥ 300. El etiquetado acelera llegar ahí; los veredictos de producción lo completan.
