<?php

namespace App\Domains\Incidents\Actions;

use App\Domains\AI\Models\AIEventEvaluation;
use App\Domains\Context\Models\EventContextSnapshot;
use App\Domains\Incidents\Enums\EventRelationType;
use App\Domains\Incidents\Enums\EvidenceSourceType;
use App\Domains\Incidents\Enums\EvidenceType;
use App\Domains\Incidents\Enums\IncidentCreatorType;
use App\Domains\Incidents\Enums\IncidentSourceType;
use App\Domains\Incidents\Enums\IncidentStatusCode;
use App\Domains\Incidents\Enums\TimelineActorType;
use App\Domains\Incidents\Enums\TimelineEntryType;
use App\Domains\Incidents\Events\IncidentCreated;
use App\Domains\Incidents\Jobs\CheckIncidentAcknowledgementJob;
use App\Domains\Incidents\Models\Incident;
use App\Domains\Incidents\Models\IncidentPriority;
use App\Domains\Incidents\Models\IncidentStatus;
use App\Domains\Incidents\Models\IncidentType;
use App\Domains\Incidents\Support\IncidentCreatedBroadcast;
use App\Domains\Normalization\Models\NormalizedEvent;
use App\Domains\Tenancy\Actions\RecordUsageEvent;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

class CreateIncidentFromEvent
{
    public function __construct(
        private readonly AppendTimelineEntry $appendTimelineEntry,
        private readonly LinkEventToIncident $linkEventToIncident,
        private readonly AddIncidentEvidence $addIncidentEvidence,
        private readonly RecordUsageEvent $recordUsageEvent,
        private readonly ApplyExternalResolution $applyExternalResolution,
    ) {}

    /**
     * Creates a new incident triggered by a normalized event. If an open incident already exists
     * for the same asset/driver inside the dedup window, links the event to that incident
     * instead of creating a new one.
     *
     * @param  array<string, mixed>  $context  Optional payload with `decision_id`, `priority_code`, `incident_type_code`, `title`, `summary`.
     */
    public function execute(NormalizedEvent $event, array $context = []): Incident
    {
        return DB::transaction(function () use ($event, $context) {
            $teamId = (int) $event->team_id;
            $existing = $this->findOpenDuplicate($event);

            if ($existing !== null) {
                $this->linkEventToIncident->execute(
                    $existing,
                    $event,
                    EventRelationType::SupportingEvent,
                );

                return $existing;
            }

            $incidentType = $this->resolveIncidentType($context['incident_type_code'] ?? null, $event);
            $priority = $this->resolvePriority($context['priority_code'] ?? null, $incidentType);
            $openStatus = IncidentStatus::query()->where('code', IncidentStatusCode::Open->value)->firstOrFail();

            $sourceType = isset($context['decision_id'])
                ? IncidentSourceType::AiDecision
                : IncidentSourceType::NormalizedEvent;

            $title = $context['title'] ?? $this->buildTitle($event, $incidentType->name);
            $summary = $context['summary'] ?? $this->buildSummary($event);

            $openedAt = Carbon::instance($event->occurred_at ?? now());
            $slaDueAt = $priority->sla_seconds !== null
                ? $openedAt->copy()->addSeconds((int) $priority->sla_seconds)
                : null;

            $incident = Incident::query()->create([
                'team_id' => $teamId,
                'incident_type_id' => $incidentType->id,
                'incident_status_id' => $openStatus->id,
                'incident_priority_id' => $priority->id,
                'source_type' => $sourceType,
                'source_reference_id' => $context['decision_id'] ?? $event->id,
                'related_event_id' => $event->id,
                'related_decision_id' => $context['decision_id'] ?? null,
                'asset_id' => $event->asset_id,
                'driver_id' => $event->driver_id,
                'title' => $title,
                'summary' => $summary,
                'opened_at' => $openedAt,
                'sla_due_at' => $slaDueAt,
                'created_by_type' => IncidentCreatorType::System,
                'metadata_json' => $context['metadata'] ?? null,
            ]);

            // SLA watchdog: one delayed job instead of a per-minute cron. It
            // no-ops if the incident was acknowledged or closed by then.
            if ($slaDueAt !== null) {
                CheckIncidentAcknowledgementJob::dispatch($incident->id)
                    ->delay($slaDueAt)
                    ->afterCommit();
            }

            $this->appendTimelineEntry->execute(
                incident: $incident,
                entryType: TimelineEntryType::Created,
                actorType: TimelineActorType::System,
                title: 'Incident created',
                payload: [
                    'source_type' => $sourceType->value,
                    'normalized_event_id' => $event->id,
                    'decision_id' => $context['decision_id'] ?? null,
                ],
                occurredAt: $incident->opened_at,
            );

            $this->linkEventToIncident->execute(
                $incident,
                $event,
                EventRelationType::RootTrigger,
            );

            $this->autoAttachEvidence($incident, $event);

            // An event that arrives already resolved at the provider still opens
            // its incident (a cancelled panic can be coercion) — annotate only,
            // never auto-close on creation regardless of the tenant setting.
            if (($event->payload_normalized_json['is_resolved'] ?? null) === true) {
                $this->applyExternalResolution->execute($incident, $event, allowClose: false);
            }

            $this->recordUsageEvent->execute(
                teamId: $teamId,
                meterCode: 'incident_workflows',
                quantity: 1,
                eventKey: 'incident_workflows:'.$incident->id,
                metadata: [
                    'incident_id' => $incident->id,
                    'source_type' => $sourceType->value,
                    'normalized_event_id' => $event->id,
                ],
            );

            $fresh = $incident->fresh(['type', 'status', 'priority']);

            IncidentCreated::dispatch($fresh);
            broadcast(IncidentCreatedBroadcast::fromModel($fresh));

            return $fresh;
        });
    }

