<?php

namespace App\Http\Controllers\Incidents;

use App\Domains\Access\Actions\AuthorizeAction;
use App\Domains\AI\Models\AIEventEvaluation;
use App\Domains\AI\Models\AIMediaAssessment;
use App\Domains\Context\Enums\IncidentRelationType;
use App\Domains\Context\Models\EventMediaContext;
use App\Domains\Context\Models\EventMediaRequest;
use App\Domains\Context\Models\EventRelatedIncidentLink;
use App\Domains\Context\Support\EventMediaGallery;
use App\Domains\Context\Support\MediaRetrievalWindow;
use App\Domains\Incidents\Enums\AssigneeType;
use App\Domains\Incidents\Enums\TimelineActorType;
use App\Domains\Incidents\Models\Incident;
use App\Domains\Incidents\Models\IncidentCallVerification;
use App\Domains\Incidents\Models\IncidentPriority;
use App\Domains\Incidents\Models\IncidentStatus;
use App\Domains\Incidents\Models\IncidentType;
use App\Domains\Incidents\Support\IncidentInboxPresenter;
use App\Domains\Incidents\Support\IncidentStatusPresenter;
use App\Domains\Normalization\Models\NormalizedEvent;
use App\Domains\Notifications\Enums\DeliveryStatus;
use App\Domains\Notifications\Enums\NotificationSourceType;
use App\Domains\Notifications\Models\Notification;
use App\Http\Controllers\Controller;
use App\Models\Team;
use App\Models\User;
use App\Support\TeamMembers;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;
use Inertia\Response;

class IncidentInboxController extends Controller
{
    /**
     * Maximum number of incidents loaded into the inbox in a single page.
     */
    private const INBOX_LIMIT = 200;

    /**
     * How far back "Historial relacionado" looks for incidents of the same
     * asset or driver.
     */
    private const RELATED_WINDOW_DAYS = 30;

    public function __construct(
        private readonly IncidentInboxPresenter $presenter,
    ) {}

    public function index(Request $request, Team $current_team): Response
    {
        $this->authorize('viewAny', Incident::class);

        $filters = $this->filters($request);

        $query = Incident::query()
            ->where('team_id', $current_team->id)
            ->with([
                'type',
                'status',
                'priority',
                'currentAssignment',
                'asset',
                'driver',
                'relatedEvent.provider',
                'relatedEvent.eventType',
                'relatedEvent.eventSeverity',
                'aiEvaluation',
            ]);

        $this->applyFilters($query, $filters);

        /** @var EloquentCollection<int, Incident> $incidents */
        $incidents = $query
            ->orderByDesc('opened_at')
            ->limit(self::INBOX_LIMIT)
            ->get();

        $users = $this->resolveUsers(
            $incidents->map(fn (Incident $incident) => $incident->currentAssignment)
                ->filter(fn ($assignment) => $assignment?->assigned_to_type === AssigneeType::User)
                ->map(fn ($assignment) => (int) $assignment->assigned_to_id)
                // Los que tienen tomado un incidente se resuelven en la misma
                // consulta que los asignados: la bandeja pinta ambos nombres.
                ->concat($incidents->map(fn (Incident $incident) => $incident->claimed_by_user_id)),
            $current_team->id,
        );

        return Inertia::render('incidents/index', [
            'incidents' => $incidents
                ->map(fn (Incident $incident) => $this->presenter->toRow($incident, $users))
                ->all(),
            'filters' => $filters,
            'filterOptions' => fn () => $this->filterOptions($current_team),
            'members' => fn () => $this->members($current_team),
            'reclassifyOptions' => fn () => $this->reclassifyOptions(),
            'can' => $this->abilities($request->user(), $current_team),
        ]);
    }

