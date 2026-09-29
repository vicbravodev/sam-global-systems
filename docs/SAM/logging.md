# Logging narrativo y seguro

Cada decisión y cada cálculo del sistema deja una línea JSON con un código estable. Todo pasa por `App\Support\SystemLog` (`ok`, `skipped`, `degraded`, `failed`, `measure`). Los logs van a `storage/logs/system-*.json`; el feed de telemática (ciclos cada 5 s) va aparte, a `storage/logs/telematics-*.json`.

## Esquema de una línea

```json
{
  "message": "ai.media.assessment_skipped",
  "level_name": "INFO",
  "context": {
    "outcome": "skipped",
    "reason": "image_cap_reached",
    "input": {"evaluation_id": 12, "event_media_context_id": 40},
    "calc": {"max_images_per_event": 6},
    "result": {},
    "duration_ms": null
  },
  "extra": {"trace_id": "01k…", "team_id": 7, "raw_event_id": 123, "normalized_event_id": 456}
}
```

| Campo | Regla |
|---|---|
| `message` | Código estable `dominio.etapa.resultado`, snake_case en inglés, sin texto variable. Es la clave por la que se filtra. |
| `outcome` | `ok` \| `skipped` \| `degraded` \| `failed`. `degraded` = siguió, pero peor de lo esperado (fallback, dato faltante, reintento). |
| `reason` | Código estable, obligatorio si `outcome` ≠ `ok`. Nunca una frase. |
| `input` | Los datos que decidieron la rama: ids, códigos, flags y umbrales. |
| `calc` | En cálculos, cada término, umbral y fórmula: debe poder rehacerse a mano. |
| `result` | Qué quedó: ids creados, estado final, conteos. |
| `duration_ms` | En operaciones medidas. |
| `error` | Sólo en `failed`/`degraded` con excepción: `SafeException::describe()` (`class`, `code`, `message` saneado, `at`; `sqlstate` en `QueryException`; `http_status` en `RequestException` del cliente HTTP, cuyo `message` es sólo `HTTP request returned status code {status}`, nunca el body), nunca `getMessage()`. |

`extra` lo pone el `Context` (`trace_id`, `team_id`, `parent_trace_id`, ids de etapa). Nivel derivado: `ok`/`skipped` → `info` (o `debug` en rutas calientes), `degraded` → `warning`, `failed` → `error`. Un código que no cumple `/^[a-z0-9_]+(\.[a-z0-9_]+){2,}$/`, o un `reason` ausente fuera de `ok`, lanza `App\Support\SystemLogSchemaViolation` (un `InvalidArgumentException`) fuera de producción. Cualquier otro fallo al escribir (sink caído, listener que lanza) se traga dentro de `SystemLog`: loguear nunca rompe al llamador.

## Cómo seguir un evento

```bash
# Todo lo que pasó con un evento, de punta a punta
jq 'select(.extra.trace_id == "<id>")' storage/logs/system-*.json

# Todas las veces que ocurrió una decisión
jq 'select(.message == "ai.media.assessment_skipped")' storage/logs/system-*.json

# Telemática (feed Samsara)
jq 'select(.message == "telematics.cycle.completed")' storage/logs/telematics-*.json
```

## Prohibido

- Llamar a `Log::`, `logger()` o `info()`: lo impide `Tests\Feature\Architecture\LoggingConventionsTest`.
- Pasar `getMessage()` a un log; la excepción va como `error: $e`.
- Teléfonos, emails, nombres de personas, direcciones, tokens, secretos, firmas, URLs con query, payloads crudos de proveedor, texto libre de operadores, prompts o respuestas de IA.
- Coordenadas sin redondear (máximo 3 decimales, y sólo donde expliquen una decisión).

**Red de seguridad:** `App\Support\RedactSensitiveLogData` corre como tap en cada canal y enmascara teléfonos, emails, tokens, claves sensibles y el path de las URLs de hosts fuera de la allowlist. Es la red, no el permiso: el código no debe depender de ella.

**Líneas en debug (volumen):** siguen existiendo, en nivel `debug`: `context.live_location.skipped` y `context.media.auto_request_skipped` con reason `not_critical`; `ingestion.media.inline_collected` con `urls_found = 0`; `normalization.asset.resolved` / `normalization.driver.resolved` ok sin id en el payload (path `null`); `ingestion.raw_event.processed`; `ai.quota.checked` cuando no bloquea y no hubo bypass crítico; `ai.media_fusion.skipped` con reason `no_media`; `ai.media.reused` con reason `already_assessed_same_evaluation`; `ai.media.batch_skipped`; `decisions.escalation_policy.resolved` con reason `not_required`; `incidents.emergency.fast_path` skipped con `not_emergency` y `no_event_type`; `incidents.call_verification.skipped` con `not_panic`, `attempt_already_requested`, `not_pending` y `not_in_flight`; `incidents.call_verification.status_ignored`; `automation.workflow.not_matched`; `automation.trigger.evaluated` con `matched_count = 0`; `notifications.channels.selected` ok (una por destinatario); `notifications.dedup.skipped` con `delivery_exists`; `notifications.status_change.skipped` con `internal_status`; `notifications.retry.skipped` con `not_failed`; `notifications.provider_status.skipped` con `empty_status`, `not_advancing` y `not_a_notification_delivery`.

## Cómo se prueba

Los tests usan `Tests\Concerns\AssertsSystemLog`: `assertSystemLogged($code, fn ($entry) => ...)`, `assertSystemNotLogged($code)` y `assertNoSensitiveDataLogged()`. Cada código nuevo lleva su entrada en este catálogo (el test arquitectural lo exige para los literales) y un test que lo afirme.

## Catálogo de códigos

### Cola (`queue`) — automático

| Código | Outcome | Reason posibles | Campos clave |
|---|---|---|---|
| `queue.job.finished` | ok | — | `job`, `queue`, `connection`, `attempt`, `duration_ms` |
| `queue.job.released` | skipped | `released` | `job`, `queue`, `connection`, `attempt` |
| `queue.job.attempt_failed` | degraded | `exception` | `job`, `queue`, `attempt`, `max_tries`, `duration_ms`, `error` |
| `queue.job.failed` | failed | `max_attempts_exceeded`, `timeout`, `exception` | `job`, `queue`, `attempt`, `error` |

Los jobs de la cola `telematics` (feed cada 5 s) escriben sus líneas de cola en el canal `telematics`: `finished` y `released` a `debug` (el nivel `info` del canal los descarta por defecto), `attempt_failed` y `failed` con su nivel. Mientras corre un job de esa cola, sus `http.client.*` también van al canal `telematics`: las `ok` a `debug`, las `degraded`/`failed` con su nivel.

### Fallo definitivo de un job: `{dominio}.{job}.failed`

Patrón de `JobFailureReporter::codeFor`: dominio en snake_case (de `App\Domains\{Dominio}`, o `app` si no es de dominio) + nombre de la clase sin el sufijo `Job` en snake_case, p. ej. `ingestion.poll_safety_events.failed`. Outcome `failed`, reason `exception`; campos `job` (clase), los ids de dominio que pasa cada job y `error`. Se emite además de `queue.job.failed`.

### HTTP saliente (`http.client`) — automático

| Código | Outcome | Reason posibles | Campos clave |
|---|---|---|---|
| `http.client.request.completed` | ok / degraded | `http_error` (respuesta 4xx/5xx) | `provider`, `method`, `host`, `path` o `path_hash`, `duration_ms` |
| `http.client.request.failed` | failed | `connection_failed` | `provider`, `method`, `host`, `path` o `path_hash`, `error` |

El `path` se conserva sólo para los proveedores de la allowlist (`samsara`, `twilio`, `openai`, `anthropic`, `s3`; `s3` = sólo hosts S3 de `amazonaws.com`, no API Gateway/Lambda/ELB). Para el resto (`slack`, `other`: webhooks de tenant, donde el path ES la credencial) se registra `path_hash` = primeros 12 caracteres del sha256 del path. Además, `RedactSensitiveLogData::sanitize()` conserva el path de las URLs en texto libre sólo para los hosts de la allowlist, sin userinfo (misma fuente: `AutomaticSystemLog::isPathAllowedHost`); en cualquier otro host (webhooks de tenant, Slack, Discord, Zapier, Teams) sustituye path y query por `/[redacted]`, también dentro de `error.message`.

### HTTP entrante rechazado (`http.request`)

Lo emite `App\Support\DeniedRequestLog` (outcome `degraded`); sólo la plantilla de la ruta, nunca el path real.

| Código | Outcome | Reason posibles | Campos clave |
|---|---|---|---|
| `http.request.denied` | degraded | `unauthenticated` (401), `forbidden` (403), `csrf_mismatch` (419) | `method`, `route_name`, `route_uri`, `status`, `exception`, `user_id`, `team_id` |
| `http.request.throttled` | degraded | `rate_limited` (429) | igual |
| `http.request.not_found` | degraded | `unknown_endpoint` (404 en `api/webhooks/*`) | igual |

### Autenticación (`auth`) — automático

| Código | Outcome | Reason posibles | Campos clave |
|---|---|---|---|
| `auth.login.succeeded` | ok | — | `user_id`, `guard`, `remember` |
| `auth.login.failed` | skipped | `invalid_credentials` | `user_id`, `guard`, `login_fingerprint` (HMAC-SHA256 con `app.key` del email normalizado, 12 caracteres; nunca el email ni un hash enumerable) |
| `auth.login.locked_out` | degraded | `too_many_attempts` | `route_name`, `login_fingerprint` |
| `auth.logout.succeeded` | ok | — | `user_id`, `guard` |
| `auth.password.reset` | ok | — | `user_id` |

### Almacenamiento (`storage`)

| Código | Outcome | Reason posibles | Campos clave |
|---|---|---|---|
| `storage.object.operation_failed` | failed | `storage_unavailable` | `operation` y el contexto del llamador, `error` |

