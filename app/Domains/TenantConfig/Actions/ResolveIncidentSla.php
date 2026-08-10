<?php

namespace App\Domains\TenantConfig\Actions;

use App\Domains\Incidents\Models\IncidentPriority;
use App\Domains\TenantConfig\Models\TenantIncidentSla;

class ResolveIncidentSla
{
    /**
     * Cascada: override del tenant → catálogo global → sin SLA.
     * Devuelve null sólo cuando ninguno de los dos define vigilancia.
     */
    public function execute(int $teamId, int $incidentPriorityId): ?int
    {
        $override = TenantIncidentSla::withoutGlobalScopes()
            ->where('team_id', $teamId)
            ->where('incident_priority_id', $incidentPriorityId)
            ->first();

        if ($override !== null && $override->sla_seconds !== null) {
            return $override->sla_seconds;
        }

        return IncidentPriority::query()
            ->whereKey($incidentPriorityId)
            ->value('sla_seconds');
    }
}
