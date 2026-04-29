<?php

namespace App\Domains\Incidents\Actions;

use App\Domains\Incidents\Enums\EventRelationType;
use App\Domains\Incidents\Enums\TimelineActorType;
use App\Domains\Incidents\Enums\TimelineEntryType;
use App\Domains\Incidents\Models\Incident;
use App\Domains\Incidents\Models\IncidentEventLink;
use App\Domains\Normalization\Models\NormalizedEvent;

class LinkEventToIncident
{
    public function __construct(
        private readonly AppendTimelineEntry $appendTimelineEntry,
    ) {}

    public function execute(
        Incident $incident,
        NormalizedEvent $event,
        EventRelationType $relationType,
    ): IncidentEventLink {
        $link = IncidentEventLink::query()->firstOrCreate(
            [
                'incident_id' => $incident->id,
                'normalized_event_id' => $event->id,
            ],
            [
                'relation_type' => $relationType,
            ],
        );

        if ($link->wasRecentlyCreated) {
            $this->appendTimelineEntry->execute(
                incident: $incident,
                entryType: TimelineEntryType::EventLinked,
                actorType: TimelineActorType::System,
                title: "Event #{$event->id} linked",
                description: null,
                payload: [
                    'normalized_event_id' => $event->id,
                    'relation_type' => $relationType->value,
                ],
            );
        }

        return $link;
    }
}