### Auditoría (`audit`)

| Código | Outcome | Reason posibles | Campos clave |
|---|---|---|---|
| `audit.domain_event.record_failed` | degraded | `classifier_failed`, `dispatch_failed` | `event_name`, `error` |

### Ingestión (`ingestion`)

| Código | Outcome | Reason posibles | Campos clave |
|---|---|---|---|
| `ingestion.media.inline_download_failed` | degraded | `download_failed` | `raw_event_id`, `url_key`, `error` |
| `ingestion.poll.cursor_rejected` | degraded | `provider_rejected_cursor` | `integration_id`, `http_status`, `provider_message` (saneado, 200 car.), `restart_from` |
| `ingestion.provider.unresolved` | degraded | `unknown_provider_code` | `provider_code`, `event_type` (ambos sólo si cumplen `App\Support\LoggableCode::PATTERN`; si no, `null`), `event_type_valid`; sin proveedor el evento terminará `unmapped` |
| `ingestion.raw_event.stored` | ok | | `source_type`, `provider_id`, `external_event_id`, `external_event_type`, `calc.dedup_key_strategy` (`explicit`/`external_event_id`/`checksum`), `calc.occurred_at_source` (clave del payload), `calc.occurred_at_parse_failed`, `raw_event_id`, `event_source_id` |
| `ingestion.raw_event.processed` | ok | | `raw_event_id` |
| `ingestion.dedup.skipped` | skipped | `no_dedup_key` | `raw_event_id` |
| `ingestion.dedup.key_expired` | ok | | `raw_event_id`, `expired_key_raw_event_id` |
| `ingestion.dedup.key_registered` | ok | | `raw_event_id`, `dedup_source` (`deduplication_key`/`checksum`), `calc.ttl_hours` |
| `ingestion.duplicate.detected` | skipped | `existing_key`, `lost_insert_race` | `raw_event_id`, `dedup_source`, `first_raw_event_id` (sólo `existing_key`). Nunca el valor de la clave |
| `ingestion.media.inline_skipped` | skipped | `known_duplicate` | `raw_event_id`, `event_state` |
| `ingestion.media.inline_collected` | ok | | `raw_event_id`, `calc.urls_found`, `calc.downloaded`, `calc.failed` |
| `ingestion.usage.not_metered` | degraded | `meter_missing` | `meter_code`, `raw_event_id`; hueco de facturación |
| `ingestion.usage.recorded` | ok | | `meter_code`, `raw_event_id`, `event_state` |
| `ingestion.poll.cursor_restarted` | ok | | `integration_id`, `calc.restart_from`, `had_cursor`, `had_start_time`, `backfill_hours`, `restart_margin_minutes` |
| `ingestion.poll.rate_limited` | degraded | `provider_rate_limited` | `integration_id`, `calc.retry_after_seconds`, `fallback_seconds`, `released_for_seconds` |
| `ingestion.poll.cycle_completed` | ok | | `integration_id`, `calc.start_time`, `had_cursor`, `result.events`, `has_more`, `next_cursor_present` |

### Webhooks (`webhook`)

| Código | Outcome | Reason posibles | Campos clave |
|---|---|---|---|
| `webhook.event.received` | ok | | `webhook_event_id`, `event_type` (sólo si cumple `/^[A-Za-z0-9_.-]{1,64}$/`, `App\Support\LoggableCode`; si no, `null`), `event_type_valid`; calc `body_bytes`, `has_signature_header`, `has_timestamp_header`. Nunca cuerpo, firma ni timestamp. Un endpoint desconocido no llega aquí: lo registra `http.request.not_found` (`DeniedRequestLog`, fase 1) |
| `webhook.signature.verified` | ok | | `input.scheme` (`timestamped`/`plain`), `calc.secret_variant` (`base64_decoded`/`raw`), `key_variants_tried`, `skew_seconds`, `tolerance_seconds` (`null` en `plain`: no se revisa hora) |
| `webhook.signature.rejected` | degraded | `empty_signature`, `invalid_timestamp`, `stale_timestamp`, `hmac_mismatch` | `input.scheme`; en `stale_timestamp`, calc `skew_seconds`, `tolerance_seconds`, `reference` (`received_at`/`now`), `timestamp_unit`; en `hmac_mismatch`, calc `key_variants_tried`, `skew_seconds`, `tolerance_seconds` (`null` en `plain`). Nunca firma, secreto ni cuerpo |
| `webhook.event.discarded` | skipped | `tenant_deleted` | `webhook_event_id` |
| `webhook.event.rejected` | skipped | `invalid_signature` | `webhook_event_id`, `signature_mode` (`raw_header`/`legacy_body`), `event_type` (sólo si cumple `/^[A-Za-z0-9_.-]{1,64}$/`; si no, `null`: viene de una petición sin autenticar), `event_type_valid` |
| `webhook.event.ingested` | ok | | `webhook_event_id`, `event_type` (con la misma guarda: puede venir de la query string, fuera del HMAC), `event_type_valid`, `signature_mode`, `provider_code` (con la misma guarda); `result.provider_code_fallback` |

### Normalización (`normalization`)

| Código | Outcome | Reason posibles | Campos clave |
|---|---|---|---|
| `normalization.type.mapped` | ok | | `input.provider_id`, `external_event_type`; calc `candidates`, `evaluated`, `rejected` (lista de `mapping_rule_id` + `failed_path`, sólo el path, nunca los valores); result `mapping_rule_id`, `event_type_code`, `priority` |
| `normalization.type.unmapped` | skipped | `no_rule_for_type`, `conditions_not_met`, `no_provider` | `provider_id` o `raw_event_id`, `external_event_type`; calc `candidates`, `rejected` |
| `normalization.severity.resolved` | ok | | `mapping_rule_id`, `event_type_code`; calc `severity_source` (`rule_override`/`type_default`/`medium_fallback`); result `severity_code` |
| `normalization.internal.resolved` | ok | | `raw_event_id`, `event_type_code` (evento de monitor interno, sin proveedor ni regla) |
| `normalization.asset.resolved` | ok / degraded | `cross_tenant_reference`, `referenced_asset_trashed`, `referenced_asset_missing` | `raw_event_id`; calc `asset_path_used` (clave del payload o `null`), `reference_found`, `cross_tenant_rejected` (sólo `true` con `cross_tenant_reference`), `rejection` (el mismo código que `reason`, `null` en ok); result `asset_id` (`null` si se rechazó; el id ajeno nunca se registra). Un activo propio borrado (soft delete) no es alarma cross-tenant. Ok sin id en el payload (`asset_path_used = null`) va en debug |
| `normalization.driver.resolved` | ok / degraded | `cross_tenant_reference`, `referenced_driver_trashed`, `referenced_driver_missing` | igual, con `driver_path_used` y `driver_id` |
| `normalization.asset.rejected` | degraded | `cross_tenant_internal_asset`, `internal_asset_trashed`, `internal_asset_missing` | `raw_event_id`; calc `rejection` (= `reason`); nunca el id ajeno ni ningún `team_id` |
| `normalization.event.discarded` | skipped | `asset_not_monitored` | `raw_event_id`, `asset_id`, `event_type_code`, `category_code`, `monitoring_state` (`pending`/`excluded`); calc `is_emergency` (real), `emergency_exemption_applies` (`true` en la ruta mapeada; `false` en la interna, que descarta incluso emergencias) |
| `normalization.event.emergency_unmonitored_passed` | ok | | `raw_event_id`, `asset_id`, `event_type_code`, `category_code`; calc `is_emergency=true`; result `normalized_event_id`, `extra_charge_dispatched=true`, `charge_scope=asset_local_day`. Sólo afirma que se despachó `UnmonitoredAssetEmergencyReceived`: el cobro real lo registra billing (fase 5), idempotente por activo y día local |
| `normalization.event.normalized` | ok | | `raw_event_id`; result `normalized_event_id`, `route` (`mapped`/`internal`/`unmapped`), `event_type_code`, `category_code`, `severity_code`, `asset_id`, `driver_id`, `unmonitored_asset` |
| `normalization.catalog.fallback_used` | degraded | `catalog_row_missing` | `expected_code` (`unmapped`/`operational`/`low`), `table` (`event_types`/`event_categories`/`event_severities`) |
| `normalization.job.skipped` | skipped | `raw_event_missing`, `status_not_normalizable` | `raw_event_id`, `status` |

### Contexto (`context`)

| Código | Outcome | Reason posibles | Campos clave |
|---|---|---|---|
| `context.live_location.skipped` | skipped | `no_asset`, `not_critical`, `payload_has_gps`, `latest_location_fresh` | `normalized_event_id`; en `not_critical`, `severity_code`; en `latest_location_fresh`, calc `latest_age_seconds`, `staleness_threshold_seconds`. Nunca coordenadas |
| `context.live_location.failed` | degraded | `no_active_integration`, `provider_returned_nothing` | `normalized_event_id`, `asset_id`; calc `references`, `integrations_tried`, `latest_age_seconds`, `staleness_threshold_seconds`; result `position_stale=true` |
| `context.live_location.fetched` | ok | | `normalized_event_id`, `asset_id`; calc `latest_age_seconds`, `staleness_threshold_seconds`, `fix_age_seconds` (`null` si el proveedor no dio hora), `fix_time_source` (`provider`/`assumed_now`); result `position_stale=false`, `snapshot_updated` (`false` si ese fix ya estaba guardado) |
| `context.snapshot.built` | ok | | `normalized_event_id`; calc `location_source` (`event_payload`/`live_fetch`/`asset_latest_location`/`unknown`), `location_age_seconds`, `position_stale`, `geofence_matches`, `related_incidents`, `recent_events`, `recent_same_type`, `recent_high_severity`, `correlation_minutes`, `schedule_persisted`, `within_operating_hours`, `has_driver`; result `snapshot_id`, `context_version`, `signals` (sólo nombres de señal activas), `risk_level` (del perfil operativo). Se emite después de que la transacción del snapshot confirma: un snapshot revertido nunca se reporta. `location_age_seconds` es siempre `null` con `location_source = event_payload` (el payload no trae hora del fix) |
| `context.enrich.skipped` | skipped | `normalized_event_missing` | `normalized_event_id` |
| `context.media.auto_request_skipped` | skipped | `normalized_event_missing`, `not_critical`, `setting_disabled` | `snapshot_id` o `normalized_event_id`; `severity_code`; `setting_key` |
| `context.media.request_reused` | skipped | `request_in_flight` | `normalized_event_id`, `request_type`, `sweep_only`; result `event_media_request_id`, `status` |
| `context.media.requested` | ok | | `normalized_event_id`, `request_type`, `sweep_only`; calc `expires_in_hours`; result `event_media_request_id` |
| `context.usage.not_metered` | degraded | `meter_missing` | `meter_code`, `event_media_request_id`; hueco de facturación |
| `context.usage.recorded` | ok | | `meter_code`, `event_media_request_id` |

