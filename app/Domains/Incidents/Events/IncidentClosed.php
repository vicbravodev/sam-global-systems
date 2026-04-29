<?php

namespace App\Domains\Incidents\Events;

use App\Domains\Incidents\Models\Incident;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class IncidentClosed
{
    use Dispatchable, SerializesModels;

    public function __construct(
        public readonly Incident $incident,
    ) {}
}
