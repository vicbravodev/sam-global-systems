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

];