Las líneas del listener síncrono `RequestPanicMediaOnContextBuilt` (`context.media.requested`, `context.media.request_reused`, `context.usage.*`, `context.media.auto_request_skipped`), cuando las dispara ese listener, se emiten dentro de la transacción del snapshot, antes de `context.snapshot.built`: si esa transacción se revierte, describen algo que no quedó persistido.

### Samsara (`samsara`)

| Código | Outcome | Reason posibles | Campos clave |
|---|---|---|---|
| `samsara.media_retrieval.request_failed` | degraded | `connection_failed`, `provider_rejected` | `vehicle_id`, `media_type`, `http_status`, `provider_message`, `provider_request_id`, `error` |
| `samsara.media_retrieval.poll_failed` | degraded | `connection_failed`, `provider_rejected` | `retrieval_id`, `http_status`, `provider_message`, `provider_request_id`, `error` |
| `samsara.uploaded_media.listing_failed` | degraded | `connection_failed`, `provider_rejected` | `vehicle_id`, `http_status`, `provider_message`, `provider_request_id`, `error` |

### Media (`media`)

| Código | Outcome | Reason posibles | Campos clave |
|---|---|---|---|
| `media.event_media.extracted` | ok | — | `normalized_event_id`; result `media_created_count` |
| `media.frames.extracted` | ok | — | `media_context_id`; result `frames_extracted`, `frames_created` |
| `media.frames.ffmpeg_unavailable` | degraded | `ffmpeg_missing` | `media_context_id`, `ffmpeg_binary` |
| `media.frames.offset_missing` | skipped | `no_frame_at_offset` | `offset_seconds`, `exit_code`, `stderr_excerpt` (saneado) |
| `media.deferred.skipped` | skipped | `request_missing`, `not_in_flight` | `event_media_request_id`, `status` |
| `media.deferred.closed` | skipped / ok | skipped: `normalized_event_missing`, `retrieval_window_expired`, `no_active_integration`, `older_than_footage_retention`, `provider_rejected_retrieval`, `provider_rejected_all_stills`, `all_clips_failed`, `all_stills_failed`. ok: sin reason; cierra con evidencia subida y el motivo va en `result.close_reason` (cualquiera de los anteriores, y `fulfilled_by_sweep` / `no_camera_fulfilled_by_sweep`, que sólo se alcanzan por esta vía) | `event_media_request_id`, `normalized_event_id`, `calc.event_age_hours`/`max_age_hours` (retención), `result.status`, `result.completed_via`, `result.close_reason` |
| `media.deferred.sweep_completed` | ok | - | `event_media_request_id`, `normalized_event_id`, `calc.window_seconds`, `items_found`, `available`, `result.downloaded` (bajados en este barrido), `result.already_stored` (ya estaban en storage de un barrido anterior) |
| `media.deferred.retrieval_placed` | ok | - | `event_media_request_id`, `normalized_event_id`, `calc.media_type`, `inputs`, `next_poll_seconds` |
| `media.deferred.stills_placed` | ok | - | `event_media_request_id`, `normalized_event_id`, `calc.stills_requested`, `stills_rejected`, `next_poll_seconds` |
| `media.deferred.polling` | ok | - | `event_media_request_id`, `normalized_event_id`, `calc.pending`, `available`, `failed_downloads`, `items`, `next_poll_seconds`, `result.requeue_reason` (`pending_at_provider`/`provider_unreachable`/`download_failed`) |
| `media.deferred.completed` | ok | - | `event_media_request_id`, `normalized_event_id`, `result.available`, `result.downloaded` (de este sondeo), `result.already_stored` (disponibles que ya estaban guardados de un sondeo anterior), `result.stills_downloaded_total` (acumulado, solo stills) |
| `media.deferred.sweep_polling` | ok | - | `event_media_request_id`, `normalized_event_id`, `calc.next_poll_seconds` |
| `media.deferred.download_failed` | degraded | `download_failed` | `normalized_event_id`, `camera_input`, `error` |

### IA (`ai`) y copiloto (`copilot`)

