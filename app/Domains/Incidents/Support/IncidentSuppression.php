<?php

namespace App\Domains\Incidents\Support;

use App\Domains\Incidents\Models\Incident;

final class IncidentSuppression
{
    /**
     * Un incidente está bajo control humano cuando alguien lo tomó o
     * acusó recibo. La automatización debe callarse en ese caso: el
     * operador ya está encima y las llamadas o escalaciones sólo estorban.
     */
    public static function isUnderHumanControl(Incident $incident): bool
    {
        return $incident->claimed_by_user_id !== null
            || $incident->acknowledged_at !== null;
    }
}
