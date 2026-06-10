<?php

namespace App\Domains\Incidents\Listeners;

use App\Domains\AI\Events\MediaAssessmentCompleted;
use App\Domains\Incidents\Actions\AppendTimelineEntry;
use App\Domains\Incidents\Enums\TimelineActorType;
use App\Domains\Incidents\Enums\TimelineEntryType;
use App\Domains\Incidents\Models\Incident;
use App\Domains\Incidents\Support\IncidentUpdatedBroadcast;

/**
 * Surfaces what the AI saw in the footage on the incident itself (Roadmap B8):
 * each fresh media assessment becomes a timeline entry, and the inbox is told
 * to refresh. Terminal incidents are still annotated — the assessment is part
 * of the historical record — but the decision engine re-run is gated elsewhere.
 */
class AnnotateIncidentOnMediaAssessmentCompleted
{
    public function __construct(
        private readonly AppendTimelineEntry $appendTimelineEntry,
    ) {}

    public function handle(MediaAssessmentCompleted $event): void
    {
        $incident = $this->resolveIncident($event);

        if ($incident === null) {
            return;
        }

        foreach ($event->assessments as $assessment) {
            $this->appendTimelineEntry->execute(
                incident: $incident,
                entryType: TimelineEntryType::MediaAssessed,
                actorType: TimelineActorType::Ai,
                title: 'Media assessed: '.($assessment->result?->value ?? 'unknown'),
                description: $assessment->summary_text,
                payload: [
                    'assessment_id' => $assessment->id,
                    'event_media_context_id' => $assessment->event_media_context_id,
                    'media_type' => $assessment->media_type?->value,
                    'result' => $assessment->result?->value,
                    'confidence_score' => $assessment->confidence_score,
                ],
                occurredAt: $assessment->assessed_at,
            );
        }

        broadcast(IncidentUpdatedBroadcast::fromModel($incident));
    }

    private function resolveIncident(MediaAssessmentCompleted $event): ?Incident
    {
        $normalizedEventId = $event->evaluation->normalized_event_id;

        if ($normalizedEventId === null) {
            return null;
        }

        $incident = Incident::withoutGlobalScopes()
            ->where('related_event_id', $normalizedEventId)
            ->orderByDesc('id')
            ->first();

        if ($incident !== null) {
            return $incident;
        }

        // Deduped events get linked to an existing open incident instead of
        // spawning their own — follow the link.
        return Incident::withoutGlobalScopes()
            ->whereHas('eventLinks', fn ($query) => $query->where('normalized_event_id', $normalizedEventId))
            ->orderByDesc('id')
            ->first();
    }
}
