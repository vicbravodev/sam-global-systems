<?php

namespace App\Domains\Incidents\Events;

use App\Domains\Incidents\Models\Incident;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class IncidentStatusChanged
{
    use Dispatchable, SerializesModels;

    public function __construct(
        public readonly Incident $incident,
        public readonly string $previousStatus,
        public readonly string $newStatus,
        // Usuario que provocó el cambio (null = sistema/automatización). Los
        // listeners lo usan para no notificar a quien hizo el cambio.
        public readonly ?int $actorUserId = null,
    ) {}
}
