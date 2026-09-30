<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Aviso de evento tardío
    |--------------------------------------------------------------------------
    |
    | Si un incidente se abre más de estos minutos después del `occurred_at`
    | de su evento (rescate de ReprocessStuckRawEventsJob, webhook atrasado,
    | poller con cursor viejo), se marca en `metadata_json.late_arrival`, se
    | deja una entrada `late_arrival` en la línea de tiempo y la notificación
    | de creación dice cuánto hace que ocurrió. No cambia prioridad ni canales.
    |
    | El resto de claves `incidents.*` se leen con su default en el código.
    |
    */

    'late_notice_after_minutes' => (int) env('INCIDENTS_LATE_NOTICE_AFTER_MINUTES', 10),

];
