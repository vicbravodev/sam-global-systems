<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Rescate de raw events atascados
    |--------------------------------------------------------------------------
    |
    | ReprocessStuckRawEventsJob (cada 5 min) re-despacha los raw events que
    | fallaron o no avanzan: `failed`, `received`, `pending_processing` y
    | `processing` sin cambios, y `processed` sin evento normalizado.
    |
    | - stuck_after_minutes: minutos sin cambios (updated_at) para considerar
    |   atascado un evento. Por debajo, el pipeline normal (con sus reintentos
    |   y backoff) todavía puede estar trabajando en él.
    | - max_attempts: rescates por evento. Al agotarlos se alerta una vez
    |   (AlertPipelineFailure) y se deja de reintentar.
    | - max_age_hours: nunca se rescata un evento recibido hace más tiempo:
    |   sería backlog (despliegue inicial, incidente de infraestructura), no
    |   noticia, y re-abrir pánicos de hace días confunde a la operación.
    | - batch_size: tope de re-despachos por pasada (emergencias primero).
    |
    */

    'reprocess' => [
        'stuck_after_minutes' => (int) env('PIPELINE_REPROCESS_STUCK_AFTER_MINUTES', 15),
        'max_attempts' => (int) env('PIPELINE_REPROCESS_MAX_ATTEMPTS', 3),
        'max_age_hours' => (int) env('PIPELINE_REPROCESS_MAX_AGE_HOURS', 24),
        'batch_size' => (int) env('PIPELINE_REPROCESS_BATCH_SIZE', 100),
    ],

    /*
    |--------------------------------------------------------------------------
    | Alertas del proveedor sin clasificar
    |--------------------------------------------------------------------------
    |
    | Tipos externos que el proveedor usa para alertas (y emergencias: el
    | botón de pánico de Samsara llega como `AlertIncident`). Si uno de estos
    | se normaliza como `unmapped` (payload sin `data.conditions`, o
    | condiciones que ninguna regla reconoce) no abre incidente: puede ser un
    | pánico malformado. Se avisa a plataforma y a owners/admins del tenant
    | del evento (AlertPipelineFailure, tipo `unmapped_alert`), una vez por
    | raw event. Lista separada por comas en el env.
    |
    */

    'unmapped_alert_types' => array_values(array_filter(array_map(
        'trim',
        explode(',', (string) env('PIPELINE_UNMAPPED_ALERT_TYPES', 'AlertIncident')),
    ), static fn (string $type): bool => $type !== '' && $type !== '0')),

    /*
    |--------------------------------------------------------------------------
    | Respaldo de pánicos por polling (alert incidents de Samsara)
    |--------------------------------------------------------------------------
    |
    | El botón de pánico de Samsara llega por webhook (`AlertIncident`). Si el
    | webhook falla (Secret Key mal pegada, Samsara no entrega, red), el pánico
    | se perdería. PollSamsaraAlertIncidentsJob consulta además
    | `GET /alerts/incidents/stream` de las alertas de pánico de cada
    | integración Samsara activa con unidades monitorizadas, y mete cada
    | emergencia por el MISMO pipeline que el webhook, deduplicando con él por
    | la identidad del incidente (no por el id de la entrega).
    |
    | - enabled: encendido por defecto (seguridad de vida).
    | - interval_minutes: cadencia del scheduler (1–59).
    | - window_minutes: ventana de solape que se relee en cada barrido
    |   (por `updatedAtTime`). Debe superar `webhook_grace_seconds` para que
    |   un pánico rescatado se vuelva a ver ya fuera de la gracia y se pueda
    |   avisar del webhook roto.
    | - webhook_grace_seconds: margen que se le da al webhook antes de
    |   considerar que no entregó un pánico que el poll sí vio.
    | - configurations_refresh_minutes: cada cuánto se redescubren las alertas
    |   de pánico (`GET /alerts/configurations`, trigger 1034).
    | - max_pages: páginas por corrida; lo que sobre sigue en la próxima con el
    |   cursor y el `startTime` fijado.
    |
    */

    'alert_incidents_poll' => [
        'enabled' => (bool) env('PIPELINE_ALERT_INCIDENTS_POLL_ENABLED', true),
        'interval_minutes' => (int) env('PIPELINE_ALERT_INCIDENTS_POLL_INTERVAL_MINUTES', 1),
        'window_minutes' => (int) env('PIPELINE_ALERT_INCIDENTS_POLL_WINDOW_MINUTES', 10),
        'webhook_grace_seconds' => (int) env('PIPELINE_ALERT_INCIDENTS_POLL_WEBHOOK_GRACE_SECONDS', 120),
        'configurations_refresh_minutes' => (int) env('PIPELINE_ALERT_INCIDENTS_POLL_CONFIGURATIONS_REFRESH_MINUTES', 60),
        'max_pages' => (int) env('PIPELINE_ALERT_INCIDENTS_POLL_MAX_PAGES', 10),
    ],

    /*
    |--------------------------------------------------------------------------
    | Retención de datos operativos
    |--------------------------------------------------------------------------
    |
    | Filas transitorias que el pipeline deja atrás y que ya no alimentan nada:
    | se purgan cada noche (routes/console.php) en lotes. Un valor < 1 apaga la
    | purga de esa tabla (se registra como `skipped`, nunca borra todo).
    |
    | - webhook_events_days: webhooks ya resueltos (`processed`, `failed`,
    |   `invalid_signature`). De los `processed` el cuerpo útil ya vive en
    |   `raw_events`; los rechazados nadie los reintenta. Aquí sólo queda la
    |   copia cruda con su firma. Los `received`/`processing` no se tocan: si
    |   quedaron así, alguien debe mirarlos.
    | - integration_sync_jobs_days: corridas de sync terminadas (`completed`,
    |   `failed`). Sólo se consultan las en vuelo y la del aviso de activos
    |   pendientes, que es de minutos atrás.
    | - reply_tokens_days: días DESPUÉS de vencer (24 h de vida) para borrar
    |   un token de respuesta por SMS/WhatsApp. Guarda el teléfono del
    |   destinatario; lo que el token decidió ya quedó en el incidente.
    | - failed_jobs_days: jobs fallidos de la cola (`failed_jobs`). Horizon
    |   guarda su propia copia 7 días; esto acota la tabla de Laravel.
    |
    | Lo facturable y lo de auditoría (usage_events, messaging_charges,
    | audit_logs, domain_event_logs, …) NO se purga aquí.
    |
    */

    'retention' => [
        'webhook_events_days' => (int) env('PIPELINE_RETENTION_WEBHOOK_EVENTS_DAYS', 30),
        'integration_sync_jobs_days' => (int) env('PIPELINE_RETENTION_INTEGRATION_SYNC_JOBS_DAYS', 30),
        'reply_tokens_days' => (int) env('PIPELINE_RETENTION_REPLY_TOKENS_DAYS', 30),
        'failed_jobs_days' => (int) env('PIPELINE_RETENTION_FAILED_JOBS_DAYS', 30),
    ],

];
