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

### Webhooks (`webhook`)

| Código | Outcome | Reason posibles | Campos clave |
|---|---|---|---|
| `webhook.signature.verified` | ok | | `input.scheme` (`timestamped`/`plain`), `calc.secret_variant` (`base64_decoded`/`raw`), `key_variants_tried`, `skew_seconds`, `tolerance_seconds` |
| `webhook.signature.rejected` | degraded | `empty_signature`, `invalid_timestamp`, `stale_timestamp`, `hmac_mismatch` | `input.scheme`; en `stale_timestamp`, calc `skew_seconds`, `tolerance_seconds`, `reference` (`received_at`/`now`), `timestamp_unit`; en `hmac_mismatch`, calc `key_variants_tried`. Nunca firma, secreto ni cuerpo |
| `webhook.event.discarded` | skipped | `tenant_deleted` | `webhook_event_id` |
| `webhook.event.rejected` | skipped | `invalid_signature` | `webhook_event_id`, `signature_mode` (`raw_header`/`legacy_body`), `event_type` |
| `webhook.event.ingested` | ok | | `webhook_event_id`, `event_type`, `signature_mode`, `provider_code`; `result.provider_code_fallback` |

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
| `media.deferred.closed_without_media` | skipped | `closed_without_media` | `event_media_request_id`, `status`, `detail` (hoy es una frase fija del sistema, no texto de usuario; pendiente de convertir a código estable) |
| `media.deferred.download_failed` | degraded | `download_failed` | `normalized_event_id`, `camera_input`, `error` |

### IA (`ai`) y copiloto (`copilot`)

| Código | Outcome | Reason posibles | Campos clave |
|---|---|---|---|
| `ai.evaluation.rules_only` | degraded | `agent_error` | `normalized_event_id`, `error` |
| `ai.media.assessment_skipped` | skipped | `file_missing`, `image_cap_reached`, `in_progress`, `quota_exceeded` | `evaluation_id`, `event_media_context_id`; en `image_cap_reached`, calc `max_images_per_event`; en `file_missing`, `result.error_class` (clase de la excepción, nunca su mensaje) |
| `ai.media.assessment_rejected` | skipped | `rejected_before_model` | `evaluation_id`, `event_media_context_id`, `rejection` |
| `ai.media.assessment_retry` | degraded | `transient_failure` | `evaluation_id`, `event_media_context_id`, `error` |
| `ai.media.assessment_unavailable` | degraded | `agent_error` | `evaluation_id`, `event_media_context_id`, `error` |
| `copilot.narration.fallback` | degraded | `agent_error` | `error` |

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