| Código | Outcome | Reason posibles | Campos clave |
|---|---|---|---|
| `ai.gate.skipped` | skipped | `skip_type`, `skip_category` | `normalized_event_id`, `event_type_code`, `category_code`, `stage` (`context_listener`, `evaluate_job` o `reevaluate_job`), `calc.config_key`, `result.evaluated=false`, `result.decision_engine_runs=false` (el motor solo corre con `AIEvaluationCompleted`; no se afirma nada del incidente, que el fast path de emergencias puede abrir sin IA) |
| `ai.evaluation.skipped` | skipped | `normalized_event_missing` | `normalized_event_id` |
| `ai.evaluation.already_exists` | skipped | `evaluation_exists` | `normalized_event_id`, `result.existing_evaluation_id` |
| `ai.heuristics.evaluated` | ok | - | `normalized_event_id`; calc `signature_source` (`signature`, `event_signature` o null), `noise_match` (solo si es una firma de ruido constante; el valor libre del payload nunca se registra), `known_noise_signatures_count`, `duplicates_signal_present`, `recent_duplicates_count`, `duplicate_threshold`, `signals_missing`; result `short_circuit`, `rule`. Nota (heurística muerta, §8): `signals_missing` lista `payload.signature` y `signals.recent_duplicates_count` cuando ningún productor las llena, así se ve que la heurística no puede disparar |
| `ai.quota.checked` | ok | - | `normalized_event_id`, `purpose` (`text` o `vision`); calc `is_critical`, `bypassed`, y sin bypass `billing_period_key`, `tokens_meters_present`, `tokens_used_this_period` (mes de facturación), `tokens_limit`, `calls_meter_present`, `calls_today` (desde el inicio del día; null si no se consultó), `calls_limit`; result `blocked`, `exceeded_by` (`monthly_tokens`, `daily_calls` o null). Debug solo en la rama sin bypass que no bloquea; el bypass crítico y los bloqueos salen en info. Sin meters el límite no se aplica (`tokens_meters_present=false`) |
| `ai.risk.calculated` | ok | - | `normalized_event_id`, `snapshot_id`; calc `severity_code`, `severity_source` (`payload`, `event_severity` o `default`), `base`, `snapshot_present`, `risk_level`, `risk_level_boost`, `recent_events_count`, `recurrence_thresholds` (`high` 10, `medium` 3), `recurrence_boost`, `sensitive_geofence`, `geofence_boost`, `signal_boosts` (señal → boost aplicado), `signal_boost_total`, `sum` (sin clamp), `clamp` `[0.0, 1.0]`, `final`; result `risk_score`. Recomputable: `final = round(clamp(base + risk_level_boost + recurrence_boost + geofence_boost + signal_boost_total), 2)`. Sin snapshot todos los boosts valen 0.0 |
| `ai.evaluation.agent_failed` | degraded | `agent_error` | `normalized_event_id`, `error` (clase; solo afirma que el agente falló, el fallback se narra en `ai.evaluation.rules_only` tras el commit) |
| `ai.evaluation.rules_only` | degraded / skipped | `quota_exceeded`, `agent_error` (degraded); `heuristic_short_circuit` (skipped) | `normalized_event_id`, `result.evaluation_id`, `result.heuristic_rule` (`known_noise_signature` o `recent_duplicates_in_window`, solo en heurística), `result.error_class` (clase ya persistida, solo en `agent_error`). Se emite después del commit |
| `ai.priority.resolved` | ok | - | `evaluation_id`; calc `risk_score`, `classification`, `actionable`, `thresholds` (`urgent` 0.85, `high` 0.6, `normal` 0.3, inclusivos; no accionable → `low`); result `priority_level`, `requires_action`. Después del commit |
| `ai.false_positive.checked` | ok | - | `evaluation_id`; calc `classification`, `confidence`, `threshold` (0.85); result `is_false_positive` (`false_positive` y `confidence >= threshold`). Después del commit |
| `ai.evaluation.completed` | ok | - | `normalized_event_id`, `evaluation_version`, `route` (`ai`, `heuristic`, `quota_exceeded` o `agent_error`), `operator_feedback_present` (nunca el contenido); calc `base_risk`, `agent_risk_delta` (null fuera de `ai`), `risk_after_agent` (`round(clamp(base_risk + agent_risk_delta), 2)`), `fusion_applied` (bool; `false` sin fusión, deltas en 0.0), `fusion_risk_delta`, `risk_clamp` (`[0.0, 1.0]` con fusión; null sin fusión), `risk_score` (persistido), `base_confidence` (0.95 / 0.5 / 0.4 o la del agente), `fusion_confidence_delta`, `confidence_clamp` (`[0.05, 0.99]` con fusión; null sin fusión, porque ese paso no recorta), `confidence` (persistida). Recomputable con fusión (`fusion_applied=true`): `risk_score = round(clamp(risk_after_agent + fusion_risk_delta, risk_clamp), 2)` y `confidence = round(clamp(base_confidence + fusion_confidence_delta, confidence_clamp), 2)`; sin fusión (`fusion_applied=false`): `risk_score = risk_after_agent` y `confidence = round(base_confidence, 2)` (una confianza del agente de 1.0 se persiste 1.0, y una ilegible de 0.0 queda 0.0); result `evaluation_id`, `mode` (el que dejó la transacción), `classification`, `priority_level`, `model`, `input_tokens`, `output_tokens`, `cost_estimate`, `latency_ms`, `ai_inference_log_id`. Después del commit; nunca explicación, key_factors, prompt ni respuesta |
| `ai.media_fusion.skipped` | skipped | `no_media` (debug), `no_verdict` (solo media inconclusa, `low_quality` o `unavailable`) | `evaluation_id`; calc `media_assessed_count`. Rutas no heurísticas, después del commit y antes de `ai.priority.resolved` |
| `ai.media_fusion.applied` | ok | - | `evaluation_id`, `classification`, `is_critical_event`; calc `branch` (`confirms`, `critical_no_reduce` o `contradicts`), `media_assessed_count`, `media_confirms_count` (incluye amenaza visible), `media_contradicts_count`, `media_visible_threat_count`, `dismissive` (la IA dijo falso positivo/ruido/duplicado: invierte el signo del delta de confianza), `confidence_before`, `confidence_delta`, `confidence_bounds` `[0.05, 0.99]`, `confidence_after` (persistida), `risk_before` (riesgo tras el agente), `risk_delta`, `risk_bounds` `[0.0, 1.0]`, `risk_after` (persistido). Recomputable: `after = round(clamp(before + delta, bounds), 2)`. Después del commit; nunca la frase del análisis visual |
| `ai.media.batch_skipped` | skipped | `no_media` | `evaluation_id`. Debug |
| `ai.media.filtered` | skipped | `non_image_media` | `evaluation_id`; calc `received_count`, `image_count`, `excluded_count`, `excluded_media_types` (valores únicos de `MediaType`). El archivo excluido sigue como evidencia |
| `ai.media.reused` | skipped | `already_assessed_same_evaluation` (debug), `prior_conclusive_assessment` | `evaluation_id`, `event_media_context_id`; en `prior_conclusive_assessment`, calc `checked` (`before_lock` o `after_lock`) y `result.prior_evaluation_id`; result `assessment_id`, `assessment_result`. No se paga de nuevo |
| `ai.media.assessed` | ok | - | `evaluation_id`, `event_media_context_id`, `assessment_type`, `media_type`; calc `remaining_slots_before`, `max_images_per_event`; result `assessment_id`, `assessment_result`, `confidence`, `visible_threat`, `model`, `input_tokens`, `output_tokens`, `cost_estimate`, `latency_ms`. Después del commit del assessment y su uso; nunca `summary_text`, señales extraídas ni `storage_path` |
| `ai.media.batch_completed` | ok / degraded | `retry_pending` (degraded) | `evaluation_id`; calc `received_count` (antes del filtro de imágenes), `image_count`, `remaining_slots_at_start`, `max_images_per_event`; result `reused_count`, `created_count` (incluye `low_quality` y `unavailable` persistidos), `skipped_count` (media de este lote saltada por cualquier motivo, cada una una sola vez), `skipped_by_reason` (motivo → conteo: `in_progress`, `image_cap_reached`, `quota_exceeded`, `file_missing`, `transient_failure`), `retry_pending` (hubo un error transitorio que este mismo `execute` relanza tras la línea para que el job reintente; entonces la línea sale `degraded` con reason `retry_pending`), `mode_before`, `mode_after`. Invariante: `image_count = reused_count + created_count + skipped_count` y `skipped_count = suma(skipped_by_reason)` |
| `ai.media.job_skipped` | skipped | `evaluation_missing`, `no_media_ids`, `media_not_found` | `evaluation_id`; en `media_not_found`, calc `requested_count` |
| `ai.media.assessment_deferred` | skipped | `no_evaluation_yet` | `normalized_event_id`, `event_media_context_id`. Solo afirma que aún no había evaluación a la cual adjuntar la media; no promete un barrido posterior (en eventos que el gate salta no la evalúa nadie) |
| `ai.media.assessment_skipped` | skipped | `file_missing`, `image_cap_reached`, `in_progress`, `quota_exceeded` | `evaluation_id`, `event_media_context_id`; en `image_cap_reached`, calc `max_images_per_event`; en `file_missing`, `result.error_class` (clase de la excepción, nunca su mensaje) |
| `ai.media.assessment_rejected` | skipped | `rejected_before_model` | `evaluation_id`, `event_media_context_id`, `rejection` |
| `ai.media.assessment_retry` | degraded | `transient_failure` | `evaluation_id`, `event_media_context_id`, `error` |
| `ai.reevaluation.skipped` | skipped | `normalized_event_missing` | `normalized_event_id`, `trigger_type` (el gate del job lo narra `ai.gate.skipped` con `stage=reevaluate_job`) |
| `ai.reevaluation.superseded` | ok | - | `normalized_event_id`, `trigger_type`; result `superseded_request_id`, `previous_status` (`pending` o `processing`). El request previo queda `skipped` |
| `ai.reevaluation.completed` | ok | - | `normalized_event_id`, `trigger_type`, `trigger_reference_id`, `reason_present` (nunca el texto); result `reevaluation_request_id`, `evaluation_id`, `evaluation_version`. Tras marcar el request `completed` |
| `ai.reevaluation.not_requested` | skipped | `no_normalized_event`, `decision_pending` (el motor aún no corrió y leerá el hecho `media_assessment`), `media_already_assessed` (calc `media_context_count`, `assessed_elsewhere_count`), `incident_terminal` (result `incident_id`, `incident_status_id`) | `evaluation_id`, `normalized_event_id`. Listener de media evaluada |
| `ai.reevaluation.requested` | ok | - | `normalized_event_id`, `evaluation_id`, `trigger_type`, `requested_by` (`media_assessment` u `operator`), `trigger_reference_id` (solo media), `reason_present` (solo operador; nunca el texto); calc `debounce_s`, `new_media_count` (solo media); result `latest_assessment_result` (solo media). Dice "pedido", no "encolado": `ReevaluateEventJob` es único por (evento, trigger), así que el pedido puede absorberse en un job ya pendiente y no crear otro |
| `ai.media.assessment_unavailable` | degraded | `agent_error` | `evaluation_id`, `event_media_context_id`, `error` |
| `copilot.narration.fallback` | degraded | `agent_error` | `error` |

### Decisiones (`decisions`)

| Código | Outcome | Reason posibles | Campos clave |
|---|---|---|---|
| `decisions.engine.skipped` | skipped | `evaluation_missing` | `ai_evaluation_id`, `stage` (`engine_job`/`reevaluate_job`) |
| `decisions.decision.already_exists` | skipped | `decision_exists` | `ai_evaluation_id`, `stage` (`engine_job`/`evaluate_rules`); result `decision_id` |
| `decisions.ruleset.missing` | degraded | `no_active_ruleset` | `ai_evaluation_id`, `default_ruleset_code`; result `falls_back_to` (`ai_mapping`) |
| `decisions.rule.invalid` | degraded | `unknown_operator`, `malformed_condition`, `non_array_node`, `non_scalar_leaf` (el del primer problema) | `rule_id`, `rule_code`, `ruleset_id`, `rule_team_id`; calc `problems[]` (`path`, `problem`, `operator`, `field`, `evaluates_as`), `problems_count`. Todo nodo inválido evalúa `false` en `matches()` y nunca lanza: una regla mal configurada no casa y no tumba el motor. `non_array_node` = hijo no array de `all`/`any`; `non_scalar_leaf` = hoja con `field` u `operator` no escalar. result `invalid_nodes_evaluate_as` (false) |
| `decisions.rules.evaluated` | ok | — | `ai_evaluation_id`; calc `ruleset_id`, `ruleset_scope`, `candidate_count`, `evaluated_count`, `matched_rule_ids`, `matched`, `stopped_at`, `facts` (solo los que deciden las reglas por defecto); result `matched_count` |
| `decisions.outcome.forced_human_review` | ok | — | `ai_evaluation_id`; calc `from_code` (desenlace terminal que se bloqueó), `latest_media_result` (`contradicts_event`), `prior_actionable_decision` (true); result `decision_id`, `decision_code` = código persistido (`REQUIRE_HUMAN_REVIEW`, o `INCIDENT` si además actuó el piso crítico: sale también `decisions.outcome.floored` con `from_code` `REQUIRE_HUMAN_REVIEW`) |
| `decisions.outcome.floored` | ok | — | `ai_evaluation_id`; calc `from_code`, `to_code` (`INCIDENT`), `floor_severity_codes`; result `decision_id` |
| `decisions.outcome.resolved` | ok | — | `ai_evaluation_id`, `ruleset_id`, `classification`; calc `source`, `confidence`, `human_review_threshold`, `review_by_confidence` (= `confidence < human_review_threshold`), `risk`, `ai_outcome_code` + `ai_mapping_thresholds` (`escalate`, `incident`) o `ai_outcome_missing`, `guard_check`, `latest_media_result`, `floor_check`, `review_by_resolver` (= `review_by_confidence` ∨ `guard_check` = `forced_review`), `review_by_outcome` (desenlace = `REQUIRE_HUMAN_REVIEW`); result `decision_id`, `decision_code`, `source_type`, `rule_id`, `rule_code`, `requires_human_review` (= `review_by_resolver` ∨ `review_by_outcome`), `trace_steps_count` |
| `decisions.priority.resolved` | ok | — | `decision_id`; calc `ai_priority_level`, `requires_human_review` (→ `high`), `mapped`, `critical_bump` (Low/Normal → High por severidad crítica); result `priority_level` |
| `decisions.escalation_policy.resolved` | ok / degraded / skipped (debug) | `no_active_team_policy` (ESCALATE sin política activa del team: no escala a nadie), `rule_policy_inactive` (la regla apunta a una política propia inactiva), `rule_policy_foreign` (la regla, global, apunta a una política de otro team), `not_required` | `decision_id`; calc `policy_source` (`source_rule`/`team_default_for_escalate`) y `rule_policy_used` (solo ok), `rule_policy_present`, `rule_policy_scope` (`own`/`foreign`), `rule_policy_id` (solo si `own`: nunca el id de una política de otro team); `no_active_team_policy` solo lleva `rule_policy_present`/`rule_policy_scope` si la regla tenía política; result `escalation_policy_id` |

