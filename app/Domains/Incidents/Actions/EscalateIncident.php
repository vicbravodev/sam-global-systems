<?php

namespace App\Domains\Incidents\Actions;

use App\Domains\Incidents\Enums\IncidentCreatorType;
use App\Domains\Incidents\Enums\IncidentStatusCode;
use App\Domains\Incidents\Enums\TimelineActorType;
use App\Domains\Incidents\Enums\TimelineEntryType;
use App\Domains\Incidents\Events\IncidentStatusChanged;
use App\Domains\Incidents\Models\Incident;
use App\Domains\Incidents\Models\IncidentStatus;
use App\Domains\Incidents\Support\IncidentUpdatedBroadcast;
use Illuminate\Support\Facades\DB;
use RuntimeException;

class EscalateIncident
{
    public function __construct(
        private readonly AppendTimelineEntry $appendTimelineEntry,
    ) {}

    /**
     * Transition a non-terminal incident into the escalated status, append a
     * timeline entry, and dispatch the status-changed event that triggers the
     * escalation automation workflow.
     */
    public function execute(
        Incident $incident,
        ?string $reason = null,
        IncidentCreatorType $escalatedByType = IncidentCreatorType::User,
        ?int $escalatedById = null,
    ): Incident {
        return DB::transaction(function () use ($incident, $reason, $escalatedByType, $escalatedById) {
            $previousStatusCode = $incident->status?->code ?? IncidentStatusCode::Open->value;

            $targetStatus = IncidentStatus::query()
                ->where('code', IncidentStatusCode::Escalated->value)
                ->first();

            if ($targetStatus === null) {
                throw new RuntimeException('Incident status '.IncidentStatusCode::Escalated->value.' not seeded.');
            }

            $incident->update(['incident_status_id' => $targetStatus->id]);

            $this->appendTimelineEntry->execute(
                incident: $incident,
                entryType: TimelineEntryType::Escalated,
                actorType: $escalatedByType === IncidentCreatorType::User ? TimelineActorType::User : TimelineActorType::System,
                actorId: $escalatedById,
                title: 'Incident escalated',
                description: $reason,
                payload: [
                    'previous_status' => $previousStatusCode,
                    'reason' => $reason,
                ],
            );

            $fresh = $incident->fresh(['status', 'priority', 'type']);

            IncidentStatusChanged::dispatch($fresh, $previousStatusCode, IncidentStatusCode::Escalated->value);

            broadcast(IncidentUpdatedBroadcast::fromModel($fresh));

            return $fresh;
        });
    }
}
