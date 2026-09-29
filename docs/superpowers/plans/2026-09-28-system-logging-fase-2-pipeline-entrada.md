# Logging narrativo — Fase 2: Pipeline de entrada — Plan de implementación

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Que cada decisión del tramo de entrada del pipeline deje su línea narrativa. El tramo cubre webhook → firma → ingesta → dedupe → normalización (mapeo, activo, conductor, severidad, gate de vigilancia) → contexto (ubicación, snapshot, pedido de media) → job de media diferida. Cada línea dice qué entró, qué rama se tomó y por qué, con qué umbrales y qué salió.

**Architecture:** Sobre la fundación de la fase 1 (PR #150): `App\Support\SystemLog` (ok/skipped/degraded/failed/measure, esquema fijo, redacción global, guard central), `Tests\Concerns\AssertsSystemLog` y el catálogo `docs/SAM/logging.md`, que `LoggingConventionsTest` exige que contenga cada código literal. No hay componentes nuevos: cada tarea añade llamadas `SystemLog` en los puntos de decisión de un área y actualiza el catálogo. Las ramas que hoy son un `return` silencioso pasan a registrar su `reason`. Los cálculos (tolerancia de firma, staleness de GPS, ventanas de media) registran cada término en `calc`.

**Tech Stack:** Laravel 13 · PHP 8.5 · PHPUnit 13.

**Spec:** `docs/superpowers/specs/2026-09-28-system-logging-design.md` (§3 esquema, §5 "Fase 2").

## Global Constraints

- Código `dominio.etapa.resultado` (regex `/^[a-z0-9_]+(\.[a-z0-9_]+){2,}$/`), en inglés snake_case.
- `reason` obligatorio en snake_case cuando `outcome` ≠ `ok`.
- Nivel: ok/skipped → info, degraded → warning, failed → error.
- Nunca en un log: teléfonos, emails, nombres, tokens, secretos, firmas, payloads crudos, texto libre, `getMessage()`. Las excepciones van como `error: $e`.
- Nunca se registran coordenadas: la ubicación se describe por su fuente (`location_source`) y su antigüedad, no por lat/lng.
- Los ids y códigos de catálogo (`asset_id`, `event_type_code`, `severity_code`, `external_event_type`) sí se registran. `external_id` de Samsara no es PII.
- Solo el nombre del campo del payload del que salió un dato (`asset_path_used = 'vehicle.id'`), nunca el valor del payload salvo ids.
- Cada código nuevo tiene: (a) su fila en `docs/SAM/logging.md` (código | outcome | reason posibles | campos clave); (b) al menos un test que recorre la rama real con DB real y la afirma con `assertSystemLogged(code, fn (array $c) => …)`, comprobando `reason` y los campos de `calc` clave. Cada archivo de test nuevo o tocado llama `assertNoSensitiveDataLogged()` en al menos un test.
- No cambiar comportamiento: solo se añaden líneas. Donde haga falta exponer un dato interno (la fuente de `occurred_at`, la regla descartada, la variante de secreto), la refactorización mínima conserva la firma pública o la extiende sin romper a sus llamadores. Los tests existentes siguen verdes sin tocarlos, salvo para añadir el trait `AssertsSystemLog` y aserciones nuevas.
- Rutas calientes: las líneas por evento van en `info`; no hay rutas de 5 s en esta fase.
- Sin directorios nuevos en `app/`, sin cambios en `composer.json`/`package.json`. Commits `type: subject en minúsculas` sin trailers. PHPUnit, sin Pest. No mockear la DB. Nunca `git stash`.
- El worktree `.claude/worktrees/logging-fase-2` no tiene `.env`, así que los tests se corren con `APP_KEY=base64:$(openssl rand -base64 32) php artisan test --compact …`. Los warnings de dotenv son esperados; 0 fallos = verde.

## Review Focus

1. **Firma rechazada:** la línea dice la causa exacta (`empty_signature`, `stale_timestamp`, `invalid_timestamp`, `hmac_mismatch`) y el desfase medido contra la tolerancia. Nunca registra la firma, el secreto ni el cuerpo. Lo fija el Task 1.
2. **Evento descartado por activo no vigilado:** queda una línea `skipped` con `asset_id`, el estado de vigilancia y `is_emergency=false`. La emergencia de un activo no vigilado deja otra línea distinta. Hoy ambas son silenciosas. Lo fija el Task 3.
3. **Activo de otro tenant:** cuando el id del payload apunta a un activo o conductor de otro tenant, la línea dice `cross_tenant_rejected=true` sin registrar el `asset_id` ajeno. Lo fija el Task 3.
4. **Ubicación:** la línea de contexto dice qué fuente se usó (`event_payload` / `live_fetch` / `asset_latest_location` / `unknown`) y por qué no se pidió una ubicación en vivo, con `staleness_seconds` contra el umbral, sin coordenadas. Lo fija el Task 4.
5. **Job de media diferida:** cada cierre registra un `reason` estable (no la frase inglesa) y cada re-encolado el motivo y el retraso. Lo fija el Task 5.

---

## Mapa de archivos

| Archivo | Tarea |
|---|---|
| `app/Domains/Integrations/Adapters/SamsaraAdapter.php` (`validateWebhookSignature`, `timestampWithinTolerance`) | 1 |
| `app/Domains/Integrations/Jobs/ProcessWebhookEventJob.php` | 1 |
| `app/Domains/Ingestion/Services/RawEventIngestionService.php`, `Actions/StoreRawEvent.php`, `Actions/DetectDuplicateEvent.php`, `Actions/IngestSafetyEvent.php`, `Jobs/ProcessRawEventJob.php`, `Jobs/PollSafetyEventsJob.php` | 2 |
| `app/Domains/Normalization/Actions/NormalizeRawEvent.php`, `Actions/MapExternalEventType.php`, `Actions/ResolveEventSeverity.php`, `Jobs/NormalizeEventJob.php` | 3 |
| `app/Domains/Context/Actions/FetchLiveLocationForEvent.php`, `Actions/BuildEventContext.php`, `Actions/RequestDeferredEventMedia.php`, `Listeners/RequestPanicMediaOnContextBuilt.php`, `Jobs/EnrichContextJob.php` | 4 |
| `app/Domains/Context/Jobs/FetchDeferredEventMediaJob.php` | 5 |
| `docs/SAM/logging.md` | todas (su sección) |
| Tests: se añaden métodos a los tests existentes del área (listados en cada tarea), que ya traen los fixtures | todas |

---

### Task 1: Webhook y firma

**Files:**
- Modify: `app/Domains/Integrations/Adapters/SamsaraAdapter.php` (`validateWebhookSignature`, ~línea 742; `timestampWithinTolerance`, ~795)
- Modify: `app/Domains/Integrations/Jobs/ProcessWebhookEventJob.php`
- Modify: `docs/SAM/logging.md` (sección Integrations / webhooks)
- Test: `tests/Feature/Domains/Integrations/WebhookProcessingTest.php`, `tests/Feature/Domains/Integrations/SamsaraAdapterTest.php`, `tests/Feature/Domains/Integrations/DeletedTenantIngestionTest.php`

**Interfaces:**
- Consumes: `SystemLog`, `AssertsSystemLog`.
- Produces: los códigos de la tabla. `validateWebhookSignature` mantiene su firma y su retorno `bool`.

| Rama | Llamada |
|---|---|
| `$provided === ''` | `SystemLog::degraded('webhook.signature.rejected', reason: 'empty_signature', input: ['scheme' => $scheme])` |
| timestamp presente, no numérico | `SystemLog::degraded('webhook.signature.rejected', reason: 'invalid_timestamp', input: ['scheme' => 'timestamped'])` |
| timestamp fuera de tolerancia | `SystemLog::degraded('webhook.signature.rejected', reason: 'stale_timestamp', input: ['scheme' => 'timestamped'], calc: ['skew_seconds' => $skew, 'tolerance_seconds' => $tolerance, 'reference' => $receivedAt !== null ? 'received_at' : 'now', 'timestamp_unit' => $unit])` |
| ningún candidato coincide | `SystemLog::degraded('webhook.signature.rejected', reason: 'hmac_mismatch', input: ['scheme' => $scheme], calc: ['secret_variants_tried' => count($candidates)])` |
| coincide | `SystemLog::ok('webhook.signature.verified', input: ['scheme' => $scheme], calc: ['secret_variant' => $variant, 'secret_variants_tried' => $index + 1, 'skew_seconds' => $skew, 'tolerance_seconds' => $tolerance])` |

Definiciones:
- `$scheme` es `'timestamped'` si hay timestamp y `'plain'` si no.
- `$variant` es `'base64_decoded'` si la clave que coincidió es la decodificada y `'raw'` si es el secreto tal cual.
- `$unit` es `'seconds'` o `'milliseconds'`.
- `$skew` es `abs($reference - $seconds)`, o null en `plain`.
- `$tolerance` es el entero de config; si es 0 (sin chequeo), `calc.tolerance_seconds = 0` y `skew_seconds` null.

Para tener `$skew` y `$unit`, refactoriza `timestampWithinTolerance` en un privado que devuelva `array{within: bool, valid: bool, skew_seconds: ?int, tolerance_seconds: int, unit: ?string}`. Cambia `candidateSecrets` para que el foreach sepa qué variante coincidió: por ejemplo, que devuelva `list<array{key: string, variant: string}>`.

**Seguridad:** nada del payload, del secreto, de la firma ni del timestamp crudo entra al log. Solo derivados numéricos y códigos.

`ProcessWebhookEventJob::handle`:

| Rama | Llamada |
|---|---|
| tenant dado de baja | `SystemLog::skipped('webhook.event.discarded', reason: 'tenant_deleted', input: ['webhook_event_id' => $this->webhookEvent->id])` |
| antes de validar | calcula `$signatureMode = $this->webhookEvent->raw_payload === null ? 'legacy_body' : 'raw_header'` |
| firma inválida | `SystemLog::skipped('webhook.event.rejected', reason: 'invalid_signature', input: ['webhook_event_id' => …, 'signature_mode' => $signatureMode, 'event_type' => $this->webhookEvent->event_type])` |
| ingerido | `SystemLog::ok('webhook.event.ingested', input: ['webhook_event_id' => …, 'event_type' => …, 'signature_mode' => $signatureMode, 'provider_code' => $providerCode], result: ['provider_code_fallback' => $integration->provider?->code === null])` |

En la rama "ingerido", `$providerCode = $integration->provider->code ?? 'unknown'`: la misma expresión que se pasa a `ingest`, extraída a una variable.

`event_type` es el tipo del webhook de Samsara (catálogo del proveedor), no texto libre.

- [ ] **Step 1: Write the failing tests**

En `SamsaraAdapterTest` añade el trait `AssertsSystemLog` y un test por rama de la primera tabla. Invoca `app(SamsaraAdapter::class)->validateWebhookSignature($body, $signature, $secret, $timestamp, $receivedAt)` construyendo firmas válidas con `hash_hmac('sha256', 'v1:'.$ts.':'.$body, base64_decode($secret))`, como en los tests de firma existentes del archivo. Casos:

- firma vacía → `empty_signature`;
- timestamp `'abc'` → `invalid_timestamp`;
- timestamp 10 min atrás con tolerancia 300 → `stale_timestamp`, con `calc.skew_seconds === 600` (congela el reloj con `$this->travelTo(...)`, o pasa `$receivedAt` explícito) y `calc.tolerance_seconds === 300`;
- HMAC de otro secreto → `hmac_mismatch`, con `calc.secret_variants_tried === 2` si el secreto es base64 válido;
- firma válida con el secreto base64 → `webhook.signature.verified`, con `calc.secret_variant === 'base64_decoded'`;
- firma válida con el secreto crudo no-base64 → `secret_variant === 'raw'`;
- sin timestamp (plain) → `scheme === 'plain'`, `skew_seconds` ausente/null.

En cada test, `assertNoSensitiveDataLogged()`. Además afirma que el JSON de las entradas no contiene la firma ni el secreto: `assertStringNotContainsString($signature, json_encode($this->systemLogEntries()))`.

En `WebhookProcessingTest` y `DeletedTenantIngestionTest`, con los fixtures existentes, añade:
- un webhook válido → `webhook.event.ingested` con `signature_mode === 'raw_header'`;
- un evento sin `raw_payload` (la rama legacy que ya ejercita algún test del archivo) → `signature_mode === 'legacy_body'`;
- firma inválida → `webhook.event.rejected` con `invalid_signature`;
- tenant borrado → `webhook.event.discarded` con `tenant_deleted`.

- [ ] **Step 2: Run to verify they fail**

Run: `APP_KEY=base64:$(openssl rand -base64 32) php artisan test --compact tests/Feature/Domains/Integrations`
Expected: FAIL (`No se registró [webhook.signature.rejected]`, etc.).

- [ ] **Step 3: Implement** las dos tablas.

- [ ] **Step 4: Catalog + run**

Añade a `docs/SAM/logging.md` las filas de `webhook.signature.verified`, `webhook.signature.rejected`, `webhook.event.discarded`, `webhook.event.rejected` y `webhook.event.ingested`.

Run: `APP_KEY=… php artisan test --compact tests/Feature/Domains/Integrations tests/Feature/Architecture/LoggingConventionsTest.php`
Expected: PASS.

- [ ] **Step 5: Commit** — `git commit -m "feat: log narrativo de recepción de webhooks y verificación de firma"`

---

### Task 2: Ingesta

**Files:**
- Modify: `app/Domains/Ingestion/Services/RawEventIngestionService.php`
- Modify: `app/Domains/Ingestion/Actions/StoreRawEvent.php`
- Modify: `app/Domains/Ingestion/Actions/DetectDuplicateEvent.php`
- Modify: `app/Domains/Ingestion/Actions/IngestSafetyEvent.php`
- Modify: `app/Domains/Ingestion/Jobs/ProcessRawEventJob.php`
- Modify: `app/Domains/Ingestion/Jobs/PollSafetyEventsJob.php`
- Modify: `docs/SAM/logging.md`
- Test: `tests/Feature/Domains/Ingestion/{RawEventIngestionServiceTest,StoreRawEventTest,ProcessRawEventJobTest,SafetyEventsPollingTest,PollSafetyEventsJobResilienceTest}.php`

| Sitio / rama | Llamada |
|---|---|
| `RawEventIngestionService::ingest`, proveedor no resuelto (`$providerId === null`) | `SystemLog::degraded('ingestion.provider.unresolved', reason: 'unknown_provider_code', input: ['provider_code' => $source, 'event_type' => $eventType])`. Sin proveedor el evento terminará `unmapped`. |
| `StoreRawEvent::store`, tras crear el RawEvent | `SystemLog::ok('ingestion.raw_event.stored', input: ['source_type' => $sourceType, 'provider_id' => $providerId, 'external_event_id' => $externalEventId, 'event_type_raw' => $rawEvent->event_type_raw], calc: ['dedup_key_strategy' => $strategy, 'occurred_at_source' => $occurredAtSource, 'occurred_at_parse_failed' => $parseFailed], result: ['raw_event_id' => $rawEvent->id, 'event_source_id' => $eventSource->id])` |
| `DetectDuplicateEvent::execute`, sin clave | `SystemLog::skipped('ingestion.dedup.skipped', reason: 'no_dedup_key', input: ['raw_event_id' => $rawEvent->id])` |
| clave existente expirada (se borra) | `SystemLog::ok('ingestion.dedup.key_expired', input: ['raw_event_id' => …], result: ['expired_key_raw_event_id' => $existingKey->raw_event_id])` |
| duplicado por clave existente | `SystemLog::skipped('ingestion.duplicate.detected', reason: 'existing_key', input: ['raw_event_id' => …, 'dedup_source' => $dedupSource], result: ['first_raw_event_id' => $existingKey->raw_event_id])` |
| duplicado por carrera (`insertOrIgnore` = 0) | `SystemLog::skipped('ingestion.duplicate.detected', reason: 'lost_insert_race', input: ['raw_event_id' => …, 'dedup_source' => $dedupSource])` |
| clave registrada (primera vez) | `SystemLog::ok('ingestion.dedup.key_registered', input: ['raw_event_id' => …, 'dedup_source' => $dedupSource], calc: ['ttl_hours' => 24])` |
| `ProcessRawEventJob::handle`, no duplicado | `SystemLog::ok('ingestion.raw_event.processed', input: ['raw_event_id' => $rawEvent->id])` |
| `IngestSafetyEvent::execute`, duplicado conocido | `SystemLog::skipped('ingestion.media.inline_skipped', reason: 'known_duplicate', input: ['raw_event_id' => $rawEvent->id, 'event_state' => $eventState])` |
| `downloadInlineMedia`, al final (siempre que no se saltó) | `SystemLog::ok('ingestion.media.inline_collected', input: ['raw_event_id' => $rawEvent->id], calc: ['urls_found' => $found, 'downloaded' => $downloaded, 'failed' => $failed])`, con `storeMediaDownload` devolviendo `bool` para contar |
| `recordUsage`, meter ausente | `SystemLog::degraded('ingestion.usage.not_metered', reason: 'meter_missing', input: ['meter_code' => self::USAGE_METER_CODE, 'raw_event_id' => $rawEvent->id])`. Sin meter es un hueco de facturación, por eso `degraded`. |
| `recordUsage`, registrado | `SystemLog::ok('ingestion.usage.recorded', input: ['meter_code' => self::USAGE_METER_CODE, 'raw_event_id' => $rawEvent->id, 'event_state' => $eventState])` |
| `PollSafetyEventsJob::handle`, cursor/start_time ausente → restart | `SystemLog::ok('ingestion.poll.cursor_restarted', input: ['integration_id' => …], calc: ['restart_from' => $startTime, 'had_cursor' => $cursorBefore !== null, 'had_start_time' => $startBefore !== null, 'backfill_hours' => self::BACKFILL_HOURS, 'restart_margin_minutes' => self::RESTART_MARGIN_MINUTES])` |
| rate limited | `SystemLog::degraded('ingestion.poll.rate_limited', reason: 'provider_rate_limited', input: ['integration_id' => …], calc: ['retry_after_seconds' => $e->retryAfterSeconds, 'fallback_seconds' => self::RATE_LIMIT_FALLBACK_SECONDS, 'released_for_seconds' => $releaseFor])` |
| otro error de fetch | nada nuevo: `queue.job.attempt_failed` (automático) + `JobFailureReporter` en `failed()` ya lo cubren |
| ciclo completado | `SystemLog::ok('ingestion.poll.cycle_completed', input: ['integration_id' => …], calc: ['start_time' => $startTime, 'had_cursor' => $cursor !== null], result: ['events' => count($result['events']), 'has_more' => $result['has_more'] ?? false, 'next_cursor_present' => $result['cursor'] !== null])` |

Definiciones:
- **`$strategy`** en `StoreRawEvent`: `'explicit'` si llegó `$deduplicationKey`; `'external_event_id'` si se usó `$externalEventId`; `'checksum'` si se cayó al checksum. Calcúlalo antes del `??=`.
- **`$occurredAtSource` y `$parseFailed`:** `parseOccurredAt` pasa a devolver `array{value: ?DateTimeInterface, source: ?string, parse_failed: bool}`.
  - `source` es la clave del payload usada (`eventTime`, `data.happenedAtTime`, `startMs`, `time`, `createdAtTime` u `occurred_at`), o null si no había ninguna.
  - `parse_failed` es true cuando había valor pero no se pudo parsear.
  - `store()` usa `['value']` para el atributo.
- **`$dedupSource`:** `'deduplication_key'` si `$rawEvent->deduplication_key !== null`; si no, `'checksum'`.

**Nunca se registra el valor de la clave de dedupe.** Puede ser un checksum del payload o `eventId:estado`. Se registran su procedencia y los ids.

- [ ] **Step 1: Write the failing tests** — con los fixtures de cada archivo (añade `AssertsSystemLog`):
  - `RawEventIngestionServiceTest`: proveedor inexistente → `ingestion.provider.unresolved`. Un payload con `data.isResolved=true` → `ingestion.raw_event.stored` con `calc.dedup_key_strategy === 'explicit'`.
  - `StoreRawEventTest`:
    - `startMs` epoch-ms → `occurred_at_source === 'startMs'`;
    - `eventTime: 'no-es-fecha'` → `occurred_at_parse_failed === true` y `occurred_at_source === 'eventTime'`;
    - sin fecha → `occurred_at_source` null;
    - sin external id ni clave → `dedup_key_strategy === 'checksum'`.
  - `ProcessRawEventJobTest`:
    - primer evento → `ingestion.dedup.key_registered` y `ingestion.raw_event.processed`;
    - segundo con la misma clave → `ingestion.duplicate.detected` / `existing_key` con `result.first_raw_event_id`;
    - clave expirada (factory de `EventDeduplicationKey` con `expires_at` pasado) → `ingestion.dedup.key_expired` y luego `key_registered`.
  - `SafetyEventsPollingTest` / `PollSafetyEventsJobResilienceTest` (usa `FakesSamsaraSafetyStream`):
    - ciclo normal → `ingestion.poll.cycle_completed` con `result.events` igual al número de eventos del fake;
    - feed sin estado → `ingestion.poll.cursor_restarted`;
    - 429 → `ingestion.poll.rate_limited` con `calc.retry_after_seconds`;
    - evento con media inline → `ingestion.media.inline_collected` con `calc.urls_found` correcto;
    - reentrega del mismo evento y estado → `ingestion.media.inline_skipped` / `known_duplicate`;
    - sin `UsageMeter` → `ingestion.usage.not_metered`;
    - con meter → `ingestion.usage.recorded`.
  - En cada archivo, `assertNoSensitiveDataLogged()`.
  - Y en `StoreRawEventTest`, que el JSON de las entradas no contenga el checksum (`assertStringNotContainsString($rawEvent->checksum, …)`).

- [ ] **Step 2: Run to verify they fail** — `APP_KEY=… php artisan test --compact tests/Feature/Domains/Ingestion` → FAIL.
- [ ] **Step 3: Implement** la tabla.
- [ ] **Step 4: Catalog + run** — añade las filas de `ingestion.*` al catálogo; corre `tests/Feature/Domains/Ingestion`, `tests/Feature/Domains/Integrations` y `tests/Feature/Architecture/LoggingConventionsTest.php` → PASS.
- [ ] **Step 5: Commit** — `feat: log narrativo de ingesta, dedupe, media inline y poll de safety events`

---

### Task 3: Normalización

**Files:**
- Modify: `app/Domains/Normalization/Actions/MapExternalEventType.php`
- Modify: `app/Domains/Normalization/Actions/ResolveEventSeverity.php`
- Modify: `app/Domains/Normalization/Actions/NormalizeRawEvent.php`
- Modify: `app/Domains/Normalization/Jobs/NormalizeEventJob.php`
- Modify: `docs/SAM/logging.md`
- Test: `tests/Feature/Domains/Normalization/{MapExternalEventTypeTest,ResolveEventSeverityTest,NormalizeRawEventMonitoringGateTest,NormalizeEventJobTenantLeakTest}.php` y un test nuevo `tests/Feature/Domains/Normalization/NormalizeRawEventLogTest.php` para las ramas sin cobertura.

| Sitio / rama | Llamada |
|---|---|
| `MapExternalEventType::execute`, regla elegida | `SystemLog::ok('normalization.type.mapped', input: ['provider_id' => $providerId, 'external_event_type' => $externalEventType], calc: ['candidates' => $candidates->count(), 'evaluated' => $evaluated, 'rejected' => $rejected], result: ['mapping_rule_id' => $rule->id, 'event_type_code' => $rule->mappedEventType?->code, 'priority' => $rule->priority])` |
| sin candidatos | `SystemLog::skipped('normalization.type.unmapped', reason: 'no_rule_for_type', input: ['provider_id' => …, 'external_event_type' => …], calc: ['candidates' => 0])` |
| candidatos pero ninguno cumple | `SystemLog::skipped('normalization.type.unmapped', reason: 'conditions_not_met', input: […], calc: ['candidates' => $n, 'rejected' => $rejected])` |
| `ResolveEventSeverity::execute` | `SystemLog::ok('normalization.severity.resolved', input: ['mapping_rule_id' => $rule->id, 'event_type_code' => $type->code], calc: ['severity_source' => 'rule_override'\|'type_default'\|'medium_fallback'], result: ['severity_code' => $severity->code])` |
| `NormalizeRawEvent::execute`, sin proveedor | `SystemLog::skipped('normalization.type.unmapped', reason: 'no_provider', input: ['raw_event_id' => $rawEvent->id, 'external_event_type' => $externalEventType])` |
| ruta de monitor interno elegida | `SystemLog::ok('normalization.internal.resolved', input: ['raw_event_id' => …, 'event_type_code' => $internalType->code])` |
| `resolveInternalAssetId`, el asset no pertenece | `SystemLog::degraded('normalization.asset.rejected', reason: 'cross_tenant_internal_asset', input: ['raw_event_id' => $rawEvent->id])`. **Sin** el id ajeno. |
| `resolveAssetId` | una línea al final de cada salida: `SystemLog::ok('normalization.asset.resolved', input: ['raw_event_id' => …], calc: ['asset_path_used' => $path, 'reference_found' => bool, 'cross_tenant_rejected' => bool], result: ['asset_id' => $assetId])` (ok aunque sea null). Con `cross_tenant_rejected === true` va `degraded`, `reason: 'cross_tenant_reference'`, y `result.asset_id` es null. |
| `resolveDriverId` | igual: `normalization.driver.resolved`, con `driver_path_used` y el mismo tratamiento de `cross_tenant_reference` |
| descarte por activo no vigilado | `SystemLog::skipped('normalization.event.discarded', reason: 'asset_not_monitored', input: ['raw_event_id' => …, 'asset_id' => $assetId, 'event_type_code' => $eventType->code, 'category_code' => $category?->code], calc: ['is_emergency' => false])`. En la ruta interna usa `event_type_code` del tipo interno. |
| emergencia de activo no vigilado que pasa | `SystemLog::ok('normalization.event.emergency_unmonitored_passed', input: ['raw_event_id' => …, 'asset_id' => $assetId, 'event_type_code' => …, 'category_code' => …], calc: ['is_emergency' => true], result: ['normalized_event_id' => $normalizedEvent->id, 'billed_as_extra_asset_day' => true])` |
| evento normalizado (las tres rutas: mapeada, interna y unmapped) | `SystemLog::ok('normalization.event.normalized', input: ['raw_event_id' => …], result: ['normalized_event_id' => …, 'route' => 'mapped'\|'internal'\|'unmapped', 'event_type_code' => …, 'category_code' => …, 'severity_code' => …, 'asset_id' => …, 'driver_id' => …, 'unmonitored_asset' => bool])` |
| catálogo unmapped que cae al primer registro | `SystemLog::degraded('normalization.catalog.fallback_used', reason: 'catalog_row_missing', input: ['expected_code' => 'unmapped'\|'operational'\|'low', 'table' => 'event_types'\|'event_categories'\|'event_severities'])` cuando el `where('code', …)` devuelve null |
| `NormalizeEventJob::handle`, raw no existe | `SystemLog::skipped('normalization.job.skipped', reason: 'raw_event_missing', input: ['raw_event_id' => $this->rawEventId])` |
| status no permitido | `SystemLog::skipped('normalization.job.skipped', reason: 'status_not_normalizable', input: ['raw_event_id' => $rawEvent->id, 'status' => $rawEvent->status->value])` |

Notas de implementación:

- **`MapExternalEventType`:** registra `$rejected` como `list<array{mapping_rule_id: int, failed_path: string}>`, con la primera condición que falló de cada regla descartada. Solo el path, nunca el valor esperado ni el real (pueden venir del payload).
  - Para eso, `matchesConditions` pasa a devolver `?string`: null si cumple, o el path que falló. Una regla sin condiciones cumple. Con payload null falla con el path `'*'`.
  - `$evaluated` es cuántas reglas se evaluaron hasta la elegida.
- **`resolveAssetId` / `resolveDriverId`:** `$path` es la clave usada (`'asset.id'`, `'vehicle.id'`, `'vehicleId'` o `'data.conditions.0.details.panicButton.vehicle.id'`), o null si no hubo ninguna. Sin proveedor o sin team, la línea dice `asset_path_used = null` y `reference_found = false`.
  - Extrae las salidas a una variable y un único punto de log por función.
  - El `asset_id` ajeno **nunca** entra al log: `cross_tenant_rejected` es un bool.
- **`is_emergency`:** usa `self::isEmergencyCode()` como hoy.

- [ ] **Step 1: Write the failing tests** — en `NormalizeRawEventLogTest` (fixtures: copia el setUp de `NormalizeRawEventMonitoringGateTest`, que ya crea proveedor, reglas, tipos y activos):
  - evento mapeado con activo vigilado: `normalization.type.mapped`, `normalization.asset.resolved` (`asset_path_used` correcto), `normalization.severity.resolved`, y `normalization.event.normalized` con `route === 'mapped'`;
  - activo no vigilado y tipo no emergencia → `normalization.event.discarded` / `asset_not_monitored`, sin `normalization.event.normalized`;
  - activo no vigilado y pánico → `normalization.event.emergency_unmonitored_passed`;
  - referencia de activo de otro tenant → `normalization.asset.resolved` degraded, `cross_tenant_reference`, `calc.cross_tenant_rejected === true`, y el JSON de las entradas no contiene el id del activo ajeno;
  - lo mismo con el conductor → `normalization.driver.resolved`;
  - tipo sin regla → `normalization.type.unmapped` / `no_rule_for_type`, y `normalization.event.normalized` con `route === 'unmapped'`;
  - regla con condición que no cumple → `conditions_not_met`, con `calc.rejected[0].failed_path` igual al path de la condición;
  - evento interno (payload `internal.asset_id`) → `normalization.internal.resolved` y `route === 'internal'`; con asset ajeno → `normalization.asset.rejected` / `cross_tenant_internal_asset`;
  - en `ResolveEventSeverityTest`, las tres fuentes de severidad;
  - en `NormalizeEventJobTenantLeakTest` (o en el test nuevo), raw inexistente → `normalization.job.skipped` / `raw_event_missing`, y status `duplicate` → `status_not_normalizable`.
  - `assertNoSensitiveDataLogged()` en el test nuevo.

- [ ] **Step 2: Run to verify they fail** — `APP_KEY=… php artisan test --compact tests/Feature/Domains/Normalization` → FAIL.
- [ ] **Step 3: Implement** la tabla.
- [ ] **Step 4: Catalog + run** — filas de `normalization.*`; corre Normalization, Ingestion y `LoggingConventionsTest` → PASS.
- [ ] **Step 5: Commit** — `feat: log narrativo de normalización, mapeo, severidad y gate de vigilancia`

---

### Task 4: Contexto

**Files:**
- Modify: `app/Domains/Context/Actions/FetchLiveLocationForEvent.php`
- Modify: `app/Domains/Context/Actions/BuildEventContext.php`
- Modify: `app/Domains/Context/Actions/RequestDeferredEventMedia.php`
- Modify: `app/Domains/Context/Listeners/RequestPanicMediaOnContextBuilt.php`
- Modify: `app/Domains/Context/Jobs/EnrichContextJob.php`
- Modify: `docs/SAM/logging.md`
- Test: `tests/Feature/Domains/Context/{FetchLiveLocationForEventTest,EnrichContextJobTest,RequestPanicMediaOnContextBuiltTest,RequestDeferredEventMediaTest}.php`

| Sitio / rama | Llamada |
|---|---|
| `FetchLiveLocationForEvent`, sin team o activo | `SystemLog::skipped('context.live_location.skipped', reason: 'no_asset', input: ['normalized_event_id' => $normalizedEvent->id])` |
| severidad no crítica | `… reason: 'not_critical', input: [… 'severity_code' => $normalizedEvent->eventSeverity?->code]` |
| el payload ya trae GPS | `… reason: 'payload_has_gps'` |
| la última ubicación es reciente | `… reason: 'latest_location_fresh', calc: ['latest_age_seconds' => $age, 'staleness_threshold_seconds' => $stalenessSeconds]` |
| pedido en vivo fallido | `SystemLog::degraded('context.live_location.failed', reason: $refsTried === 0 ? 'no_active_integration' : 'provider_returned_nothing', input: ['normalized_event_id' => …, 'asset_id' => …], calc: ['references' => $refsCount, 'integrations_tried' => $refsTried, 'latest_age_seconds' => $age, 'staleness_threshold_seconds' => $stalenessSeconds], result: ['position_stale' => true])` |
| pedido en vivo ok | `SystemLog::ok('context.live_location.fetched', input: [...], calc: ['latest_age_seconds' => $age, 'staleness_threshold_seconds' => $stalenessSeconds, 'fix_age_seconds' => $fixAge], result: ['position_stale' => false, 'snapshot_updated' => true])` |
| `BuildEventContext::extractLocation` | `$location['source']` ya existe; la línea del snapshot lo registra |
| `BuildEventContext::execute`, snapshot guardado | `SystemLog::ok('context.snapshot.built', input: ['normalized_event_id' => …], calc: ['location_source' => $location['source'], 'location_age_seconds' => $locationAge, 'position_stale' => $liveFetch['position_stale'], 'geofence_matches' => count($geofenceMatches), 'related_incidents' => count($incidents), 'recent_events' => $recentHistory['recent_events_count'], 'recent_same_type' => $recentHistory['recent_same_type_count'], 'recent_high_severity' => $recentHistory['recent_high_severity_count'], 'correlation_minutes' => $correlationMinutes, 'schedule_persisted' => $schedule->isPersisted, 'within_operating_hours' => $schedule->withinOperatingHours, 'has_driver' => $driverSnapshot !== null], result: ['snapshot_id' => $snapshot->id, 'context_version' => $nextVersion, 'signals' => array_keys(array_filter($signals, …))])` |
| `EnrichContextJob`, el evento no existe | `SystemLog::skipped('context.enrich.skipped', reason: 'normalized_event_missing', input: ['normalized_event_id' => $this->normalizedEventId])` |
| `RequestPanicMediaOnContextBuilt`, evento no encontrado | `SystemLog::skipped('context.media.auto_request_skipped', reason: 'normalized_event_missing', input: ['snapshot_id' => $snapshot->id])` |
| no crítico | `… reason: 'not_critical', input: ['normalized_event_id' => …, 'severity_code' => …]` |
| setting desactivado | `… reason: 'setting_disabled', input: ['normalized_event_id' => …, 'setting_key' => self::SETTING_KEY]` |
| pedido hecho | lo registra `RequestDeferredEventMedia` (siguiente fila) |
| `RequestDeferredEventMedia`, pedido en vuelo reutilizado | `SystemLog::skipped('context.media.request_reused', reason: 'request_in_flight', input: ['normalized_event_id' => …, 'request_type' => $type->value, 'sweep_only' => $sweepOnly], result: ['event_media_request_id' => $existing->id, 'status' => $existing->status->value])` |
| pedido creado | `SystemLog::ok('context.media.requested', input: [...], calc: ['expires_in_hours' => 6], result: ['event_media_request_id' => $request->id])` |
| `recordUsage`, sin meter | `SystemLog::degraded('context.usage.not_metered', reason: 'meter_missing', input: ['meter_code' => self::USAGE_METER_CODE, 'event_media_request_id' => $request->id])` |
| `recordUsage`, registrado | `SystemLog::ok('context.usage.recorded', input: ['meter_code' => …, 'event_media_request_id' => …])` |

Definiciones:
- **`$age`:** segundos desde `latestLocation->recorded_at` hasta ahora, o null si no hay.
- **`$fixAge`:** segundos desde el `recorded_at` devuelto por el proveedor, o null.
- **`$refsCount` / `$refsTried`:** `fetchFromProvider` pasa a devolver `array{live: ?array, references: int, integrations_tried: int}`. Es un privado, así que no rompe firmas.
- **`$locationAge`:** segundos desde `$location['recorded_at']` si existe, o null.
- **`signals`:** la lista de claves de `$signals` con valor truthy. Solo nombres de señal, nunca valores.
- **`$correlationMinutes`:** extrae a variable el `max(1, …)` actual.

**Sin coordenadas:** nunca se registran `latitude`/`longitude`.

- [ ] **Step 1: Write the failing tests** — con los fixtures de cada archivo:
  - `FetchLiveLocationForEventTest`: una aserción por cada `reason` de `context.live_location.skipped`; el caso de fetch ok con `calc.staleness_threshold_seconds === 60` (el default) y `latest_age_seconds` coherente con el `recorded_at` del fixture (congela el reloj); el fallo con `integrations_tried`. Además, que el JSON de las entradas no contenga la latitud del fixture: `assertStringNotContainsString((string) $lat, …)`.
  - `EnrichContextJobTest`: `context.snapshot.built` con `location_source` correcto y `context_version === 1`, y 2 al reconstruir; evento inexistente → `context.enrich.skipped`.
  - `RequestPanicMediaOnContextBuiltTest`: `not_critical`, `setting_disabled`, y crítico con el setting activo → `context.media.requested`.
  - `RequestDeferredEventMediaTest`: segundo pedido igual → `context.media.request_reused`; sin meter → `context.usage.not_metered`.
  - `assertNoSensitiveDataLogged()` en cada archivo.

- [ ] **Step 2: Run to verify they fail** — `APP_KEY=… php artisan test --compact tests/Feature/Domains/Context` → FAIL.
- [ ] **Step 3: Implement** la tabla.
- [ ] **Step 4: Catalog + run** — filas de `context.*`; corre Context y `LoggingConventionsTest` → PASS.
- [ ] **Step 5: Commit** — `feat: log narrativo de contexto, ubicación en vivo y pedidos de media`

---

### Task 5: Job de media diferida

**Files:**
- Modify: `app/Domains/Context/Jobs/FetchDeferredEventMediaJob.php`
- Modify: `docs/SAM/logging.md` (reemplaza la fila de `media.deferred.closed_without_media` y quita la nota de "frase pendiente de convertir")
- Test: `tests/Feature/Domains/Context/FetchDeferredEventMediaJobTest.php`

**Cambio estructural:** `closeWithoutNewMedia(...)` y `markFailed(...)` reciben además un `string $reasonCode` (snake_case). La frase inglesa actual se conserva tal cual para `EventMediaFailed` y `retrieval_close_reason` (DB y evento, fuera de alcance). El log usa **solo** el código.

La línea `media.deferred.closed_without_media` de la fase 1 se sustituye por `media.deferred.closed`:
- sin evidencia subida (`markFailed`): `skipped`, `reason: $reasonCode`, `result: ['status' => $status->value, 'completed_via' => null]`;
- con evidencia subida: `ok`, `result: ['status' => 'completed', 'completed_via' => 'uploaded_media', 'close_reason' => $reasonCode]`.

Borra el código `media.deferred.closed_without_media` del catálogo y del código.

| Llamada actual (frase) | `$reasonCode` |
|---|---|
| 'Normalized event no longer exists.' | `normalized_event_missing` |
| 'Media retrieval window expired…' | `retrieval_window_expired` |
| 'No active integration with an external asset reference…' | `no_active_integration` |
| 'Event is older than the device footage retention window…' | `older_than_footage_retention` (con `calc: ['event_age_hours' => …, 'max_age_hours' => $maxAgeHours]`) |
| 'Request fulfilled by the quota-free uploaded-media sweep.' | `fulfilled_by_sweep` |
| 'Asset reports no paired camera…' | `no_camera_fulfilled_by_sweep` |
| 'Provider rejected the media retrieval request.' | `provider_rejected_retrieval` |
| 'Provider rejected every still-image retrieval request.' | `provider_rejected_all_stills` |
| 'Provider reported every requested clip as failed.' | `all_clips_failed` |
| 'Provider reported every requested still as failed.' | `all_stills_failed` |

Para `closeWithoutNewMedia`/`markFailed` con `calc` extra, añade un parámetro opcional `array $calc = []` que se pase a la línea.

Líneas nuevas en el resto de ramas:

| Rama | Llamada |
|---|---|
| `handle`, pedido inexistente o ya no en vuelo | `SystemLog::skipped('media.deferred.skipped', reason: $request === null ? 'request_missing' : 'not_in_flight', input: ['event_media_request_id' => $this->eventMediaRequestId, 'status' => $request?->status->value])` |
| `sweepUploadedMedia`, al final | `SystemLog::ok('media.deferred.sweep_completed', input: ['event_media_request_id' => …, 'normalized_event_id' => …], calc: ['window_seconds' => $windowSeconds, 'items_found' => count($items), 'available' => $availableCount], result: ['downloaded' => $downloaded])` |
| `placeRetrieval` ok | `SystemLog::ok('media.deferred.retrieval_placed', input: [...], calc: ['media_type' => …, 'inputs' => …, 'next_poll_seconds' => self::POLL_DELAY_SECONDS])` |
| `placeStillRetrievals` ok | `SystemLog::ok('media.deferred.stills_placed', input: [...], calc: ['stills_requested' => …, 'stills_rejected' => …, 'next_poll_seconds' => self::POLL_DELAY_SECONDS])` |
| `pollRetrieval` / `pollStillRetrievals` re-encola | `SystemLog::ok('media.deferred.polling', input: [...], calc: ['pending' => count($pending), 'available' => count($available), 'failed_downloads' => $failedDownloads, 'items' => count($items), 'next_poll_seconds' => self::POLL_DELAY_SECONDS], result: ['requeue_reason' => $pending !== [] ? 'pending_at_provider' : ($items === [] ? 'provider_unreachable' : 'download_failed')])` |
| completado con media nueva | `SystemLog::ok('media.deferred.completed', input: [...], result: ['available' => count($available), 'downloaded' => $downloaded])` |
| `continueSweepOnly`, sigue barriendo | `SystemLog::ok('media.deferred.sweep_polling', input: [...], calc: ['next_poll_seconds' => self::SWEEP_POLL_DELAY_SECONDS])` |

Usa los nombres reales de las variables locales de cada método. Si una rama no tiene la variable, calcúlala del mismo dato del que ya depende la rama; no inventes datos. En `input` van siempre `event_media_request_id` y `normalized_event_id`.

- [ ] **Step 1: Write the failing tests** — en `FetchDeferredEventMediaJobTest` (sus fixtures ya fakean el adaptador de media):
  - una aserción por cada `$reasonCode` que el archivo ya ejercita (expirado, sin integración, fuera de retención, sin cámara, proveedor rechaza, todos fallaron);
  - `media.deferred.polling` con `requeue_reason`;
  - `media.deferred.completed`;
  - `media.deferred.sweep_completed` con `items_found`;
  - pedido ya completado → `media.deferred.skipped` / `not_in_flight`.

  Para cada cierre, afirma que `reason` es el código y no la frase: `assertDoesNotMatchRegularExpression('/\s/', $c['reason'])`. `assertNoSensitiveDataLogged()` en el archivo.

- [ ] **Step 2: Run to verify they fail** — `APP_KEY=… php artisan test --compact tests/Feature/Domains/Context/FetchDeferredEventMediaJobTest.php` → FAIL.
- [ ] **Step 3: Implement** — los parámetros `$reasonCode` y `$calc`, y las líneas.
- [ ] **Step 4: Catalog + gate completo** — actualiza el catálogo. Run: `vendor/bin/pint --dirty --format agent`, la suite completa (`APP_KEY=… php artisan test --compact`) y `npm run types:check && npm run lint:check && npm run format:check` → todo verde.
- [ ] **Step 5: Commit** — `feat: códigos estables y narrativa completa del job de media diferida`

---

## Cierre de la fase

- [ ] Revisión de aislamiento con el subagente `tenant-isolation-reviewer`: ningún log con ids de otro tenant (Review Focus 3).
- [ ] `APP_KEY=… git push -u origin feat/logging-pipeline-entrada`, PR con el resumen de códigos, `gh pr checks --watch`, merge con la autorización amplia vigente y `git pull --ff-only` en el checkout principal.
- [ ] Siguiente: plan de la fase 3 (IA + Decisions).
