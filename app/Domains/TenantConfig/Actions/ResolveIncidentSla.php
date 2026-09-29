<?php

namespace App\Domains\TenantConfig\Actions;

use App\Domains\Incidents\Models\IncidentPriority;
use App\Domains\TenantConfig\Models\TenantIncidentSla;
use App\Support\TenantContext;

class ResolveIncidentSla
{
    /**
     * Cascada: override del tenant → catálogo global → sin SLA.
     * Devuelve null sólo cuando ninguno de los dos define vigilancia.
     */
    public function execute(int $teamId, int $incidentPriorityId): ?int
    {
        return $this->resolve($teamId, $incidentPriorityId)['sla_seconds'];
    }

    /**
     * La misma cascada que `execute()`, con la fuente del valor (solo lectura).
     *
     * @return array{sla_seconds: ?int, sla_source: 'tenant_override'|'priority_catalog'|'none'}
     */
    public function resolve(int $teamId, int $incidentPriorityId): array
    {
        return TenantContext::for($teamId, function () use ($teamId, $incidentPriorityId) {
            $override = TenantIncidentSla::query()
                ->where('team_id', $teamId)
                ->where('incident_priority_id', $incidentPriorityId)
                ->first();

            if ($override !== null && $override->sla_seconds !== null) {
                return ['sla_seconds' => $override->sla_seconds, 'sla_source' => 'tenant_override'];
            }

            $catalog = IncidentPriority::query()
                ->whereKey($incidentPriorityId)
                ->value('sla_seconds');

            return $catalog !== null
                ? ['sla_seconds' => (int) $catalog, 'sla_source' => 'priority_catalog']
                : ['sla_seconds' => null, 'sla_source' => 'none'];
        });
    }
}