`decisions.rule.invalid` = hallazgo §8, antes silencioso; la regla sigue evaluando `false` en ese nodo. Nunca se registran valores de condición ni nombres de reglas.

Toda la narrativa del desenlace (`decisions.outcome.*`, `decisions.priority.resolved`, `decisions.escalation_policy.resolved`) se emite después del commit de `DB::transaction()`: si la decisión revierte, no sale ninguna. Nunca se registran `decision_reason` ni nombres de reglas, desenlaces o políticas; los códigos pasan por `LoggableCode::guard`.

`source` de `decisions.outcome.resolved` (qué ganó en `ResolveDecisionOutcome::resolve`):

| `source` | Cuándo | `source_type` |
|---|---|---|
| `hard_safety` | primera regla que casó con `stop_processing` y `outcome_override` (global o del tenant) | `rule` |
| `tenant_rule` | primera regla del tenant con `outcome_override` | `tenant_policy` |
| `global_rule` | primera regla global con `outcome_override` | `rule` |
| `ai_mapping` | sin regla: clasificación de la IA → `ai_outcome_code` (RealEvent: riesgo ≥ `escalate` → ESCALATE, ≥ `incident` → INCIDENT, si no ALERT; accionable con `review_by_confidence` → REQUIRE_HUMAN_REVIEW) | `ai` |
| `log_only_fallback` | el desenlace mapeado no existe en `decision_outcomes` (`ai_outcome_missing`) → LOG_ONLY | `fallback` |

`source` es la fuente de `resolve()`; si después actuó el guard o el piso, `source_type` pasa a `fallback` y `guard_check`/`floor_check` lo explican.

| `guard_check` (contradicción de media) | Significado |
|---|---|
| `outcome_not_terminal` | el desenlace no es IGNORE/LOG_ONLY: el guard no aplica |
| `media_not_contradicting` | la última evaluación de media del evento no contradice (`latest_media_result`, null si no hay) |
| `no_prior_actionable_decision` | la media contradice pero ninguna decisión previa actuó: la baja se mantiene |
| `forced_review` | la media contradice y una decisión previa actuó: REQUIRE_HUMAN_REVIEW (línea `decisions.outcome.forced_human_review`) |

| `floor_check` (piso crítico) | Significado |
|---|---|
| `outcome_creates_incident` | INCIDENT/ESCALATE: nada que subir |
| `rule_chose_review` | REQUIRE_HUMAN_REVIEW elegido por una regla configurada: se respeta |
| `not_critical` | la severidad del evento no está en `floor_severity_codes` |
| `floored` | evento crítico subido a INCIDENT (línea `decisions.outcome.floored`) |

Sin `decision_trace_id`: `GenerateDecisionTrace` crea una fila por paso; las trazas cuelgan de `decision_id` y `trace_steps_count` dice cuántas son.

### Automatización (`automation`)

| Código | Outcome | Reason posibles | Campos clave |
|---|---|---|---|
| `automation.recipients.non_member_skipped` | skipped | `not_team_member` | `team_id`; calc `non_member_skipped_count` (usuarios del target que no son miembros del team; nunca sus ids: son de otro tenant) |
| `automation.webhook.connection_failed` | degraded | `connection_failed` | `action_execution_id`, `error` |
| `automation.trigger.evaluated` | ok (debug si `matched_count = 0`) | | `trigger_type`, `source_type`, `source_reference_id` (`LoggableCode`); calc `candidates_count` (workflows activos del trigger, del team o de plataforma); result `matched_workflow_ids`, `matched_count`. Va por `DB::afterCommit`: los listeners corren dentro de la transacción de la decisión, la creación o el escalamiento |
| `automation.workflow.matched` | ok | | `automation_workflow_id`, `workflow_scope` (`tenant`/`global`), `trigger_type`, `source_type`; calc `conditions_count`; result `job_requested`. Va por `DB::afterCommit` |
| `automation.workflow.not_matched` | skipped (debug) | `condition_mismatch` | `automation_workflow_id`, `workflow_scope`, `trigger_type`, `source_type`; calc `failed_key`, `expected`, `actual` (primera condición que falla; los tres por `LoggableCode`: texto libre del tenant → null), `conditions_count`. Va por `DB::afterCommit` |
| `automation.workflow.skipped` | skipped | `workflow_unavailable`, `already_ran` | `source_type`; `workflow_unavailable` (job): sin `automation_workflow_id` (si no es del team ni de plataforma es de otro tenant); `already_ran`: `automation_workflow_id`, `source_reference_id` (`LoggableCode`), result `existing_workflow_execution_id` |
| `automation.workflow.started` | ok | | `automation_workflow_id`, `workflow_scope`, `source_type`, `source_reference_id` (`LoggableCode`); calc `steps_count`, `queued_count`, `awaiting_confirmation_count` (`requires_confirmation`, sin job), `reused_count` (la `ActionExecution` ya existía; el job se despacha igual), `cumulative_delays_seconds` (demora acumulada por paso = `delay` de su `ExecuteActionJob`), `template_resolved_count`, `incident_expected` (source `incident`/`escalation`), `incident_linked` (el incidente existe y es del team; `expected && ! linked` → los pasos de incidente fallarán con `no_linked_incident`); result `workflow_execution_id`, `status`, `usage_event_key` (`workflow_exec_{id}`). Tras la transacción propia |
| `automation.action.skipped` | skipped | `execution_missing`, `already_completed`, `already_cancelled` | `action_execution_id`, `action_type`, `execution_mode`, `source_type` |
| `automation.action.stopped` | skipped | `tenant_blocked`, `incident_terminal`, `human_control` | `action_execution_id`, `action_type`, `execution_mode`, `source_type`; calc `blocked_reason` (`tenant_blocked`) o `incident_id` + `delayed: true` (revalidación del paso con retraso). La ejecución queda `cancelled` con su motivo |
| `automation.action.completed` | ok | | `action_execution_id`, `action_type`, `execution_mode`, `source_type`, `incident_id` (sólo si es del team; si no, null), `incident_team_matches` (null sin incidente vinculado); calc `attempt`; result sólo `notification_id`, `notification_status`, `recipients_count`, `assignment_id`, `status_code` (incidente), `http_status` (webhook), `stub` (`deferred_v2`) — nunca `body`, `channel` ni destinatarios; `duration_ms` de la acción |
| `automation.action.failed` | degraded | ver tabla de `kind` | mismo `input`; calc `attempt`; result `error_class` + contexto del `kind`; `duration_ms`. Nunca `error`: el mensaje interpola `target_reference` (teléfono/email/URL) e ids ajenos y ya queda en `error_message` |
| `automation.action.retry_scheduled` | ok / skipped | `retries_exhausted` | `action_execution_id`; calc `attempts`, `max_retries`, `backoff_schedule_seconds`, `backoff_index` (= attempts), `index_in_schedule`; result `delay_seconds` = (backoff_schedule_seconds[attempts] ?? last(backoff_schedule_seconds)) ?: 0 = `delay` del `ExecuteActionJob`, `job_requested` |

| reason de `automation.action.failed` | Origen | Contexto |
|---|---|---|
| `webhook_url_missing` | webhook sin URL | |
| `webhook_connection_failed` | cURL no conectó (además `automation.webhook.connection_failed`) | |
| `webhook_redirect` | respuesta 3xx (no se siguen) | `http_status` |
| `webhook_http_error` | respuesta 4xx/5xx | `http_status` |
| `unsafe_url` | `OutboundUrlGuard` rechazó la URL | `unsafe_url_code` (`reserved_host`, `blocked_ip`, `numeric_host`, `unresolvable_host`, `scheme_not_allowed`, `malformed_url`, `credentials_in_url`); nunca el host |
| `unsupported_notification_action` | tipo de acción sin canal de notificación en el puente Send* | |
| `no_recipients` | ningún destinatario resuelto | |
| `notification_not_delivered` | la notificación quedó `failed` en todos los canales | `notification_id` |
| `assignee_missing` | asignar sin id de usuario | |
| `invalid_assignee` | `AssignIncident` rechazó al usuario (no miembro): su `InvalidArgumentException` se re-etiqueta en `assignIncident()`; `error_class` sigue siendo `InvalidArgumentException` | |
| `no_linked_incident` | acción de incidente sin incidente vinculado | |
| `incident_not_in_team` | el incidente no existe o no es del team (sin su id) | |
| `unexpected_exception` | cualquier otra excepción (incluido un `InvalidArgumentException` ajeno al guard de asignación) | |

Un workflow sin pasos no es un "skip": crea la ejecución, mide `incident_workflows` y termina; `automation.workflow.started` lo dice con `steps_count = 0` y `status: completed`. `RetryActionExecutionJob` no está en `routes/console.php`: hoy sólo reintenta el endpoint manual (`ActionExecutionController::retry` → `RetryFailedAction`).

### Incidentes (`incidents`)

