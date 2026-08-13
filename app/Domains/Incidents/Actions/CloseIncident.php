<?php

namespace App\Domains\Incidents\Actions;

use App\Domains\Incidents\Enums\IncidentCreatorType;
use App\Domains\Incidents\Enums\IncidentStatusCode;
use App\Domains\Incidents\Enums\ResolutionCode;
use App\Domains\Incidents\Enums\TimelineActorType;
use App\Domains\Incidents\Enums\TimelineEntryType;
use App\Domains\Incidents\Events\IncidentClosed;
use App\Domains\Incidents\Events\IncidentResolved;
use App\Domains\Incidents\Events\IncidentStatusChanged;
use App\Domains\Incidents\Models\Incident;
use App\Domains\Incidents\Models\IncidentResolution;
use App\Domains\Incidents\Models\IncidentStatus;
use App\Domains\Incidents\Support\IncidentUpdatedBroadcast;
use Illuminate\Support\Facades\DB;
use RuntimeException;

class CloseIncident
{
    public function __construct(
        private readonly AppendTimelineEntry $appendTimelineEntry,
    ) {}

    public function execute(
        Incident $incident,
        ResolutionCode $resolutionCode,
        string $summary,
        ?string $rootCause = null,
        ?string $correctiveAction = null,
        ?string $preventiveAction = null,
        IncidentCreatorType $resolvedByType = IncidentCreatorType::User,
        ?int $resolvedById = null,
    ): IncidentResolution {
        return DB::transaction(function () use ($incident, $resolutionCode, $summary, $rootCause, $correctiveAction, $preventiveAction, $resolvedByType, $resolvedById) {
            $previousStatusCode = $incident->status?->code ?? IncidentStatusCode::Open->value;

            $targetStatusCode = match ($resolutionCode) {
                ResolutionCode::FalsePositive => IncidentStatusCode::FalsePositive,
                ResolutionCode::DuplicateIncident => IncidentStatusCode::Cancelled,
                ResolutionCode::UnresolvedClosed => IncidentStatusCode::Closed,
                default => IncidentStatusCode::Resolved,
            };

            $targetStatus = IncidentStatus::query()->where('code', $targetStatusCode->value)->first();

            if ($targetStatus === null) {
                throw new RuntimeException("Incident status {$targetStatusCode->value} not seeded.");
            }

            $resolution = IncidentResolution::query()->updateOrCreate(
                ['incident_id' => $incident->id],
                [
                    'resolution_code' => $resolutionCode,
                    'resolution_summary' => $summary,
                    'resolved_by_type' => $resolvedByType,
                    'resolved_by_id' => $resolvedById,
                    'root_cause' => $rootCause,
                    'corrective_action' => $correctiveAction,
                    'preventive_action' => $preventiveAction,
                    'resolved_at' => now(),
                ],
            );

            $now = now();
            $updates = [
                'incident_status_id' => $targetStatus->id,
                'resolved_at' => $now,
            ];

            if ($targetStatusCode === IncidentStatusCode::Closed) {
                $updates['closed_at'] = $now;
            }
            if ($targetStatusCode === IncidentStatusCode::FalsePositive) {
                $updates['false_positive_at'] = $now;
            }
            if ($targetStatusCode === IncidentStatusCode::Cancelled) {
                $updates['cancelled_at'] = $now;
            }

            $incident->update($updates);

            $this->appendTimelineEntry->execute(
                incident: $incident,
                entryType: TimelineEntryType::Resolved,
                actorType: $resolvedByType === IncidentCreatorType::User ? TimelineActorType::User : TimelineActorType::System,
                actorId: $resolvedById,
                title: 'Incidente resuelto: '.$resolutionCode->value,
                description: $summary,
                payload: [
                    'resolution_code' => $resolutionCode->value,
                    'resolution_id' => $resolution->id,
                ],
            );

            if ($targetStatusCode === IncidentStatusCode::Closed) {
                $this->appendTimelineEntry->execute(
                    incident: $incident,
                    entryType: TimelineEntryType::Closed,
                    actorType: $resolvedByType === IncidentCreatorType::User ? TimelineActorType::User : TimelineActorType::System,
                    actorId: $resolvedById,
                    title: 'Incidente cerrado',
                );
            }

            $fresh = $incident->fresh(['status', 'priority']);

            IncidentStatusChanged::dispatch($fresh, $previousStatusCode, $targetStatusCode->value);
            IncidentResolved::dispatch($fresh, $resolution);

            if ($targetStatusCode === IncidentStatusCode::Closed) {
                IncidentClosed::dispatch($fresh);
            }

            broadcast(IncidentUpdatedBroadcast::fromModel($fresh));

            return $resolution;
        });
    }
}