    /**
     * Acciones que el rol del usuario permite sobre los incidentes del tenant,
     * para que la UI oculte o deshabilite lo que el servidor rechazaría con
     * 403. Son las mismas claves de permiso que chequean IncidentPolicy,
     * EventMediaContextPolicy::request y AIEvaluationPolicy::reevaluate; el
     * estado del incidente (terminal o no) lo sigue resolviendo la UI.
     *
     * @return array{manage: bool, resolve: bool, close: bool, requestMedia: bool, reevaluate: bool}
     */
    private function abilities(User $user, Team $team): array
    {
        $authorize = app(AuthorizeAction::class);

        return [
            'manage' => $authorize->execute($user, 'incidents.manage', $team),
            'resolve' => $authorize->execute($user, 'incidents.resolve', $team),
            'close' => $authorize->execute($user, 'incidents.close', $team),
            'requestMedia' => $authorize->execute($user, 'context.view', $team),
            'reevaluate' => $authorize->execute($user, 'ai.analysis.execute', $team),
        ];
    }

    /**
     * Resolve the active inbox filters from the request query string.
     *
     * @return array{q: string|null, severity: string|null, status: string|null, provider: string|null, shift: string|null}
     */
    private function filters(Request $request): array
    {
        return [
            'q' => $request->filled('q') ? $request->string('q')->trim()->toString() : null,
            'severity' => $request->filled('severity') ? $request->string('severity')->toString() : null,
            'status' => $request->filled('status') ? $request->string('status')->toString() : null,
            'provider' => $request->filled('provider') ? $request->string('provider')->toString() : null,
            'shift' => $request->filled('shift') ? $request->string('shift')->toString() : null,
        ];
    }

    /**
     * @param  Builder<Incident>  $query
     * @param  array{q: string|null, severity: string|null, status: string|null, provider: string|null, shift: string|null}  $filters
     */
    private function applyFilters(Builder $query, array $filters): void
    {
        if ($filters['q'] !== null && $filters['q'] !== '') {
            // LOWER(...) LIKE keeps the search case-insensitive on both
            // PostgreSQL (production) and SQLite (tests) without ILIKE.
            $term = '%'.mb_strtolower(str_replace(['%', '_'], ['\%', '\_'], $filters['q'])).'%';
            // "INC-00036", "inc-36" or "36" also find the incident by its
            // per-tenant number (the reference operators read and dictate).
            $number = preg_match('/^(?:inc-?)?0*(\d{1,9})$/i', trim($filters['q']), $matches) === 1
                ? (int) $matches[1]
                : null;

            $query->where(fn (Builder $q) => $q
                ->whereRaw('LOWER(title) LIKE ?', [$term])
                ->orWhereRaw('LOWER(summary) LIKE ?', [$term])
                ->when($number !== null, fn (Builder $inner) => $inner->orWhere('number', $number)));
        }

        if ($filters['severity'] !== null) {
            $priorityId = IncidentPriority::query()->where('code', $filters['severity'])->value('id');
            $query->where('incident_priority_id', $priorityId ?? 0);
        }

        if ($filters['status'] !== null) {
            $statusId = IncidentStatus::query()->where('code', $filters['status'])->value('id');
            $query->where('incident_status_id', $statusId ?? 0);
        }

        if ($filters['provider'] !== null) {
            $provider = $filters['provider'];
            $query->whereHas('relatedEvent.provider', fn (Builder $q) => $q->where('name', $provider));
        }

        if ($filters['shift'] !== null) {
            $hour = $this->hourExpression($query);

            if ($filters['shift'] === 'morning') {
                $query->whereRaw("{$hour} >= 6 AND {$hour} < 14");
            } elseif ($filters['shift'] === 'afternoon') {
                $query->whereRaw("{$hour} >= 14 AND {$hour} < 22");
            } elseif ($filters['shift'] === 'night') {
                // Night wraps past midnight: 22:00–23:59 and 00:00–05:59.
                $query->whereRaw("{$hour} >= 22 OR {$hour} < 6");
            }
        }
    }