| Código | Outcome | Reason posibles | Campos clave |
|---|---|---|---|
| `incidents.call_verification.skipped` | skipped | `not_panic` (debug), `disabled_by_tenant`, `incident_terminal`, `already_in_flight`, `already_concluded`, `attempt_already_requested` (debug), `no_phone_contact`, `not_pending` (debug), `no_voice_channel`, `not_in_flight` (debug) | `verification_id` o `incident_id`, `team_id`, `incident_type_code`, `attempt`; calc `setting_key` (`disabled_by_tenant`), `candidate_counts_by_source` (`no_phone_contact`: `driver`/`verification_contacts`/`escalation_steps`/`supervisors`), `channel_present`/`from_present`/`credentials_present` (`no_voice_channel`); result `verification_id`, `status` (`already_concluded`). Las del listener y de `StartIncidentCallVerification` van por `DB::afterCommit`. Nunca teléfonos ni la lista de contactos |
| `incidents.call_verification.emergency_override` | degraded | `tenant_blocked` | `verification_id`, `team_id`, `blocked_reason` |
| `incidents.call_verification.placement_failed` | degraded | `provider_error` | `verification_id`; result `error_class` (nunca `error`: el mensaje del proveedor puede traer el número marcado); `duration_ms` |
| `incidents.call_verification.requested` | ok | | `incident_id`, `attempt`; calc `candidates_from` (`resolved`/`metadata`), `candidates_count` (teléfonos de la cadena), `candidate_index` = (attempt − 1) mod candidates_count, `candidate_counts_by_source` (null con `metadata`: el reintento no re-resuelve), `restarted_after_suppression`; result `verification_id`, `job_requested`. Va por `DB::afterCommit`. Nunca `phone` ni `candidates` |
| `incidents.call_verification.placed` | ok | | `verification_id`, `incident_id`, `attempt`; calc `ring_timeout_seconds`, `configured_retry_delay_seconds`, `min_retry_delay_seconds` (30), `retry_delay_seconds` = max(min_retry_delay_seconds, configured_retry_delay_seconds) = delay del `EvaluateVerificationCallOutcomeJob`; result `call_sid`, `provider_status` (`LoggableCode`), `channel_id`, `safety_net_requested`; `duration_ms` de `createCall` |
| `incidents.call_verification.closed` | skipped | `incident_terminal`, `human_control` | `verification_id`, `incident_id`, `attempt`; result `failure_reason` (`human_control`). El intento queda `failed` sin llamar |
| `incidents.call_verification.safety_net` | ok / skipped | `not_due_yet` | `verification_id`, `incident_id`, `attempt`; calc `placed_at`, `retry_delay_seconds`, `due_at` = placed_at + retry_delay_seconds; result `failure_code` (`timeout_without_callback`) |
| `incidents.call_verification.attempt_failed` | ok / skipped | `already_answered`, `incident_terminal`, `human_control` | `verification_id`, `incident_id`, `attempt`; calc `failure_code` (prefijo del motivo: `placement_failed`/`timeout_without_callback`/`call_status`), `call_status` (`LoggableCode`), `configured_attempts`, `candidates_count`, `max_attempts_cap`, `budget` = min(max_attempts_cap, max(configured_attempts, candidates_count)); result `next` (`next_attempt`/`exhausted_escalated`), `next_attempt`, `outcome` (`no_answer`). Nunca el resto del motivo ni la descripción de la línea de tiempo |
| `incidents.call_verification.unverifiable_escalated` | ok / skipped | `incident_terminal`, `already_recorded` | `incident_id`, `unverifiable_code` (`no_phone_contact`/`voice_channel_unavailable`); result `escalated_now`, `status_after`. Va por `DB::afterCommit`. Nunca la descripción |
| `incidents.call_verification.answered` | ok / skipped | `already_answered`, `invalid_digit`, `incident_closed` | `verification_id`, `incident_id`, `attempt`; calc `digits_length` (nunca los dígitos); result `outcome` (`confirmed_real`/`confirmed_false`), `acknowledged`, `escalated`, `level_notified`, `closed`, `resolution_code` |
| `incidents.call_verification.status_ignored` | skipped (debug) | `status_not_final`, `not_in_flight` | `verification_id`, `incident_id`, `attempt`; calc `call_status` (`LoggableCode`). Un status final en vuelo no tiene línea propia: lo narra `attempt_failed` con `failure_code = call_status` |
| `incidents.emergency.fast_path` | ok / skipped | `no_event_type` (debug), `offline_parked`, `not_emergency` (debug) | `normalized_event_id`, `event_type_code`, `category_code`; calc `trigger` (`emergency_code`/`offline_in_motion`), `was_in_motion`; result `priority_code`, `job_requested`. Va por `DB::afterCommit`: corre dentro de la transacción de normalización |
| `incidents.emergency.job_skipped` | skipped | `event_missing`, `team_mismatch`, `incident_exists` | `normalized_event_id`; calc `team_matches` (`false`, nunca el id ajeno); result `incident_id` (el existente) |
| `incidents.creation.skipped` | skipped | `unknown_outcome`, `outcome_not_surfaced`, `no_normalized_event`, `event_missing` | `decision_id` o `normalized_event_id`; calc `outcome_code`. Las de decisión van por `DB::afterCommit` |
| `incidents.creation.requested` | ok | | `decision_id`, `normalized_event_id`; calc `outcome_code`, `priority_code`, `priority_source` (`review_default_medium`/`alert_default_low`/`decision_priority`), `request_review`; result `job_requested`. `*_requested` = pedido; las colas son `after_commit`, así que un pedido dentro de una transacción que revierte no sale |
| `incidents.creation.routed_to_existing` | ok | | `normalized_event_id`, `decision_id`; result `incident_id`, `decision_found` (la reevaluación la narra `ApplyReevaluationToIncident`) |
| `incidents.auto_assign.requested` | ok | | `incident_id`; result `job_requested` |
| `incidents.review.flagged` | ok / skipped | `linked_to_other_incident`, `not_open` | `incident_id`, `decision_id` (nunca el motivo de la revisión) |
| `incidents.type.resolved` | ok | | `normalized_event_id`; calc `requested_code`, `event_type_code`, `alias_code`, `category_code`, `category_bucket_code`, `candidates`, `tried` (consultados hasta el elegido), `used_last_resort` (catálogo sin buckets genéricos: primer tipo activo); result `incident_type_id`, `incident_type_code`. Cálculo sobre el catálogo: directa, sale aunque la creación revierta |
| `incidents.priority.resolved` | ok | | `normalized_event_id`, `incident_type_id`; calc `source` (`context_code`/`type_default`/`lowest_level_fallback`), `requested_code`, `requested_found`, `type_default_priority_id`; result `priority_code`, `priority_level`. Directa |
| `incidents.dedup.linked` | ok | | `normalized_event_id`, `incident_type_id`; calc `window_minutes`, `window_start` (= occurred_at − window_minutes), `matched_on` (`asset`/`driver`), `match_basis` (`opened_in_window`/`linked_event_in_window`); result `existing_incident_id`, `link_created`, `priority_raised`, `previous_priority_code`, `new_priority_code` (null si no subió) |
| `incidents.offline_burst.aggregated` | ok | | `normalized_event_id`; calc `branch` (`opened_aggregate`/`linked_to_aggregate`), `window_minutes`, `window_start`, `aggregate_found`, `recent_singles_count` (device_offline individuales del tenant en la ventana; null si hubo agregado), `configured_threshold`, `effective_threshold` = max(2, configured_threshold); abre el agregado cuando `recent_singles_count + 1 >= effective_threshold`; result `aggregate_incident_id`, `link_created`. Por debajo del umbral no hay línea: va en `incidents.incident.created.calc.offline_burst` con `branch = below_threshold` |
| `incidents.sla.calculated` | ok / skipped | `no_sla_for_priority` | `incident_id`, `incident_priority_id`, `team_id`; calc `sla_seconds`, `sla_source` (`tenant_override`/`priority_catalog`/`none`), `opened_at`, `now_at`, `base_at` = max(opened_at, now_at), `base_source` (`now`/`opened_at`), `late_by_seconds` = max(0, now_at − opened_at), `backfill_adjusted`; result `sla_due_at` = base_at + sla_seconds, `watchdog_requested` |
| `incidents.incident.created` | ok | | `normalized_event_id`, `decision_id`, `source_type`; calc `fast_path`, `dedup_checked`, `dedup_window_minutes`, `offline_burst` (términos de la ráfaga o null), `aggregate_burst`, `resolved_on_arrival`; result `incident_id`, `incident_type_code`, `priority_code`, `status_code`, `asset_id`, `driver_id`, `usage_event_key`. Nunca título, resumen ni `metadata_json`. Sale antes que las líneas de los listeners de `IncidentCreated` |
| `incidents.reevaluation.applied` | ok / skipped | `team_mismatch`, `incident_missing`, `decision_not_newer`, `already_applied`, `event_missing` | `incident_id` (nunca en `team_mismatch`: es de otro tenant; calc `team_matches: false`), `decision_id`, `decision_code`; calc `is_root_event`, `is_terminal`, `previous_decision_id`, `previous_priority_code`, `previous_level`, `decision_priority_code` (el código de prioridad de la decisión), `mapped_priority_code`, `priority_alias_used` (se aplicó `normal→medium`/`urgent→critical`), `mapped_level`, `current_decision_id` (en `decision_not_newer`); result `priority_raised` = !is_terminal ∧ mapped_level ≠ null ∧ mapped_level > (previous_level ?? 0), `related_decision_moved`, `false_positive_notice`, `evaluation_version`, `classification`. Nunca `decision_reason` ni el título del timeline. Sin incidente para el evento no hay línea (primera decisión) |
| `incidents.assignment.resolved` | ok / skipped | `already_assigned`, `no_active_profile`, `no_shift_rules`, `no_eligible_member`, `incident_missing` | `incident_id`, `stage` (`on_call_listener`/`auto_assign_job`); calc (on-call, de `ResolveOnCallOperator::explain`) `profile_present`, `local_time` (H:i), `local_day` (en la zona del perfil; la zona no se registra), `shifts_count`, `matched_shift_index` (índice posicional del turno ganador en `on_call` tras `array_values()`; null si no ganó ninguno), `shifts_matched_count` (todos los turnos de la lista que casan por horario, con `user_id` numérico, sean o no miembros: pasada pura sin consultas que nunca lanza), `malformed_shifts_count` (turnos que esa pasada salta por mal formados: `days` con entradas que no son string o `start`/`end` presentes y no string; nunca cuentan como casados), `non_member_skipped_count` (candidatos recorridos antes del ganador que no son miembros; nunca sus ids), `fallback_configured`, `fallback_is_member`, `source` (`shift`/`fallback`/`default_queue`); result `assignee_type`, `assignee_user_id`, `assignment_id`, `role`. Las del listener van por `DB::afterCommit` (corre en la transacción de la apertura) |
| `incidents.on_call.notified` | ok / skipped | `not_critical`, `user_without_email` | `incident_id`, `assignee_user_id`; result `notification_id`, `notification_reused` (`SendNotification` devolvió un aviso ya existente con la misma `event_key`: no se pidió uno nuevo), `forced_channel_types` (`['web']`). Nunca email ni nombre del operador. Por `DB::afterCommit` |
| `incidents.ack_check.skipped` | skipped | `incident_missing`, `acknowledged`, `terminal`, `human_control`, `not_due_yet` | `incident_id`, `level`, `attempt`; calc (`not_due_yet`) `sla_due_at`, `now_at` (ambos al segundo, del mismo instante), `seconds_until_due` = sla_due_at − now_at exacto |
| `incidents.ack_check.breached` | ok | | `incident_id`, `level`, `attempt`; calc `first_breach` (attempt = 1), `status_before`, `steps_count`; result `escalated_now` (se llamó a `EscalateIncident`), `status_after`. Nunca el título (va en el `subject` del aviso) |
| `incidents.ack_check.rearmed` | ok | | `incident_id`, `level`, `attempt`; calc `mode` (`retry_same_level`/`next_level`), `step_attempts` = max(1, step.attempts ?? 1), `retry_minutes` = max(1, step.retry_minutes ?? default_retry_minutes), `default_retry_minutes`, `current_offset_minutes`, `next_offset_minutes`; result `next_level`, `next_attempt`, `delay_minutes` = `retry_minutes` (reintento del nivel, attempt < step_attempts) o max(1, next_offset_minutes − current_offset_minutes) (siguiente nivel). Es el `delay` del job pedido |
| `incidents.ack_check.chain_exhausted` | skipped | `no_next_level` | `incident_id`, `level`, `attempt`; calc `steps_count`, `step_attempts`. Fin de la cadena de escalamiento |
| `incidents.escalation_level.notified` | ok / skipped | `no_supervisors` | `incident_id`, `level`, `notification_type`; calc `step_present`, `recipients_source` (`step_contacts`/`supervisors`), `contacts_count`, `recipients_count`, `step_channel_types`, `urgent` (crítico/alto; null con contactos), `forced_channel_types`; result `notification_id`. Nunca `contacts`, `recipients`, `subject` ni `body`. Por `DB::afterCommit` |
| `incidents.external_resolution.matched` | ok / skipped | `event_missing`, `not_resolved`, `no_open_incident` | `normalized_event_id`; calc `strategy` (`external_event_id`/`asset_window`/`none`), `external_event_id_present` (skipped), `window_minutes` (null si no se usó la ventana); result `incident_ids`, `incidents_count` |
| `incidents.external_resolution.applied` | ok / skipped | `already_annotated` | `incident_id`, `normalized_event_id`; calc `resolved_at_source` (`payload`/`event_occurred_at`), `allow_close`, `was_terminal`, `mode` (`panic.auto_close_on_external_resolution`; null si no se consultó); result `external_resolved_at`, `closed`. Por `DB::afterCommit` (la apertura la llama dentro de su transacción) |

