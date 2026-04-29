<?php

namespace App\Domains\Incidents\Actions;

use App\Domains\Incidents\Enums\TimelineActorType;
use App\Domains\Incidents\Enums\TimelineEntryType;
use App\Domains\Incidents\Models\Incident;
use App\Domains\Incidents\Models\IncidentTimeline;
use DateTimeInterface;

class AppendTimelineEntry
{
    /**
     * @param  array<string, mixed>|null  $payload
     */
    public function execute(
        Incident $incident,
        TimelineEntryType $entryType,
        TimelineActorType $actorType,
        string $title,
        ?string $description = null,
        ?array $payload = null,
        ?int $actorId = null,
        ?DateTimeInterface $occurredAt = null,
    ): IncidentTimeline {
        return IncidentTimeline::query()->create([
            'incident_id' => $incident->id,
            'entry_type' => $entryType,
            'actor_type' => $actorType,
            'actor_id' => $actorId,
            'title' => $title,
            'description' => $description,
            'payload_json' => $payload,
            'occurred_at' => $occurredAt ?? now(),
        ]);
    }
}