    /**
     * SQL expression that yields the hour-of-day for `opened_at`, portable
     * across PostgreSQL (production) and SQLite (tests).
     *
     * @param  Builder<Incident>  $query
     */
    private function hourExpression(Builder $query): string
    {
        return $query->getModel()->getConnection()->getDriverName() === 'sqlite'
            ? "CAST(strftime('%H', opened_at) AS INTEGER)"
            : 'EXTRACT(HOUR FROM opened_at)';
    }

    /**
     * Reference lists used to populate the inbox filter dropdowns.
     *
     * @return array{severities: list<array{value: string, label: string}>, statuses: list<array{value: string, label: string}>, providers: list<string>, shifts: list<array{value: string, label: string}>}
     */
    private function filterOptions(Team $current_team): array
    {
        $providers = DB::table('incidents')
            ->join('normalized_events', 'incidents.related_event_id', '=', 'normalized_events.id')
            ->join('integration_providers', 'normalized_events.provider_id', '=', 'integration_providers.id')
            ->where('incidents.team_id', $current_team->id)
            ->whereNull('incidents.deleted_at')
            ->distinct()
            ->orderBy('integration_providers.name')
            ->pluck('integration_providers.name')
            ->all();

        return [
            'severities' => IncidentPriority::query()
                ->orderBy('id')
                ->get(['code', 'name'])
                ->map(fn (IncidentPriority $p) => ['value' => (string) $p->code, 'label' => (string) $p->name])
                ->all(),
            // Real catalog statuses, labeled with the same Spanish strings the
            // rows render so the operator can always filter what they see (B5).
            'statuses' => IncidentStatus::query()
                ->orderBy('sort_order')
                ->orderBy('id')
                ->get(['code', 'name'])
                ->map(fn (IncidentStatus $s) => [
                    'value' => (string) $s->code,
                    'label' => IncidentStatusPresenter::filterLabel((string) $s->code, (string) $s->name),
                ])
                ->all(),
            'providers' => $providers,
            'shifts' => [
                ['value' => 'morning', 'label' => 'Mañana (06–14)'],
                ['value' => 'afternoon', 'label' => 'Tarde (14–22)'],
                ['value' => 'night', 'label' => 'Noche (22–06)'],
            ],
        ];
    }

    /**
     * Team members eligible to be assigned an incident.
     *
     * @return list<array{id: int, name: string}>
     */
    private function members(Team $current_team): array
    {
        return $current_team->members()
            ->orderBy('users.name')
            ->get(['users.id', 'users.name'])
            ->map(fn (User $user) => ['id' => (int) $user->id, 'name' => (string) $user->name])
            ->all();
    }

    /**
     * Incident type/priority options used by the reclassify dialog.
     *
     * @return array{types: list<array{id: int, code: string, name: string}>, priorities: list<array{id: int, code: string, name: string}>}
     */
    private function reclassifyOptions(): array
    {
        return [
            'types' => IncidentType::query()
                ->orderBy('name')
                ->get(['id', 'code', 'name'])
                ->map(fn (IncidentType $t) => ['id' => (int) $t->id, 'code' => (string) $t->code, 'name' => (string) $t->name])
                ->all(),
            'priorities' => IncidentPriority::query()
                ->orderBy('id')
                ->get(['id', 'code', 'name'])
                ->map(fn (IncidentPriority $p) => ['id' => (int) $p->id, 'code' => (string) $p->code, 'name' => (string) $p->name])
                ->all(),
        ];
    }

