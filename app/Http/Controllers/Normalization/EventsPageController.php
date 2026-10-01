<?php

namespace App\Http\Controllers\Normalization;

use App\Contracts\ObjectStorage;
use App\Domains\AI\Models\AIEventEvaluation;
use App\Domains\AI\Support\PlaceholderEvaluation;
use App\Domains\Context\Models\EventContextSnapshot;
use App\Domains\Context\Models\EventMediaContext;
use App\Domains\Decisions\Enums\DecisionOutcomeCode;
use App\Domains\Decisions\Models\Decision;
use App\Domains\Incidents\Models\Incident;
use App\Domains\Incidents\Support\IncidentStatusPresenter;
use App\Domains\Normalization\Enums\NormalizedEventStatus;
use App\Domains\Normalization\Models\EventCategory;
use App\Domains\Normalization\Models\EventSeverity;
use App\Domains\Normalization\Models\EventType;
use App\Domains\Normalization\Models\NormalizedEvent;
use App\Http\Controllers\Controller;
use App\Models\Team;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Web browser for normalized events (Roadmap F10): table with filters, the
 * "unmapped" view (events no mapping rule caught), and a detail page linking
 * payload, media, AI evaluation, decision and incident.
 */
class EventsPageController extends Controller
{
    private const PER_PAGE = 50;

    /**
     * `event_severities.level` from which an event counts as severe (high=3,
     * critical=4 in the seeded catalog).
     */
    private const SEVERE_LEVEL = 3;

    public function index(Request $request, Team $current_team): Response
    {
        $this->authorize('viewAny', NormalizedEvent::class);

        $filters = $this->filters($request);

        $query = NormalizedEvent::query()
            ->where('team_id', $current_team->id)
            ->with(['eventType', 'eventCategory', 'eventSeverity', 'asset', 'driver', 'provider']);

        $this->applyFilters($query, $filters);

        $paginator = $query
            ->orderByDesc('occurred_at')
            ->orderByDesc('id')
            ->paginate(self::PER_PAGE)
            ->withQueryString();

        $events = collect($paginator->items());
        $ids = $events->pluck('id')->all();

        // Pipeline outcome per row, resolved in two batched lookups instead of
        // one query per event: which events already got an AI verdict and
        // which ones opened an incident.
        $evaluated = $ids === [] ? [] : AIEventEvaluation::query()
            ->where('team_id', $current_team->id)
            ->whereIn('normalized_event_id', $ids)
            ->distinct()
            ->pluck('normalized_event_id')
            ->flip()
            ->all();
        $withIncident = $ids === [] ? [] : Incident::query()
            ->where('team_id', $current_team->id)
            ->whereIn('related_event_id', $ids)
            ->distinct()
            ->pluck('related_event_id')
            ->flip()
            ->all();

        return Inertia::render('events/index', [
            'events' => $events
                ->map(fn (NormalizedEvent $event) => $this->toRow($event) + [
                    'hasEvaluation' => isset($evaluated[$event->id]),
                    'hasIncident' => isset($withIncident[$event->id]),
                ])
                ->all(),
            'pagination' => [
                'page' => $paginator->currentPage(),
                'perPage' => $paginator->perPage(),
                'total' => $paginator->total(),
                'lastPage' => $paginator->lastPage(),
            ],
            'filters' => $filters,
            'filterOptions' => fn () => $this->filterOptions(),
            'unmappedCount' => fn () => NormalizedEvent::query()
                ->where('team_id', $current_team->id)
                ->where('status', NormalizedEventStatus::Unmapped)
                ->count(),
            'summary' => fn () => $this->summary($current_team),
        ]);
    }

