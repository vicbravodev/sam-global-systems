<?php

namespace App\Http\Controllers\Normalization;

use App\Contracts\ObjectStorage;
use App\Domains\AI\Models\AIEventEvaluation;
use App\Domains\Context\Models\EventMediaContext;
use App\Domains\Decisions\Enums\DecisionOutcomeCode;
use App\Domains\Decisions\Models\Decision;
use App\Domains\Incidents\Models\Incident;
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

        return Inertia::render('events/index', [
            'events' => collect($paginator->items())
                ->map(fn (NormalizedEvent $event) => $this->toRow($event))
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
        ]);
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
        return [
            'id' => (int) $event->id,
            'occurredAt' => $event->occurred_at?->toIso8601String(),
            'status' => $event->status?->value,
            'eventType' => $event->eventType?->name,
            'eventTypeCode' => $event->eventType?->code,
            'category' => $event->eventCategory?->name,
            'severity' => $event->eventSeverity?->code,
            'severityLabel' => $event->eventSeverity?->label,
            'severityColor' => $event->eventSeverity?->color,
            'asset' => $event->asset?->name,
            'driver' => $event->driver?->full_name,
            'provider' => $event->provider?->name,
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
            'context' => $event->context_json,
            'rawPayload' => $event->rawEvent?->payload_json,
            'rawEventId' => $event->raw_event_id !== null ? (int) $event->raw_event_id : null,
        ];
    }

    /**
     * @return array<string, mixed>|null
     */
    private function evaluation(NormalizedEvent $event): ?array
    {
        $evaluation = AIEventEvaluation::withoutGlobalScopes()
            ->where('normalized_event_id', $event->id)
            ->orderByDesc('evaluation_version')
            ->first();

        if ($evaluation === null) {
            return null;
        }

        return [
            'id' => (int) $evaluation->id,
            'version' => (int) $evaluation->evaluation_version,
            'classification' => $evaluation->classification?->value,
            'classificationLabel' => $evaluation->classification?->label(),
            'confidenceScore' => $evaluation->confidence_score !== null ? (float) $evaluation->confidence_score : null,
            'riskScore' => $evaluation->risk_score !== null ? (float) $evaluation->risk_score : null,
            'priorityLevel' => $evaluation->priority_level?->value,
            'mode' => $evaluation->evaluation_mode?->value,
        ];
    }

    /**
     * @return array<string, mixed>|null
     */
    private function decision(NormalizedEvent $event): ?array
    {
        $decision = Decision::withoutGlobalScopes()
            ->where('normalized_event_id', $event->id)
            ->orderByDesc('id')
            ->first();

        if ($decision === null) {
            return null;
        }

        return [
            'id' => (int) $decision->id,
            'code' => $decision->decision_code,
            'outcomeLabel' => $decision->decision_code !== null
                ? (DecisionOutcomeCode::tryFrom($decision->decision_code)?->label() ?? $decision->decision_code)
                : null,
            'reason' => $decision->decision_reason,
            'requiresHumanReview' => (bool) $decision->requires_human_review,
            'decidedAt' => $decision->decided_at?->toIso8601String(),
        ];
    }

    /**
     * @return array<string, mixed>|null
     */
    private function incident(NormalizedEvent $event): ?array
    {
        $incident = Incident::withoutGlobalScopes()
            ->where('related_event_id', $event->id)
            ->orderByDesc('id')
            ->with(['status', 'priority'])
            ->first();

        if ($incident === null) {
            return null;
        }

        return [
            'id' => (int) $incident->id,
            'title' => (string) $incident->title,
            'status' => $incident->status?->code,
            'severity' => $incident->priority?->code,
        ];
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function mediaItems(NormalizedEvent $event): array
    {
        $storage = app(ObjectStorage::class);

        return EventMediaContext::query()
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
                    'url' => $url,
                    'thumbnailUrl' => $media->thumbnail_url,
                    'capturedAt' => $media->captured_at?->toIso8601String(),
                ];
            })
            ->all();
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
                ->map(fn (EventSeverity $severity) => ['value' => (string) $severity->id, 'label' => (string) ($severity->label ?? $severity->code)])
                ->all(),
            'statuses' => array_map(
                fn (NormalizedEventStatus $status) => ['value' => $status->value, 'label' => self::STATUS_LABELS[$status->value] ?? $status->value],
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