    /**
     * Content-negotiated detail: the inbox panel fetches JSON (Accept:
     * application/json), while a browser navigation renders the full-page
     * Inertia view with the media gallery (Roadmap F9).
     */
    public function show(Request $request, Team $current_team, Incident $incident): JsonResponse|Response
    {
        $this->authorize('view', $incident);

        $incident->load([
            'type',
            'status',
            'priority',
            'currentAssignment',
            'asset',
            'driver',
            'relatedEvent.provider',
            'relatedEvent.eventType',
            'relatedEvent.eventSeverity',
            'aiEvaluation',
            'timeline',
            'comments',
            'evidence',
            'resolution',
            'eventLinks.normalizedEvent.eventType',
            'eventLinks.normalizedEvent.eventSeverity',
            'eventLinks.normalizedEvent.asset',
        ]);

        $userIds = collect()
            ->push($incident->currentAssignment?->assigned_to_type === AssigneeType::User
                ? (int) $incident->currentAssignment->assigned_to_id
                : null)
            ->concat($incident->comments->map(fn ($comment) => (int) $comment->user_id))
            ->concat($incident->timeline
                ->filter(fn ($entry) => $entry->actor_type === TimelineActorType::User)
                ->map(fn ($entry) => (int) $entry->actor_id))
            ->push($incident->claimed_by_user_id);

        $users = $this->resolveUsers($userIds, (int) $incident->team_id);

        $detail = $this->presenter->toDetail($incident, $users);
        // Incluido también en la rama JSON: el panel de la bandeja muestra el
        // veredicto visual agregado y las miniaturas sin navegación completa.
        $detail['mediaSummary'] = $this->mediaSummary($incident);

        if ($request->wantsJson()) {
            return response()->json($detail);
        }

        return Inertia::render('incidents/show', [
            'incident' => $detail,
            'media' => fn () => $this->mediaItems($incident),
            'mediaAssessments' => fn () => $this->mediaAssessments($incident),
            'mediaRequests' => fn () => $this->mediaRequests($incident),
            'priorIncidents' => fn () => $this->priorIncidents($incident),
            'mediaRetrieval' => fn () => $this->mediaRetrieval($incident),
            'communications' => fn () => $this->communications($request, $incident),
            'members' => fn () => $this->members($current_team),
            'reclassifyOptions' => fn () => $this->reclassifyOptions(),
            'can' => $this->abilities($request->user(), $current_team),
        ]);
    }

    /**
     * Veredicto visual agregado del evento origen: cuántas medias hay, cuántas
     * evaluó la IA y en qué sentido, más hasta 4 miniaturas para el panel de
     * la bandeja. Null cuando el incidente no tiene evento origen.
     *
     * @return array<string, mixed>|null
     */
    private function mediaSummary(Incident $incident): ?array
    {
        if ($incident->related_event_id === null) {
            return null;
        }

        $media = EventMediaContext::query()
            ->where('normalized_event_id', $incident->related_event_id)
            ->orderByDesc('id')
            ->get();

        $evaluationIds = AIEventEvaluation::query()
            ->where('normalized_event_id', $incident->related_event_id)
            ->select('id');

        // Un veredicto por media: la evaluación más reciente gana.
        $verdicts = AIMediaAssessment::query()
            ->whereIn('evaluation_id', $evaluationIds)
            ->orderByDesc('assessed_at')
            ->get(['event_media_context_id', 'result'])
            ->unique('event_media_context_id');

        $countFor = fn (string $result): int => $verdicts
            ->filter(fn (AIMediaAssessment $assessment) => $assessment->result?->value === $result)
            ->count();

        // Una entrada por archivo real: los frames extraídos se pliegan bajo su
        // clip y le dan miniatura (un mp4 no se previsualiza en un <img>).
        $entries = $this->galleryOrder(app(EventMediaGallery::class)->entries($media));

        $thumbnails = collect($entries)
            ->map(fn (array $entry): array => [
                'id' => (int) $entry['media']->id,
                'url' => $entry['thumbnailUrl'] ?? (EventMediaGallery::isVideo($entry['media']) ? null : $entry['url']),
                'mediaType' => $entry['media']->media_type?->value,
            ])
            ->filter(fn (array $thumbnail): bool => $thumbnail['url'] !== null)
            ->take(4)
            ->values()
            ->all();

        $pendingRequest = EventMediaRequest::query()
            ->where('normalized_event_id', $incident->related_event_id)
            ->whereIn('status', ['pending', 'sent', 'processing'])
            ->exists();

        return [
            'total' => count($entries),
            'images' => collect($entries)->reject(fn (array $entry): bool => EventMediaGallery::isVideo($entry['media']))->count(),
            'clips' => collect($entries)->filter(fn (array $entry): bool => EventMediaGallery::isVideo($entry['media']))->count(),
            'assessed' => $verdicts->count(),
            'confirms' => $countFor('confirms_event'),
            'contradicts' => $countFor('contradicts_event'),
            'inconclusive' => $countFor('inconclusive'),
            'lowQuality' => $countFor('low_quality'),
            'unavailable' => $countFor('unavailable'),
            'pendingRequest' => $pendingRequest,
            'thumbnails' => $thumbnails,
        ];
    }