    /**
     * Tenant-wide pulse of the last 24 h for the header strip: events
     * received, how many were high/critical, how many opened an incident,
     * plus the backlog that needs attention (unmapped, failed). Ignores the
     * active filters on purpose.
     *
     * @return array{last24h: int, severe24h: int, incidents24h: int, unmapped: int, failed: int}
     */
    private function summary(Team $team): array
    {
        $since = now()->subDay();
        $events = fn (): Builder => NormalizedEvent::query()->where('team_id', $team->id);

        return [
            'last24h' => $events()->where('occurred_at', '>=', $since)->count(),
            'severe24h' => $events()
                ->where('occurred_at', '>=', $since)
                ->whereHas('eventSeverity', fn (Builder $q) => $q->where('level', '>=', self::SEVERE_LEVEL))
                ->count(),
            'incidents24h' => Incident::query()
                ->where('team_id', $team->id)
                ->whereNotNull('related_event_id')
                ->where('opened_at', '>=', $since)
                ->count(),
            'unmapped' => $events()->where('status', NormalizedEventStatus::Unmapped)->count(),
            'failed' => $events()->where('status', NormalizedEventStatus::Failed)->count(),
        ];
    }

    public function show(Team $current_team, NormalizedEvent $normalizedEvent): Response
    {
        $this->authorize('view', $normalizedEvent);

        abort_if($normalizedEvent->team_id !== $current_team->id, 404);

        $normalizedEvent->load([
            'rawEvent',
            'eventType',
            'eventCategory',
            'eventSeverity',
            'asset',
            'driver',
            'provider',
        ]);

        return Inertia::render('events/show', [
            'event' => $this->toDetail($normalizedEvent),
            'evaluation' => fn () => $this->evaluation($normalizedEvent),
            'decision' => fn () => $this->decision($normalizedEvent),
            'incident' => fn () => $this->incident($normalizedEvent),
            'media' => fn () => $this->mediaItems($normalizedEvent),
        ]);
    }

    /**
     * @return array{q: string|null, status: string|null, event_type_id: int|null, event_category_id: int|null, event_severity_id: int|null, occurred_from: string|null, occurred_until: string|null}
     */
    private function filters(Request $request): array
    {
        $status = $request->filled('status')
            ? NormalizedEventStatus::tryFrom($request->string('status')->toString())?->value
            : null;

        return [
            'q' => $request->filled('q') ? $request->string('q')->trim()->toString() : null,
            'status' => $status,
            'event_type_id' => $request->filled('event_type_id') ? $request->integer('event_type_id') : null,
            'event_category_id' => $request->filled('event_category_id') ? $request->integer('event_category_id') : null,
            'event_severity_id' => $request->filled('event_severity_id') ? $request->integer('event_severity_id') : null,
            'occurred_from' => $request->filled('occurred_from') ? $request->string('occurred_from')->toString() : null,
            'occurred_until' => $request->filled('occurred_until') ? $request->string('occurred_until')->toString() : null,
        ];
    }

    /**
     * @param  Builder<NormalizedEvent>  $query
     * @param  array{q: string|null, status: string|null, event_type_id: int|null, event_category_id: int|null, event_severity_id: int|null, occurred_from: string|null, occurred_until: string|null}  $filters
     */
    private function applyFilters(Builder $query, array $filters): void
    {
        if ($filters['q'] !== null && $filters['q'] !== '') {
            $term = '%'.mb_strtolower(str_replace(['%', '_'], ['\%', '\_'], $filters['q'])).'%';
            $query->where(fn (Builder $q) => $q
                ->whereHas('asset', fn (Builder $a) => $a->whereRaw('LOWER(name) LIKE ?', [$term]))
                ->orWhereHas('eventType', fn (Builder $t) => $t->whereRaw('LOWER(name) LIKE ?', [$term])
                    ->orWhereRaw('LOWER(code) LIKE ?', [$term])));
        }

        if ($filters['status'] !== null) {
            $query->where('status', $filters['status']);
        }

        foreach (['event_type_id', 'event_category_id', 'event_severity_id'] as $column) {
            if ($filters[$column] !== null) {
                $query->where($column, $filters[$column]);
            }
        }

        if ($filters['occurred_from'] !== null) {
            $query->where('occurred_at', '>=', $filters['occurred_from']);
        }

        if ($filters['occurred_until'] !== null) {
            $query->where('occurred_at', '<=', $filters['occurred_until'].' 23:59:59');
        }
    }

