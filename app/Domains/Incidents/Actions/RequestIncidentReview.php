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

class RequestIncidentReview
{
    public function __construct(
        private readonly AppendTimelineEntry $appendTimelineEntry,
    ) {}

    /**
     * Transition a non-terminal incident into the in-review status so a human
     * picks it up — used by automation workflows (B7) and the multimodal loop.
     */
    public function execute(
        Incident $incident,
        ?string $reason = null,
        IncidentCreatorType $requestedByType = IncidentCreatorType::System,
        ?int $requestedById = null,
    ): Incident {
        return DB::transaction(function () use ($incident, $reason, $requestedByType, $requestedById) {
            if ($incident->isTerminal()) {
                throw new RuntimeException('Cannot request review of a terminal incident.');
            }

            $previousStatusCode = $incident->status?->code ?? IncidentStatusCode::Open->value;

            $targetStatus = IncidentStatus::query()
                ->where('code', IncidentStatusCode::InReview->value)
                ->first();

            if ($targetStatus === null) {
                throw new RuntimeException('Incident status '.IncidentStatusCode::InReview->value.' not seeded.');
            }

            $incident->update(['incident_status_id' => $targetStatus->id]);

            $this->appendTimelineEntry->execute(
                incident: $incident,
                entryType: TimelineEntryType::StatusChanged,
                actorType: $requestedByType === IncidentCreatorType::User ? TimelineActorType::User : TimelineActorType::Automation,
                actorId: $requestedById,
                title: 'Human review requested',
                description: $reason,
                payload: [
                    'previous_status' => $previousStatusCode,
                    'reason' => $reason,
                ],
            );

            $fresh = $incident->fresh(['status', 'priority', 'type']);

            IncidentStatusChanged::dispatch($fresh, $previousStatusCode, IncidentStatusCode::InReview->value);

            broadcast(IncidentUpdatedBroadcast::fromModel($fresh));

            return $fresh;
        });
    }
}