    /**
     * Media inventory of the incident's source event, with viewable URLs.
     *
     * @return list<array<string, mixed>>
     */
    private function mediaItems(Incident $incident): array
    {
        if ($incident->related_event_id === null) {
            return [];
        }

        $media = EventMediaContext::query()
            ->where('normalized_event_id', $incident->related_event_id)
            ->orderByDesc('id')
            ->get();

        return array_map(fn (array $entry): array => [
            'id' => (int) $entry['media']->id,
            'mediaType' => $entry['media']->media_type?->value,
            'mimeType' => $entry['media']->mime_type,
            'url' => $entry['url'],
            'thumbnailUrl' => $entry['thumbnailUrl'],
            // Frames que la IA evaluó por este clip: su veredicto es el del clip.
            'frameIds' => $entry['frameIds'],
            'durationSeconds' => $entry['media']->duration_seconds !== null ? (int) $entry['media']->duration_seconds : null,
            'sizeBytes' => $entry['media']->size_bytes !== null ? (int) $entry['media']->size_bytes : null,
            'capturedAt' => $entry['media']->captured_at?->toIso8601String(),
            'availabilityStatus' => $entry['media']->availability_status?->value,
        ], $this->galleryOrder(app(EventMediaGallery::class)->entries($media)));
    }

    /**
     * Fotos antes que clips y, dentro de cada grupo, en orden de captura: el
     * operador ve primero lo que carga al instante.
     *
     * @param  list<array{media: EventMediaContext, url: string|null, thumbnailUrl: string|null, frameIds: list<int>}>  $entries
     * @return list<array{media: EventMediaContext, url: string|null, thumbnailUrl: string|null, frameIds: list<int>}>
     */
    private function galleryOrder(array $entries): array
    {
        usort($entries, fn (array $a, array $b): int => [EventMediaGallery::isVideo($a['media']), (int) $a['media']->id]
            <=> [EventMediaGallery::isVideo($b['media']), (int) $b['media']->id]);

        return $entries;
    }

    /**
     * What the AI saw in each media asset, across evaluation versions.
     *
     * @return list<array<string, mixed>>
     */
    private function mediaAssessments(Incident $incident): array
    {
        if ($incident->related_event_id === null) {
            return [];
        }

        $evaluationIds = AIEventEvaluation::query()
            ->where('normalized_event_id', $incident->related_event_id)
            ->select('id');

        return AIMediaAssessment::query()
            ->whereIn('evaluation_id', $evaluationIds)
            ->orderByDesc('assessed_at')
            ->get()
            ->map(fn (AIMediaAssessment $assessment): array => [
                'id' => (int) $assessment->id,
                'mediaContextId' => (int) $assessment->event_media_context_id,
                'result' => $assessment->result?->value,
                'confidenceScore' => $assessment->confidence_score !== null ? (float) $assessment->confidence_score : null,
                'summary' => $assessment->summary_text,
                'assessmentType' => $assessment->assessment_type?->value,
                'modelUsed' => $assessment->model_used,
                'assessedAt' => $assessment->assessed_at?->toIso8601String(),
            ])
            ->all();
    }