    /**
     * @return array<string, mixed>
     */
    private function toRow(NormalizedEvent $event): array
    {
        $payload = $event->payload_normalized_json;
        $description = $payload['description'] ?? null;

        return [
            'id' => (int) $event->id,
            'occurredAt' => $event->occurred_at?->toIso8601String(),
            'status' => $event->status->value,
            'statusLabel' => self::STATUS_LABELS[$event->status->value],
            'eventType' => $event->eventType?->name,
            'eventTypeCode' => $event->eventType?->code,
            'category' => $event->eventCategory?->name,
            'categoryCode' => $event->eventCategory?->code,
            'severity' => $event->eventSeverity?->code,
            'severityLabel' => $event->eventSeverity?->label,
            'severityColor' => $event->eventSeverity?->color,
            'asset' => $event->asset?->name,
            'assetId' => $event->asset_id !== null ? (int) $event->asset_id : null,
            'driver' => $event->driver?->full_name,
            'driverId' => $event->driver_id !== null ? (int) $event->driver_id : null,
            'provider' => $event->provider?->name,
            // Provider-side description when it says more than the type name
            // (e.g. the Samsara behavior label). Null when it merely repeats it.
            'description' => is_string($description)
                && $description !== ''
                && $description !== $event->eventType?->name
                && $description !== $event->eventType?->code
                ? $description
                : null,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function toDetail(NormalizedEvent $event): array
    {
        return $this->toRow($event) + [
            'processedAt' => $event->processed_at?->toIso8601String(),
            'payload' => $event->payload_normalized_json,
            'context' => $this->context($event),
            'rawPayload' => $event->rawEvent?->payload_json,
            'rawEventId' => (int) $event->raw_event_id,
            'facts' => $this->facts($event),
        ];
    }

    /**
     * Operational context the enricher captured for this event. It lives in
     * `event_context_snapshots` (one row per event); `normalized_events.
     * context_json` is never written by the pipeline, so reading it always
     * showed an empty block.
     *
     * @return array<string, mixed>|null
     */
    private function context(NormalizedEvent $event): ?array
    {
        $snapshot = EventContextSnapshot::query()
            ->where('team_id', $event->team_id)
            ->where('normalized_event_id', $event->id)
            ->first();

        if ($snapshot === null) {
            return null;
        }

        $context = array_filter([
            'location' => $snapshot->location_snapshot_json,
            'asset' => $snapshot->asset_snapshot_json,
            'driver' => $snapshot->driver_snapshot_json,
            'telemetry' => $snapshot->telemetry_snapshot_json,
            'geofences' => $snapshot->geofence_snapshot_json,
            'incidents' => $snapshot->incidents_snapshot_json,
            'recentHistory' => $snapshot->recent_history_snapshot_json,
            'media' => $snapshot->media_snapshot_json,
            'signals' => $snapshot->signals_json,
        ], fn ($value) => $value !== null && $value !== []);

        return $context === [] ? null : ['version' => (int) $snapshot->context_version] + $context;
    }

    /**
     * Operator-readable facts lifted out of the normalized payload (the shape
     * NormalizeRawEvent::buildNormalizedPayload writes): where it happened,
     * what the provider labelled it, whether it is already resolved at the
     * source and the link back to the provider's own incident page.
     *
     * @return array{location: array{latitude: float, longitude: float, formatted: string|null}|null, labels: list<string>, externalEventType: string|null, externalUrl: string|null, isResolved: bool|null, externalResolvedAt: string|null, eventState: string|null}
     */
    private function facts(NormalizedEvent $event): array
    {
        $payload = $event->payload_normalized_json;

        $location = null;
        $rawLocation = $payload['location'] ?? null;

        if (is_array($rawLocation) && isset($rawLocation['latitude'], $rawLocation['longitude'])
            && is_numeric($rawLocation['latitude']) && is_numeric($rawLocation['longitude'])) {
            $formatted = $rawLocation['formattedLocation']
                ?? $rawLocation['formatted_location']
                ?? $rawLocation['address']
                ?? null;

            $location = [
                'latitude' => (float) $rawLocation['latitude'],
                'longitude' => (float) $rawLocation['longitude'],
                'formatted' => is_string($formatted) ? $formatted : null,
            ];
        }

        $labels = [];

        foreach (is_array($payload['raw_behavior_labels'] ?? null) ? $payload['raw_behavior_labels'] : [] as $label) {
            $name = is_array($label) ? ($label['name'] ?? $label['label'] ?? null) : $label;

            if (is_string($name) && $name !== '') {
                $labels[] = $name;
            }
        }

        $externalUrl = $payload['incident_url'] ?? null;
        $externalType = $payload['external_event_type'] ?? null;
        $eventState = $payload['event_state'] ?? null;
        $resolvedAt = $payload['external_resolved_at'] ?? null;

        return [
            'location' => $location,
            'labels' => array_values(array_unique($labels)),
            'externalEventType' => is_string($externalType) ? $externalType : null,
            'externalUrl' => is_string($externalUrl) && str_starts_with($externalUrl, 'https://') ? $externalUrl : null,
            'isResolved' => is_bool($payload['is_resolved'] ?? null) ? $payload['is_resolved'] : null,
            'externalResolvedAt' => is_string($resolvedAt) ? $resolvedAt : null,
            'eventState' => is_string($eventState) ? $eventState : null,
        ];
    }

    /**
     * @return array<string, mixed>|null
     */
    private function evaluation(NormalizedEvent $event): ?array
    {
        $evaluation = AIEventEvaluation::query()
            ->where('normalized_event_id', $event->id)
            ->orderByDesc('evaluation_version')
            ->first();

        if ($evaluation === null) {
            return null;
        }

        // The deterministic stand-in agent (`null-agent:*`) always answers
        // "real event, 85%": that is not a verdict, so no scores reach the UI
        // and the card reads "Sin evaluación IA" (UI audit P0-3).
        $isPlaceholder = $evaluation->isPlaceholder();

        if ($isPlaceholder) {
            return [
                'id' => (int) $evaluation->id,
                'version' => (int) $evaluation->evaluation_version,
                'isPlaceholder' => true,
                'placeholderLabel' => PlaceholderEvaluation::LABEL,
                'classification' => null,
                'classificationLabel' => null,
                'confidenceScore' => null,
                'riskScore' => null,
                'priorityLevel' => null,
                'mode' => $evaluation->evaluation_mode?->value,
                'isRealEvent' => null,
                'requiresAction' => false,
                'recommendedAction' => null,
                'explanation' => null,
                'evaluatedAt' => $evaluation->evaluated_at?->toIso8601String(),
            ];
        }

        return [
            'id' => (int) $evaluation->id,
            'version' => (int) $evaluation->evaluation_version,
            'isPlaceholder' => false,
            'placeholderLabel' => null,
            'classification' => $evaluation->classification?->value,
            'classificationLabel' => $evaluation->classification?->label(),
            'confidenceScore' => $evaluation->confidence_score !== null ? (float) $evaluation->confidence_score : null,
            'riskScore' => $evaluation->risk_score !== null ? (float) $evaluation->risk_score : null,
            'priorityLevel' => $evaluation->priority_level?->value,
            'mode' => $evaluation->evaluation_mode?->value,
            'isRealEvent' => $evaluation->is_real_event !== null ? (bool) $evaluation->is_real_event : null,
            'requiresAction' => (bool) $evaluation->requires_action,
            'recommendedAction' => $evaluation->recommended_action,
            'explanation' => $evaluation->explanation_text,
            'evaluatedAt' => $evaluation->evaluated_at?->toIso8601String(),
        ];
    }

    /**
     * @return array<string, mixed>|null
     */
    private function decision(NormalizedEvent $event): ?array
    {
        $decision = Decision::query()
            ->where('normalized_event_id', $event->id)
            ->orderByDesc('id')
            ->first();

        if ($decision === null) {
            return null;
        }

        return [
            'id' => (int) $decision->id,
            'code' => $decision->decision_code,
            'outcomeLabel' => DecisionOutcomeCode::tryFrom($decision->decision_code)?->label() ?? $decision->decision_code,
            'reason' => $decision->decision_reason,
            'requiresHumanReview' => (bool) $decision->requires_human_review,
            'isAutomated' => (bool) $decision->is_automated,
            'priorityLevel' => $decision->priority_level,
            'decidedAt' => $decision->decided_at?->toIso8601String(),
        ];
    }

    /**
     * @return array<string, mixed>|null
     */
    private function incident(NormalizedEvent $event): ?array
    {
        $incident = Incident::query()
            ->where('related_event_id', $event->id)
            ->orderByDesc('id')
            ->with(['status', 'priority', 'currentAssignment'])
            ->first();

        if ($incident === null) {
            return null;
        }

        return [
            'id' => (int) $incident->id,
            'reference' => $incident->reference(),
            'title' => (string) $incident->title,
            'status' => $incident->status?->code,
            'uiStatus' => IncidentStatusPresenter::forIncident($incident),
            'statusLabel' => IncidentStatusPresenter::labelForIncident($incident),
            'severity' => $incident->priority?->code,
            'openedAt' => $incident->opened_at?->toIso8601String(),
        ];
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function mediaItems(NormalizedEvent $event): array
    {
        $storage = app(ObjectStorage::class);

        return array_values(EventMediaContext::query()
            ->where('normalized_event_id', $event->id)
            ->orderByDesc('id')
            ->get()
            ->map(function (EventMediaContext $media) use ($storage): array {
                $url = $media->media_url;

                if ($url === null && $media->storage_path !== null) {
                    try {
                        $url = $storage->temporaryUrl($media->storage_path, now()->addMinutes(30));
                    } catch (\Throwable) {
                        $url = null;
                    }
                }

                return [
                    'id' => (int) $media->id,
                    'mediaType' => $media->media_type?->value,
                    'mediaRole' => $media->media_role?->value,
                    'url' => $url,
                    'thumbnailUrl' => $media->thumbnail_url,
                    'capturedAt' => $media->captured_at?->toIso8601String(),
                    'durationSeconds' => $media->duration_seconds !== null ? (int) $media->duration_seconds : null,
                ];
            })
            ->all());
    }

    /**
     * @return array<string, mixed>
     */
    private function filterOptions(): array
    {
        return [
            'eventTypes' => EventType::query()
                ->orderBy('name')
                ->get(['id', 'name'])
                ->map(fn (EventType $type) => ['value' => (string) $type->id, 'label' => (string) $type->name])
                ->all(),
            'categories' => EventCategory::query()
                ->orderBy('name')
                ->get(['id', 'name'])
                ->map(fn (EventCategory $category) => ['value' => (string) $category->id, 'label' => (string) $category->name])
                ->all(),
            'severities' => EventSeverity::query()
                ->orderBy('level')
                ->get(['id', 'code', 'label'])
                ->map(fn (EventSeverity $severity) => [
                    'value' => (string) $severity->id,
                    'label' => (string) ($severity->label ?? $severity->code),
                    'code' => (string) $severity->code,
                ])
                ->all(),
            'statuses' => array_map(
                fn (NormalizedEventStatus $status) => ['value' => $status->value, 'label' => self::STATUS_LABELS[$status->value]],
                NormalizedEventStatus::cases(),
            ),
        ];
    }

    private const STATUS_LABELS = [
        'normalized' => 'Normalizado',
        'enrichment_pending' => 'Enriquecimiento pendiente',
        'enriched' => 'Enriquecido',
        'failed' => 'Fallido',
        'unmapped' => 'Sin mapear',
    ];
}
