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

**Líneas en debug (volumen):** siguen existiendo, en nivel `debug`: `context.live_location.skipped` y `context.media.auto_request_skipped` con reason `not_critical`; `ingestion.media.inline_collected` con `urls_found = 0`; `normalization.asset.resolved` / `normalization.driver.resolved` ok sin id en el payload (path `null`); `ingestion.raw_event.processed`; `ai.media_fusion.skipped` con reason `no_media`; `ai.media.reused` con reason `already_assessed_same_evaluation`; `ai.media.batch_skipped`.

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
| `ai.quota.checked` | ok | - | `normalized_event_id`, `purpose` (`text` o `vision`); calc `is_critical`, `bypassed`, y sin bypass `billing_period_key`, `tokens_meters_present`, `tokens_used_this_period` (mes de facturación), `tokens_limit`, `calls_meter_present`, `calls_today` (desde el inicio del día; null si no se consultó), `calls_limit`; result `blocked`, `exceeded_by` (`monthly_tokens`, `daily_calls` o null). Debug cuando no bloquea. Sin meters el límite no se aplica (`tokens_meters_present=false`) |
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
| `decisions.rule.invalid` | degraded | `unknown_operator`, `malformed_condition` | `rule_id`, `rule_code`, `ruleset_id`, `rule_team_id`; calc `problems[]` (`path`, `problem`, `operator`, `field`), `problems_count`; result `invalid_nodes_evaluate_as` (false) |
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
| `automation.recipients.non_member_skipped` | skipped | `not_team_member` | `team_id`, `skipped_user_ids` |
| `automation.webhook.connection_failed` | degraded | `connection_failed` | `action_execution_id`, `error` |

### Incidentes (`incidents`)

| Código | Outcome | Reason posibles | Campos clave |
|---|---|---|---|
| `incidents.call_verification.skipped` | skipped | `no_voice_channel`, `no_phone_contact` | `verification_id` o `incident_id`, `team_id` |
| `incidents.call_verification.emergency_override` | degraded | `tenant_blocked` | `verification_id`, `team_id`, `blocked_reason` |
| `incidents.call_verification.placement_failed` | degraded | `provider_error` | `verification_id`, `error` |

### Notificaciones (`notifications`)

| Código | Outcome | Reason posibles | Campos clave |
|---|---|---|---|
| `notifications.delivery.skip_record_failed` | degraded | `record_failed` | `notification_id`, `recipient_id`, `channel_id`, `skip_reason`, `error` |
| `notifications.inbound_reply.ignored` | skipped | `no_keyword`, `unknown_token` | — |
| `notifications.inbound_reply.rejected` | degraded | `unexpected_sender` | `token_id` |
| `notifications.notification.cancelled` | skipped | `tenant_cannot_send` | `notification_id`, `team_id`, `blocked_reason` |

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
