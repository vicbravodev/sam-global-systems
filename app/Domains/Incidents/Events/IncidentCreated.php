<?php

namespace App\Domains\Incidents\Events;

use App\Domains\Incidents\Models\Incident;
use Illuminate\Contracts\Events\ShouldDispatchAfterCommit;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

/**
 * Se despacha tras el commit de la apertura: un efecto (aviso, llamada,
 * asignación…) nunca corre dentro de la transacción del incidente, así que
 * su fallo no puede revertirlo, y un incidente revertido nunca avisa. Sus
 * listeners implementan IncidentCreatedReaction (aislados entre sí).
 */
class IncidentCreated implements ShouldDispatchAfterCommit
{
    use Dispatchable, SerializesModels;

    public function __construct(
        public readonly Incident $incident,
    ) {}
}
