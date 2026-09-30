<?php

namespace App\Domains\Incidents\Support;

use App\Domains\Incidents\Events\IncidentCreated;

/**
 * Un efecto de la apertura de un incidente (aviso, asignación on-call,
 * llamada de verificación, automatizaciones, recorrido GPS). Corre tras el
 * commit del incidente, en su propia transacción y aislado de los demás: ver
 * {@see IsolatesIncidentCreatedReaction}.
 */
interface IncidentCreatedReaction
{
    /**
     * El efecto en sí. Puede lanzar: sólo revierte lo suyo y se reintenta en
     * cola, así que debe ser idempotente por incidente.
     */
    public function react(IncidentCreated $event): void;

    /**
     * Cola del reintento: la del dominio, y siempre una que consuma un
     * supervisor de producción (config/horizon.php).
     */
    public function retryQueue(): string;
}
