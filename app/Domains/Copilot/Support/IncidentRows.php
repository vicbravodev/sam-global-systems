<?php

namespace App\Domains\Copilot\Support;

use App\Domains\Incidents\Models\Incident;
use App\Domains\Incidents\Support\IncidentStatusPresenter;

final class IncidentRows
{
    /**
     * @return array<string, mixed>
     */
    public static function row(Incident $incident, string $teamSlug): array
    {
        return [
            'id' => $incident->id,
            'reference' => $incident->reference(),
            'title' => $incident->title,
            'severity' => $incident->priority?->code ?? 'info',
            'statusLabel' => IncidentStatusPresenter::labelForIncident($incident),
            'assetCode' => $incident->asset?->code ?? $incident->asset?->name,
            'driverName' => $incident->driver?->full_name,
            'openedAt' => $incident->opened_at?->toIso8601String(),
            'slaDueAt' => $incident->sla_due_at?->toIso8601String(),
            'slaBreached' => $incident->sla_due_at !== null && $incident->sla_due_at->isPast() && ! $incident->isTerminal(),
            'href' => CopilotPresenter::incidentHref($teamSlug, $incident->id),
        ];
    }

    /**
     * @return array{kind: string, id: int, label: string, href: string}
     */
    public static function source(Incident $incident, string $teamSlug): array
    {
        return [
            'kind' => 'incident',
            'id' => $incident->id,
            'label' => $incident->reference().' · '.$incident->title,
            'href' => CopilotPresenter::incidentHref($teamSlug, $incident->id),
        ];
    }
}