    /**
     * In-flight / recent media retrieval requests for the source event.
     *
     * @return list<array<string, mixed>>
     */
    private function mediaRequests(Incident $incident): array
    {
        if ($incident->related_event_id === null) {
            return [];
        }

        return EventMediaRequest::query()
            ->where('normalized_event_id', $incident->related_event_id)
            ->orderByDesc('id')
            ->limit(5)
            ->get()
            ->map(fn (EventMediaRequest $request): array => [
                'id' => (int) $request->id,
                'status' => $request->status?->value,
                'requestType' => $request->request_type?->value,
                'requestedAt' => ($request->requested_at ?? $request->created_at)?->toIso8601String(),
            ])
            ->all();
    }

    /**
     * Related incidents, computed when the page is viewed (UI audit P2):
     * the links the Context pipeline stored for the source event (B6-P8)
     * were written BEFORE this incident existed and never change, so later
     * incidents on the same unit/driver never showed up. The live part is
     * other incidents of the same asset or driver in the last 30 days.
     *
     * @return list<array<string, mixed>>
     */
    private function priorIncidents(Incident $incident): array
    {
        $related = collect();

        if ($incident->related_event_id !== null) {
            EventRelatedIncidentLink::query()
                ->where('team_id', $incident->team_id)
                ->where('normalized_event_id', $incident->related_event_id)
                ->where('incident_id', '!=', $incident->id)
                ->with(['incident.status', 'incident.priority'])
                ->orderByDesc('confidence_score')
                ->limit(10)
                ->get()
                ->filter(fn (EventRelatedIncidentLink $link) => $link->incident !== null
                    && (int) $link->incident->team_id === (int) $incident->team_id)
                ->each(fn (EventRelatedIncidentLink $link) => $related->put($link->incident_id, $this->priorIncidentRow(
                    $link->incident,
                    $link->relation_type?->value,
                    $link->confidence_score !== null ? (float) $link->confidence_score : null,
                )));
        }

        if ($incident->asset_id !== null || $incident->driver_id !== null) {
            Incident::query()
                ->where('team_id', $incident->team_id)
                ->whereKeyNot($incident->id)
                ->where(fn (Builder $query) => $query
                    ->when($incident->asset_id !== null, fn (Builder $q) => $q->orWhere('asset_id', $incident->asset_id))
                    ->when($incident->driver_id !== null, fn (Builder $q) => $q->orWhere('driver_id', $incident->driver_id)))
                ->where('opened_at', '>=', now()->subDays(self::RELATED_WINDOW_DAYS))
                ->with(['status', 'priority'])
                ->orderByDesc('opened_at')
                ->limit(10)
                ->get()
                ->reject(fn (Incident $other) => $related->has($other->id))
                ->each(fn (Incident $other) => $related->put($other->id, $this->priorIncidentRow(
                    $other,
                    $incident->asset_id !== null && $other->asset_id === $incident->asset_id
                        ? IncidentRelationType::SameAssetOpenIncident->value
                        : IncidentRelationType::SameDriverRecentIncident->value,
                    null,
                )));
        }

        return $related->take(10)->values()->all();
    }

    /**
     * @return array<string, mixed>
     */
    private function priorIncidentRow(Incident $other, ?string $relationType, ?float $confidence): array
    {
        return [
            'incidentId' => (int) $other->id,
            'reference' => $other->reference(),
            'title' => (string) $other->title,
            'status' => $other->status?->code,
            'statusLabel' => IncidentStatusPresenter::label($other->status?->code),
            'severity' => $other->priority?->code,
            'openedAt' => $other->opened_at?->toIso8601String(),
            'relationType' => $relationType,
            'confidenceScore' => $confidence,
        ];
    }

    /**
     * Whether asking the provider for footage can still work. Past the
     * device retention window (the same `media.retrieval_max_age_hours` the
     * retrieval job enforces) SD footage is overwritten, so the page must not
     * offer a request that is guaranteed to fail.
     *
     * @return array{available: bool, reason: string|null, maxAgeHours: int}
     */
    private function mediaRetrieval(Incident $incident): array
    {
        $maxAgeHours = MediaRetrievalWindow::maxAgeHours((int) $incident->team_id);

        if ($incident->related_event_id === null) {
            return ['available' => false, 'reason' => 'El incidente no tiene un evento de origen.', 'maxAgeHours' => $maxAgeHours];
        }

        $occurredAt = NormalizedEvent::query()
            ->where('team_id', $incident->team_id)
            ->whereKey($incident->related_event_id)
            ->value('occurred_at');

        $expiredReason = MediaRetrievalWindow::expiredReason(
            (int) $incident->team_id,
            $occurredAt !== null ? Carbon::parse($occurredAt) : null,
        );

        return [
            'available' => $expiredReason === null,
            'reason' => $expiredReason,
            'maxAgeHours' => $maxAgeHours,
        ];
    }

