<?php

namespace App\Domains\Incidents\Events;

use App\Domains\Incidents\Models\Incident;
use App\Domains\Incidents\Models\IncidentResolution;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class IncidentResolved
{
    use Dispatchable, SerializesModels;

    public function __construct(
        public readonly Incident $incident,
        public readonly IncidentResolution $resolution,
    ) {}
}