    private function findOpenDuplicate(NormalizedEvent $event): ?Incident
    {
        if ($event->asset_id === null && $event->driver_id === null) {
            return null;
        }

        $window = (int) config('incidents.duplicate_window_minutes', 30);
        $occurredAt = $event->occurred_at ?? now();
        $threshold = Carbon::instance($occurredAt)->subMinutes($window);

        return Incident::query()
            ->where('team_id', $event->team_id)
            ->whereHas('status', fn ($q) => $q->where('is_terminal', false))
            ->where('opened_at', '>=', $threshold)
            ->where(function ($q) use ($event) {
                if ($event->asset_id !== null) {
                    $q->orWhere('asset_id', $event->asset_id);
                }
                if ($event->driver_id !== null) {
                    $q->orWhere('driver_id', $event->driver_id);
                }
            })
            ->orderByDesc('opened_at')
            ->first();
    }

    private function resolveIncidentType(?string $code, NormalizedEvent $event): IncidentType
    {
        if ($code !== null) {
            $type = IncidentType::query()->where('code', $code)->where('is_active', true)->first();
            if ($type !== null) {
                return $type;
            }
        }

        $eventTypeCode = $event->relationLoaded('eventType')
            ? $event->eventType?->code
            : $event->eventType()->first()?->code;

        if ($eventTypeCode !== null) {
            $type = IncidentType::query()->where('code', $eventTypeCode)->where('is_active', true)->first();
            if ($type !== null) {
                return $type;
            }
        }

        return IncidentType::query()->where('is_active', true)->orderBy('id')->firstOrFail();
    }

    private function resolvePriority(?string $code, IncidentType $type): IncidentPriority
    {
        if ($code !== null) {
            $priority = IncidentPriority::query()->where('code', $code)->first();
            if ($priority !== null) {
                return $priority;
            }
        }

        if ($type->default_priority_id !== null) {
            $priority = IncidentPriority::query()->find($type->default_priority_id);
            if ($priority !== null) {
                return $priority;
            }
        }

        return IncidentPriority::query()->orderBy('level')->firstOrFail();
    }

    private function buildTitle(NormalizedEvent $event, string $typeName): string
    {
        $assetSegment = $event->asset_id !== null ? " - asset #{$event->asset_id}" : '';

        return $typeName.$assetSegment;
    }

    private function buildSummary(NormalizedEvent $event): string
    {
        $occurredAt = $event->occurred_at?->toIso8601String() ?? now()->toIso8601String();

        return "Auto-created from normalized event #{$event->id} occurred at {$occurredAt}.";
    }

    private function autoAttachEvidence(Incident $incident, NormalizedEvent $event): void
    {
        $contextSnapshot = EventContextSnapshot::withoutGlobalScopes()
            ->where('normalized_event_id', $event->id)
            ->first();

        if ($contextSnapshot !== null) {
            $this->addIncidentEvidence->execute(
                incident: $incident,
                evidenceType: EvidenceType::EventSnapshot,
                sourceType: EvidenceSourceType::EventContext,
                sourceReferenceId: (int) $contextSnapshot->id,
                title: 'Event context snapshot',
                metadata: [
                    'context_version' => $contextSnapshot->context_version,
                    'snapshot_id' => $contextSnapshot->id,
                ],
            );
        }

        $aiEvaluation = AIEventEvaluation::withoutGlobalScopes()
            ->where('normalized_event_id', $event->id)
            ->latest('id')
            ->first();

        if ($aiEvaluation !== null) {
            $this->addIncidentEvidence->execute(
                incident: $incident,
                evidenceType: EvidenceType::AiExplanation,
                sourceType: EvidenceSourceType::AiEvaluation,
                sourceReferenceId: (int) $aiEvaluation->id,
                title: 'AI evaluation explanation',
                description: $aiEvaluation->explanation_text,
                metadata: [
                    'evaluation_id' => $aiEvaluation->id,
                    'classification' => $aiEvaluation->classification?->value,
                    'priority_level' => $aiEvaluation->priority_level?->value,
                ],
            );
        }
    }
}