Líneas de hecho de la apertura (`incidents.dedup.linked`, `incidents.offline_burst.aggregated`, `incidents.sla.calculated`, `incidents.incident.created`) van por `DB::afterCommit`: salen tras el commit más externo y nunca si revierte.

Después de abrir: `no_supervisors` (`incidents.escalation_level.notified`) y `no_eligible_member` (`incidents.assignment.resolved`) antes eran silenciosos: un nivel sin contactos ni admins/supervisores no avisaba a nadie y un on-call sin nadie elegible dejaba el incidente sin asignar sin rastro.

### Notificaciones (`notifications`)

| Código | Outcome | Reason posibles | Campos clave |
|---|---|---|---|
| `notifications.notification.requested` | ok | | `notification_type` (`LoggableCode`), `source_type`, `source_reference_id` (`LoggableCode`), `priority`, `triggered_by_type`; calc `explicit_recipients_count` (entradas de `payload.recipients`), `forced_channel_types` (los `force_channels` que son un `ChannelType` real; null si no se forzó); result `notification_id`, `job_requested` (se pidió `SendNotificationJob`; con `after_commit` nunca sale si revierte). Nunca `event_key`, `subject`, `body_preview` ni `payload`. Por `DB::afterCommit` (los listeners la llaman dentro de la transacción de la apertura/escalamiento) |
| `notifications.dedup.skipped` | skipped | `event_key_exists`, `event_key_race`, `delivery_exists` | `event_key_*` (`SendNotification`): `notification_type`, `source_type`; result `existing_notification_id` (misma `team_id` + `event_key`). `delivery_exists` (`DispatchNotification`, **debug**, un reintento del fan-out): `notification_id`, `recipient_id`, `channel_id` |
| `notifications.out_of_band.skipped` | skipped | `below_min_severity` | `incident_id`, `setting_key` (`notifications.out_of_band_min_severity`), `type_source` (`type_specific_template`/`generic`; nunca el id de la plantilla); calc `severity`, `severity_rank`, `severity_rank_source` (`priority`/`default_medium`), `min_severity` (`LoggableCode`), `min_severity_rank`, `min_severity_valid` (si no, se usó el default `medium`): se salta cuando `severity_rank < min_severity_rank`; result `forced_channel_types` (`['web']`). Por encima del umbral no hay línea propia: `notification.requested` lleva `forced_channel_types = null`. Por `DB::afterCommit` |
| `notifications.status_change.skipped` | skipped | `internal_status` (**debug**), `escalated_by_sla`, `no_recipients` | `incident_id`, `new_status`; calc (`no_recipients`) `candidates_count` (asignado + quien lo tomó, únicos), `actor_excluded`, `non_member_dropped_count`, `without_email_dropped_count`. Sólo conteos: nunca ids de candidatos (pueden ser de otro tenant). `escalated_by_sla`: el watchdog ya avisó. Por `DB::afterCommit` |
| `notifications.recipients.resolved` | ok / skipped | `no_recipients` | `notification_id`; calc (de `ResolveRecipients::explain`) `source` (`explicit`/`team_members`), `candidates_count` (entradas explícitas o membresías), `dropped_count_by_reason` (`not_an_array_count`, `no_address_count`, `no_user_count`, `no_email_count`: descartados en esta notificación); result (ok) `recipient_ids`, `recipients_count`, `recipients_reused` (un reintento de `SendNotificationJob` reutilizó las filas). `no_recipients` deja la notificación `cancelled`. Nunca direcciones ni nombres |
| `notifications.channels.selected` | ok (**debug**, una por destinatario) / skipped | `muted`, `no_usable_channels`, `no_channel_after_filters` | `notification_id`, `recipient_id`, `recipient_type`; calc (de `SelectNotificationChannels::explain`) `branch` (ver tabla abajo), `priority`, `usable_channel_types` (activos y no apagados por el tenant), `forced_types`, `critical_policy_types`, `quiet_hours_active`, `quiet_hours_source` (`user_preference`/`tenant_policy`/`none`), `silenced_types`, `muted`, `allowed_types` (sólo valores válidos de `ChannelType`), `allowed_types_source` (`user_preference`/`tenant_policy`), `recipient_channel_preference` (`LoggableCode`), `preference_matched`, `selected_types`, `selected_channel_ids` (canales de plataforma). Las ramas críticas no leen preferencia ni silencio: esos campos van null |
| `notifications.delivery.skipped` | skipped | `no_address`, `invalid_address` | `notification_id`, `recipient_id`, `channel_id`, `channel_type`. Además de la fila `skipped` en la DB; el texto de `ChannelAddress::invalidReason` no se registra |
| `notifications.delivery.create_failed` | degraded | `record_failed` | `notification_id`, `recipient_id`, `channel_id`, `error` (error de DB, sin texto de terceros). Antes se tragaba sin rastro; la entrega no se intenta |
| `notifications.delivery.sent` | ok | | `delivery_id`, `notification_id`, `recipient_id`, `channel_id`, `channel_type`, `provider` (`LoggableCode`), `stage` (`first`/`retry`/`fallback`), `attempt_number`, `duration_ms` (la llamada al driver); result `delivery_status`, `awaiting_provider_confirmation`, `provider_message_id`, `provider_status` (ambos `LoggableCode`), `resource_type`, `segments`, `charge_recorded` (quedó un `MessagingCharge`), `usage_meter_code`, `usage_metered` (`RecordMessagingUsage` lo midió). Nunca `errorMessage`, `response`, dirección, asunto ni cuerpo |
| `notifications.delivery.failed` | degraded | `permanent_failure`, `transient_failure` | los mismos `input` y `duration_ms` que `delivery.sent`; result `delivery_status`, `provider_error_code` (`LoggableCode`), `permanent`, `metered` (`false`: un fallo nunca se mide ni se cobra). `degraded`: la cadena sigue con reintento o fallback; el fracaso definitivo lo dicen `notifications.fallback.exhausted` y `dispatch.completed` con `notification_status = failed` |
| `notifications.dispatch.completed` | ok | | `notification_id`; calc `recipients_count`, `deliveries_attempted_count`, `deliveries_skipped_count_by_reason` (`no_address_count`, `invalid_address_count`, `delivery_exists_count`, `record_failed_count`), `sent_count` (aceptadas por el proveedor), `failed_count`; result `notification_status` (tras `RefreshNotificationStatus`) |
| `notifications.delivery.skip_record_failed` | degraded | `record_failed` | `notification_id`, `recipient_id`, `channel_id`, `skip_reason`, `error` |
| `notifications.escalation_guard.blocked` | skipped | `notification_missing`, `tenant_cannot_send`, `expired`, `incident_handled`, `recipient_reached` | `delivery_id`, `notification_id`, `stage` (`listener`/`retry_job`/`fallback_job`); calc (de `DeliveryEscalationGuard::explain`, misma cascada que `blockReason`; los pasos no evaluados van null) `ttl_minutes` (30), `notification_age_seconds` (`expired` cuando > `ttl_minutes` × 60), `incident_id` (sólo si la fuente es un incidente del mismo team), `incident_handled_at_present`, `reached_elsewhere`, `blocked_reason` (el motivo exacto: `tenant_missing`/`subscription_*` se agrupan en `tenant_cannot_send`, como `notification.cancelled`). Ni reintento ni fallback |
| `notifications.retry.skipped` | skipped | `not_failed` (**debug**), `relations_missing`, `permanent_failure`, `channel_disabled`, `no_valid_address` | `delivery_id`, `stage` (`listener`/`retry_job`); result `delivery_status` + `fallback_requested` (`channel_disabled`: la entrega queda `cancelled` y se pidió `FallbackNotificationChannelJob`), `delivery_status` (`no_valid_address`: queda `skipped`). El reenvío en sí lo narra `delivery.sent`/`delivery.failed` con `stage = retry` |
| `notifications.retry.scheduled` | ok | | `delivery_id`, `channel_type`; calc `attempt_number`, `max_attempts` (webhook 3, resto 5), `delays_seconds` (webhook `[30, 120, 600]`, resto `[30, 60, 120, 300, 600]`), `step` = max(0, min(attempt_number, count(delays_seconds)) − 1); result `delay_seconds` = `delays_seconds[step]` (el `delay` del `RetryNotificationDeliveryJob` pedido), `next_attempt_number`, `job_requested` |
| `notifications.fallback.requested` | ok | | `delivery_id`, `channel_type`; calc `trigger` (`permanent_failure`/`retries_exhausted`: attempt_number ≥ max_attempts), `attempt_number`, `max_attempts`; result `job_requested` (`FallbackNotificationChannelJob`) |
| `notifications.fallback.skipped` | skipped | `relations_missing`, `already_escalated` | `failed_delivery_id`. `already_escalated`: ya hay una entrega posterior (no `skipped`) para el destinatario; su propia cadena decide |
| `notifications.fallback.chosen` | ok | | `failed_delivery_id`; calc `policy_fallback_types` (orden de la política del tenant), `used_types` (tipos ya usados con el destinatario), `walk` (un paso `{channel_type, outcome}` por tipo recorrido, ver tabla abajo; el último es `chosen`); result `delivery_id`, `channel_type`, `channel_id` (canal de plataforma). Tras `AttemptDelivery`, que narra el envío con `stage = fallback` |
| `notifications.fallback.exhausted` | degraded | `no_fallback_channel` | `failed_delivery_id`; el mismo calc que `fallback.chosen` (`walk` sin `chosen`; vacío si la política no tiene tipos). Antes el job terminaba sin rastro y el destinatario se quedaba sin aviso |
| `notifications.provider_status.skipped` | skipped | `missing_fields`, `unknown_sid`, `empty_status` (**debug**), `not_a_notification_delivery` (**debug**), `delivery_missing`, `superseded_attempt`, `not_advancing` (**debug**) | `missing_fields`/`unknown_sid` (controller, sin tenant resuelto): calc `sid_present`, `status_present` / `resource_type`; nunca el SID. El resto (`ApplyTwilioStatusUpdate`): `charge_id`, `source` (`callback`/`poll`), `resource_type`, `delivery_id` (`delivery_missing`: la entrega del cargo ya no existe, `delivery_id` null, sólo se actualizó el cargo; `superseded_attempt`: la entrega existe pero su SID actual es otro, un reintento lo reemplazó, sólo se actualizó el cargo del intento viejo; `not_advancing`: tardío, fuera de orden o repetido, la entrega no retrocede); calc `provider_status`, `provider_error_code` (`LoggableCode`), `duration_seconds`; result (`not_advancing`) `from_status`, `to_status` (el destino que no avanzó) |
| `notifications.provider_status.applied` | ok | | `charge_id`, `source` (`callback`/`poll`), `resource_type` (`message`/`call`), `delivery_id`; calc `provider_status`, `provider_error_code` (ambos `LoggableCode`), `duration_seconds`; result `from_status`, `to_status`, `permanent` (`TwilioErrorCatalog::isPermanent` cuando `to_status = failed`; null si no). Única línea que afirma `delivered`. Nunca el texto de error (`failureMessage` es para la DB) |
| `notifications.inbound_reply.ignored` | skipped | `no_keyword`, `unknown_token`, `already_consumed`, `token_expired`, `incident_terminal` | `no_keyword`/`unknown_token`: —. El resto: `token_id`, `incident_id`, `channel_type`; result `consumed_action` (`already_consumed`: el que ya tenía, `LoggableCode`; `incident_terminal`: `noop_terminal`, por `DB::afterCommit`). Nunca el remitente, el cuerpo ni el código del token |
| `notifications.inbound_reply.applied` | ok | | `token_id`, `incident_id`, `channel_type`; calc `keyword` (`SI`/`NO`/`ESC`), `user_linked` (el token tiene usuario); result `action` (`acknowledge`/`dismiss`/`escalate`). Por `DB::afterCommit` (la transacción de `ProcessInboundReply`). Nunca el remitente, el cuerpo, el código del token ni su dirección |
| `notifications.inbound_reply.rejected` | degraded | `unexpected_sender` | `token_id` |
| `notifications.notification.cancelled` | skipped | `tenant_cannot_send` | `notification_id`, `team_id`, `blocked_reason` |