    /**
     * Verification calls placed for this incident and the notifications it
     * triggered, so the operator can jump to the delivery detail (UI audit
     * P2). Notification links only for users allowed to open them.
     *
     * @return array{verificationCalls: list<array<string, mixed>>, notifications: list<array<string, mixed>>}
     */
    private function communications(Request $request, Incident $incident): array
    {
        $calls = IncidentCallVerification::query()
            ->where('team_id', $incident->team_id)
            ->where('incident_id', $incident->id)
            ->orderByDesc('id')
            ->limit(10)
            ->get()
            ->map(fn (IncidentCallVerification $call): array => [
                'id' => (int) $call->id,
                'attempt' => (int) $call->attempt,
                'status' => $call->status?->value,
                'outcome' => $call->outcome?->value,
                'phone' => $this->maskPhone($call->phone),
                'placedAt' => $call->placed_at?->toIso8601String(),
                'respondedAt' => $call->responded_at?->toIso8601String(),
            ])
            ->values()
            ->all();

        $notifications = [];

        if ($request->user()?->can('viewAny', Notification::class)) {
            $notifications = Notification::query()
                ->where('team_id', $incident->team_id)
                ->where('source_type', NotificationSourceType::Incident)
                ->where('source_reference_id', $incident->id)
                ->withCount([
                    'deliveries',
                    'deliveries as delivered_count' => fn ($query) => $query->whereIn('status', [DeliveryStatus::Delivered, DeliveryStatus::Sent]),
                    'deliveries as failed_count' => fn ($query) => $query->whereIn('status', [DeliveryStatus::Failed, DeliveryStatus::Bounced]),
                ])
                ->orderByDesc('id')
                ->limit(10)
                ->get()
                ->map(fn (Notification $notification): array => [
                    'id' => (int) $notification->id,
                    'subject' => (string) ($notification->subject ?? $notification->notification_type),
                    'createdAt' => $notification->created_at?->toIso8601String(),
                    'deliveries' => (int) $notification->deliveries_count,
                    'delivered' => (int) $notification->delivered_count,
                    'failed' => (int) $notification->failed_count,
                ])
                ->values()
                ->all();
        }

        return ['verificationCalls' => $calls, 'notifications' => $notifications];
    }

    private function maskPhone(?string $phone): ?string
    {
        if ($phone === null || strlen($phone) < 4) {
            return $phone;
        }

        return str_repeat('•', max(0, strlen($phone) - 4)).substr($phone, -4);
    }

    /**
     * Batch-load the users referenced by the given ids into an id-keyed map.
     *
     * @param  Collection<int, int|null>  $ids
     * @return Collection<int, User>
     */
    private function resolveUsers(Collection $ids, int $teamId): Collection
    {
        // $ids puede llegar como Eloquent Collection "impura" (p. ej. tras
        // concat() sobre una EloquentCollection vacía): forzar a base
        // Collection evita que unique() invoque getKey() sobre enteros.
        $ids = $ids->toBase()->filter()->unique()->values();

        if ($ids->isEmpty()) {
            return collect();
        }

        // Sólo miembros del team (y super-admins que operan sobre él): los
        // ids vienen de asignaciones/comentarios y no deben servir para
        // enumerar nombres de usuarios de otros tenants.
        return TeamMembers::scope(User::query()->whereIn('id', $ids), $teamId, includeSuperAdmins: true)
            ->get()
            ->keyBy('id');
    }
}
