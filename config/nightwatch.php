<?php

// Laravel Nightwatch (monitoreo de producción). El agente corre como sidecar
// en compose.prod.yaml; la política de redacción vive en
// App\Providers\NightwatchServiceProvider. Referencia de variables:
// https://nightwatch.laravel.com/docs/environment-variables

$token = env('NIGHTWATCH_TOKEN');

return [

    // Sin token no hay a dónde enviar: apagado (dev, CI, tests) sin tener que
    // acordarse de NIGHTWATCH_ENABLED=false.
    'enabled' => env('NIGHTWATCH_ENABLED', is_string($token) && $token !== ''),
    'token' => $token,
    // SHA del commit horneado en la imagen (APP_VERSION, de GIT_SHA en deploy.yml):
    // agrupa regresiones por deploy.
    'deployment' => env('NIGHTWATCH_DEPLOY', env('APP_VERSION')),
    'server' => env('NIGHTWATCH_SERVER', (string) gethostname()),
    'capture_exception_source_code' => env('NIGHTWATCH_CAPTURE_EXCEPTION_SOURCE_CODE', true),

    // Nunca configurable por env: el payload trae teléfonos, nombres y
    // payloads de proveedor (webhooks de Samsara/Twilio).
    'capture_request_payload' => false,
    'redact_payload_fields' => ['_token', 'password', 'password_confirmation'],
    'redact_headers' => [
        'Authorization', 'Cookie', 'Proxy-Authorization', 'X-XSRF-TOKEN', 'X-CSRF-TOKEN',
        'X-Twilio-Signature', 'X-Samsara-Signature', 'X-SAM-Signature',
    ],

    // Muestreo de puntos de entrada. Las excepciones siempre se capturan con
    // su traza completa, aunque el request no haya salido en la muestra. Las
    // tareas programadas van bajas: el feed de telemática corre cada 5 s por tenant.
    'sampling' => [
        'requests' => env('NIGHTWATCH_REQUEST_SAMPLE_RATE', 0.1),
        'commands' => env('NIGHTWATCH_COMMAND_SAMPLE_RATE', 1.0),
        'exceptions' => env('NIGHTWATCH_EXCEPTION_SAMPLE_RATE', 1.0),
        'scheduled_tasks' => env('NIGHTWATCH_SCHEDULED_TASK_SAMPLE_RATE', 0.1),
    ],

    'filtering' => [
        'ignore_cache_events' => env('NIGHTWATCH_IGNORE_CACHE_EVENTS', false),
        'ignore_mail' => env('NIGHTWATCH_IGNORE_MAIL', false),
        'ignore_notifications' => env('NIGHTWATCH_IGNORE_NOTIFICATIONS', false),
        'ignore_outgoing_requests' => env('NIGHTWATCH_IGNORE_OUTGOING_REQUESTS', false),
        'ignore_queries' => env('NIGHTWATCH_IGNORE_QUERIES', false),
        'log_level' => env('NIGHTWATCH_LOG_LEVEL', env('LOG_LEVEL', 'debug')),
    ],

    'ingest' => [
        'uri' => env('NIGHTWATCH_INGEST_URI', '127.0.0.1:2407'),
        'timeout' => env('NIGHTWATCH_INGEST_TIMEOUT', 0.5),
        'connection_timeout' => env('NIGHTWATCH_INGEST_CONNECTION_TIMEOUT', 0.5),
        'event_buffer' => env('NIGHTWATCH_INGEST_EVENT_BUFFER', 500),
    ],

    // Tope de eventos por día (App\Support\NightwatchIngestBudget); vacío = sin
    // tope. Ponlo en la cuota mensual del plan / 30 con algo de margen.
    'daily_event_cap' => env('NIGHTWATCH_DAILY_EVENT_CAP'),

];
