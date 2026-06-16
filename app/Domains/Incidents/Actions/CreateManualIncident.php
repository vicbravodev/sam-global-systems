<?php

namespace App\Domains\Incidents\Actions;

use App\Domains\Incidents\Enums\IncidentCreatorType;
use App\Domains\Incidents\Enums\IncidentSourceType;
use App\Domains\Incidents\Enums\IncidentStatusCode;
use App\Domains\Incidents\Enums\TimelineActorType;
use App\Domains\Incidents\Enums\TimelineEntryType;
use App\Domains\Incidents\Events\IncidentCreated;
use App\Domains\Incidents\Models\Incident;
use App\Domains\Incidents\Models\IncidentPriority;
use App\Domains\Incidents\Models\IncidentStatus;
use App\Domains\Incidents\Models\IncidentType;
use App\Domains\Incidents\Support\IncidentCreatedBroadcast;
use App\Domains\Tenancy\Actions\RecordUsageEvent;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class CreateManualIncident
{
    public function __construct(
        private readonly AppendTimelineEntry $appendTimelineEntry,
        private readonly RecordUsageEvent $recordUsageEvent,
    ) {}

    /**
     * @param  array<string, mixed>  $data
     */
    public function execute(int $teamId, User $creator, array $data): Incident
    {
        return DB::transaction(function () use ($teamId, $creator, $data) {
            $type = IncidentType::query()->findOrFail($data['incident_type_id']);
            $priority = isset($data['incident_priority_id'])
                ? IncidentPriority::query()->findOrFail($data['incident_priority_id'])
                : ($type->default_priority_id !== null
                    ? IncidentPriority::query()->findOrFail($type->default_priority_id)
                    : IncidentPriority::query()->orderBy('level')->firstOrFail());
            $openStatus = IncidentStatus::query()
                ->where('code', IncidentStatusCode::Open->value)
                ->firstOrFail();

            $incident = Incident::query()->create([
                'team_id' => $teamId,
                'incident_type_id' => $type->id,
                'incident_status_id' => $openStatus->id,
                'incident_priority_id' => $priority->id,
                'source_type' => IncidentSourceType::Manual,
                'source_reference_id' => null,
                'related_event_id' => null,
                'related_decision_id' => null,
                'asset_id' => $data['asset_id'] ?? null,
                'driver_id' => $data['driver_id'] ?? null,
                'title' => $data['title'],
                'summary' => $data['summary'],
                'description' => $data['description'] ?? null,
                'opened_at' => now(),
                'created_by_type' => IncidentCreatorType::User,
                'created_by_id' => $creator->id,
                'metadata_json' => $data['metadata'] ?? null,
            ]);

            $this->appendTimelineEntry->execute(
                incident: $incident,
                entryType: TimelineEntryType::Created,
                actorType: TimelineActorType::User,
                actorId: $creator->id,
                title: 'Incidente creado manualmente por '.Str::limit((string) $creator->name, 60),
                payload: [
                    'source_type' => IncidentSourceType::Manual->value,
                    'creator_id' => $creator->id,
                ],
            );

            $this->recordUsageEvent->execute(
                teamId: $teamId,
                meterCode: 'incident_workflows',
                quantity: 1,
                eventKey: 'incident_workflows:'.$incident->id,
                metadata: [
                    'incident_id' => $incident->id,
                    'source_type' => IncidentSourceType::Manual->value,
                ],
            );

            $fresh = $incident->fresh(['type', 'status', 'priority']);

            IncidentCreated::dispatch($fresh);
            broadcast(IncidentCreatedBroadcast::fromModel($fresh));

            return $fresh;
        });
    }
}
