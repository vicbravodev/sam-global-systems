<?php

namespace App\Domains\Incidents\Jobs;

use App\Domains\Incidents\Actions\AppendTimelineEntry;
use App\Domains\Incidents\Enums\TimelineActorType;
use App\Domains\Incidents\Enums\TimelineEntryType;
use App\Domains\Incidents\Events\IncidentStatusChanged;
use App\Domains\Incidents\Models\Incident;
use App\Domains\Incidents\Models\IncidentPriority;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

class UpdateIncidentPriorityJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 2;

    public function __construct(
        public readonly int $incidentId,
        public readonly int $newPriorityId,
    ) {
        $this->onQueue('incidents');
    }

    public function handle(AppendTimelineEntry $appendTimelineEntry): void
    {
        $incident = Incident::withoutGlobalScopes()->find($this->incidentId);

        if ($incident === null) {
            return;
        }

        $newPriority = IncidentPriority::query()->find($this->newPriorityId);

        if ($newPriority === null) {
            return;
        }

        $previousPriorityId = $incident->incident_priority_id;
        $previousPriority = IncidentPriority::query()->find($previousPriorityId);

        $incident->update(['incident_priority_id' => $newPriority->id]);

        $appendTimelineEntry->execute(
            incident: $incident,
            entryType: TimelineEntryType::PriorityChanged,
            actorType: TimelineActorType::System,
            title: 'Priority changed',
            payload: [
                'previous_priority_id' => $previousPriorityId,
                'new_priority_id' => $newPriority->id,
                'previous_level' => $previousPriority?->level,
                'new_level' => $newPriority->level,
            ],
        );

        if ($previousPriority !== null && (int) $previousPriority->level < (int) $newPriority->level) {
            IncidentStatusChanged::dispatch(
                $incident->fresh(['status', 'priority']),
                $previousPriority->code,
                $newPriority->code,
            );
        }
    }
}