`notifications.delivery.sent` = aceptado por el proveedor. En Twilio es `accepted` (hay SID): la línea lo dice con `awaiting_provider_confirmation: true` y `delivery_status: queued`, y la entrega real llega después (`notifications.provider_status.applied`). `delivered` sólo lo afirma ese callback (o un canal síncrono como email/web).

`branch` de `notifications.channels.selected` (una por `return` de `SelectNotificationChannels::explain`) y el `reason` cuando la selección queda vacía:

| `branch` | Cuándo | `reason` si vacía |
|---|---|---|
| `no_team` | la notificación no tiene team | `no_channel_after_filters` |
| `no_usable_channels` | ningún canal activo sin apagar por el tenant | `no_usable_channels` |
| `critical_forced` | crítica con `force_channels` | `no_channel_after_filters` |
| `critical_policy` | crítica: canales críticos de la política | `no_channel_after_filters` |
| `forced` | no crítica con `force_channels` (menos los silenciados) | `no_channel_after_filters` |
| `muted` | preferencia silenciada y la prioridad lo permite | `muted` |
| `recipient_preference` | el filtro por `channel_preference` del destinatario dejó algo | — (nunca vacía) |
| `allowed_types` | tipos permitidos (preferencia o política) menos los silenciados | `no_channel_after_filters` |

`walk.outcome` de `notifications.fallback.chosen` / `.exhausted` (un paso por tipo de `policy_fallback_types`, en orden):

| `outcome` | Cuándo |
|---|---|
| `already_used` | el destinatario ya tuvo una entrega por ese tipo (dedup por tipo) |
| `no_usable_channel` | ningún canal de ese tipo activo y sin apagar por el tenant |
| `no_address` | el destinatario no tiene dirección para ese tipo: queda una entrega `skipped` |
| `invalid_address` | dirección inválida para el canal: queda una entrega `skipped` (el texto de `invalidReason` no se registra) |
| `race_lost` | otra ejecución concurrente ya creó esa entrega |
| `chosen` | se creó la entrega y se intentó el envío |

Selección de canales vacía (`notifications.channels.selected` skipped): antes no quedaba rastro ni en la DB (no se crea ninguna entrega). Las claves de los mapas por motivo llevan sufijo `_count` porque `no_address`/`no_email` como clave las enmascararía el redactor.

### Billing (`billing`)

| Código | Outcome | Reason posibles | Campos clave |
|---|---|---|---|
| `billing.messaging_charge.reconcile_failed` | degraded | `provider_error` | `charge_id`, `provider_sid`, `error` |
| `billing.messaging_charge.record_failed` | degraded | `record_failed` | `team_id`, `provider_sid`, `source_type`, `source_id`, `error` |
| `billing.messaging_usage.not_metered` | degraded | `record_failed` | `team_id`, `meter_code`, `event_key`, `error` |

### Conductores (`drivers`)

| Código | Outcome | Reason posibles | Campos clave |
|---|---|---|---|
| `drivers.sync.external_id_conflict` | skipped | `owned_by_other_tenant` | `team_id`, `provider_id`, `external_id` |

### Telemática (`telematics`) — `storage/logs/telematics-*.json`

| Código | Outcome | Reason posibles | Campos clave |
|---|---|---|---|
| `telematics.cycle.completed` | ok | — | `integration_id`, `feed`; result `pages`, `locations`, `readings`, `moved_assets`, `lag_s`; `duration_ms` |
| `telematics.cycle.failed` | degraded | `provider_error` | igual, más `error` |
| `telematics.backfill.completed` | ok | — | `integration_id`, `feed`, `from`, `until`; result `pages`, `stored` |
