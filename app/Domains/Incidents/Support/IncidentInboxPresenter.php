<?php

namespace App\Domains\Incidents\Support;

use App\Domains\AI\Enums\EvaluationPriority;
use App\Domains\AI\Enums\EventClassification;
use App\Domains\AI\Models\AIEventEvaluation;
use App\Domains\AI\Support\PlaceholderEvaluation;
use App\Domains\Context\Enums\GeofenceMatchType;
use App\Domains\Context\Models\EventContextSnapshot;
use App\Domains\Incidents\Enums\AssigneeType;
use App\Domains\Incidents\Enums\CommentVisibility;
use App\Domains\Incidents\Enums\EvidenceType;
use App\Domains\Incidents\Enums\TimelineActorType;
use App\Domains\Incidents\Enums\TimelineEntryType;
use App\Domains\Incidents\Models\Incident;
use App\Domains\Incidents\Models\IncidentAssignment;
use App\Domains\Incidents\Models\IncidentComment;
use App\Domains\Incidents\Models\IncidentEventLink;
use App\Domains\Incidents\Models\IncidentEvidence;
use App\Domains\Incidents\Models\IncidentTimeline;
use App\Domains\Normalization\Models\NormalizedEvent;
use App\Models\User;
use Carbon\CarbonInterface;
use Illuminate\Support\Arr;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

/**
 * Maps Incident aggregates to the JSON shapes consumed by the Incident Inbox
 * React page (`MockIncident` rows and the full `IncidentDetail` payload).
 */
class IncidentInboxPresenter
{
    private const DEFAULT_SLA_SECONDS = 1800;

    /**
     * Etiquetas en español para los veredictos de media de la IA. El valor
     * crudo viene en `payload_json.result` de las entradas MediaAssessed.
     */
    private const MEDIA_RESULT_ES = [
        'confirms_event' => 'confirma el evento',
        'contradicts_event' => 'contradice el evento',
        'inconclusive' => 'no concluyente',
        'low_quality' => 'baja calidad',
        'unavailable' => 'no disponible',
    ];

    /**
     * Descripciones históricas almacenadas en inglés por writers antiguos.
     * Los writers ya escriben en español; esto cubre filas preexistentes.
     */
    private const LEGACY_DESCRIPTION_ES = [
        'SLA breached without acknowledgement.' => 'SLA vencido sin atención (ACK).',
    ];

    /**
     * Map an incident to a lightweight inbox row (`MockIncident`).
     *
     * @param  Collection<int, User>  $users  Pre-resolved assignee users keyed by id.
     * @return array<string, mixed>
     */
    public function toRow(Incident $incident, Collection $users, ?CarbonInterface $now = null): array
    {
        $now ??= Carbon::now();
        $event = $incident->relatedEvent;
        $evaluation = $incident->aiEvaluation;
        $status = $this->status($incident);

        return [
            'id' => $incident->reference(),
            'incidentId' => $incident->id,
            'number' => $incident->number,
            'title' => $incident->title ?? 'Incidente',
            'severity' => $this->severity($incident),
            'status' => $status,
            'statusLabel' => IncidentStatusPresenter::UI_LABELS[$status],
            'provider' => $this->provider($event),
            'asset' => $this->asset($incident),
            'driver' => $this->driver($incident),
            'assignee' => $this->assignee($incident, $users),
            'claimedBy' => $this->claimedBy($incident, $users),
            'claimedAt' => $incident->claimed_at?->toIso8601String(),
            'slaSeconds' => $this->slaSeconds($incident, $now),
            'slaTotal' => $this->slaTotal($incident),
            'ageMin' => $this->ageMin($incident, $now),
            'eventType' => $this->eventType($incident),
            'location' => $this->location($event),
            'aiPlaceholder' => $this->isPlaceholder($evaluation),
            'aiConfidence' => $this->aiConfidence($evaluation),
            'aiDecision' => $this->aiDecision($evaluation),
            'aiReason' => $this->aiReason($evaluation),
            'realtime' => false,
        ];
    }

    /**
     * Map an incident (with its detail relations loaded) to a full
     * `IncidentDetail` payload for the right-hand panel.
     *
     * @param  Collection<int, User>  $users  Pre-resolved actor/assignee users keyed by id.
     * @return array<string, mixed>
     */
    public function toDetail(Incident $incident, Collection $users, ?CarbonInterface $now = null): array
    {
        $now ??= Carbon::now();
        $evaluation = $incident->aiEvaluation;

        return [
            ...$this->toRow($incident, $users, $now),
            'aiEvaluationId' => $evaluation?->id,
            'model' => $this->model($evaluation),
            'latencyMs' => $this->latencyMs($evaluation),
            'summary' => $this->incidentSummary($incident),
            'openedAt' => $incident->opened_at?->toIso8601String(),
            'slaDueAt' => $incident->sla_due_at?->toIso8601String(),
            'eventOccurredAt' => $incident->relatedEvent?->occurred_at?->toIso8601String(),
            'aiRiskScore' => $evaluation?->risk_score !== null && ! $this->isPlaceholder($evaluation)
                ? round($evaluation->risk_score, 2)
                : null,
            'aiMode' => $evaluation?->evaluation_mode?->value,
            'aiEvaluatedAt' => $evaluation?->evaluated_at?->toIso8601String(),
            'aiReasoningSteps' => $this->isPlaceholder($evaluation) ? [] : $this->reasoningSteps($evaluation),
            'aiOperatorVerdict' => $evaluation?->operator_verdict?->value,
            'aiOperatorVerdictLabel' => $evaluation?->operator_verdict?->label(),
            'aiOperatorVerdictAt' => $evaluation?->operator_verdict_at?->toIso8601String(),
            'resolution' => $this->resolution($incident),
            'timeline' => $incident->timeline
                ->map(fn (IncidentTimeline $entry) => $this->timelineEntry($entry, $users))
                ->values()
                ->all(),
            'relatedLinks' => $incident->eventLinks
                ->map(fn (IncidentEventLink $link) => $this->relatedLink($link))
                ->filter()
                ->values()
                ->all(),
            'comments' => $incident->comments
                ->map(fn (IncidentComment $comment) => $this->comment($comment, $users, $now))
                ->values()
                ->all(),
            'evidence' => $incident->evidence
                ->map(fn (IncidentEvidence $evidence) => $this->evidenceItem($evidence))
                ->values()
                ->all(),
            'operationalContext' => $this->operationalContext($incident),
        ];
    }

    private function severity(Incident $incident): string
    {
        return match ($incident->priority?->code) {
            'critical' => 'critical',
            'high' => 'high',
            'medium' => 'medium',
            'low' => 'low',
            default => 'info',
        };
    }

    private function status(Incident $incident): string
    {
        // Canonical mapping lives in IncidentStatusPresenter so every surface
        // (inbox, detail, palette, asset detail) renders the same string.
        return IncidentStatusPresenter::forIncident($incident);
    }

    private function provider(?NormalizedEvent $event): string
    {
        return $event?->provider?->name ?? '—';
    }

    private function asset(Incident $incident): string
    {
        $asset = $incident->asset;

        if ($asset === null) {
            return '—';
        }

        // Truthiness original de string: '' y '0' cuentan como vacíos.
        if (! self::isBlank($asset->code) && ! self::isBlank($asset->name)) {
            return "{$asset->code} · {$asset->name}";
        }

        return $asset->name ?? $asset->code ?? '—';
    }

    private function driver(Incident $incident): string
    {
        $driver = $incident->driver;

        if ($driver === null) {
            return '—';
        }

        // `(a ?? b) ?: '—'`: un nombre vacío ('' o '0') cae a '—'.
        $name = $driver->full_name
            ?? trim("{$driver->first_name} {$driver->last_name}");

        return self::isBlank($name) ? '—' : $name;
    }

    /**
     * Quién tiene tomado el incidente, con la misma forma que `assignee` para
     * que la bandeja pueda reutilizar el avatar de iniciales. El usuario sale
     * de la colección ya resuelta: no dispara consulta por fila.
     *
     * @param  Collection<int, User>  $users
     * @return array{id: int, name: string, initials: string}|null
     */
    private function claimedBy(Incident $incident, Collection $users): ?array
    {
        if ($incident->claimed_by_user_id === null) {
            return null;
        }

        $name = $users->get($incident->claimed_by_user_id)?->name ?? 'Usuario';

        return [
            'id' => $incident->claimed_by_user_id,
            'name' => $name,
            'initials' => $this->initials($name),
        ];
    }

    /**
     * The person who owns the incident: the user it is assigned to, else the
     * operator who took it ("Tomar"). Queue/role assignments have no person,
     * so they render as unassigned (UI audit P0-2).
     *
     * @param  Collection<int, User>  $users
     * @return array{id: int, name: string, initials: string}|null
     */
    private function assignee(Incident $incident, Collection $users): ?array
    {
        $assignment = $this->activeAssignment($incident);

        if ($assignment === null || $assignment->assigned_to_type !== AssigneeType::User) {
            return $this->claimedBy($incident, $users);
        }

        $user = $users->get($assignment->assigned_to_id);
        $name = $user?->name ?? 'Usuario';

        return [
            'id' => $assignment->assigned_to_id,
            'name' => $name,
            'initials' => $this->initials($name),
        ];
    }

    private function activeAssignment(Incident $incident): ?IncidentAssignment
    {
        return $incident->relationLoaded('currentAssignment')
            ? $incident->currentAssignment
            : $incident->currentAssignment()->first();
    }

    private function slaTotal(Incident $incident): int
    {
        // `sla_due_at` is the vigilance actually scheduled at creation time
        // (possibly a tenant override via ResolveIncidentSla) — prefer it
        // over re-deriving from the catalog so the countdown never drifts
        // from the watchdog. Only incidents predating this column, or with
        // no SLA at all, fall back to the catalog chain.
        if ($incident->sla_due_at !== null) {
            return max(0, (int) $this->slaClockStart($incident)->diffInSeconds($incident->sla_due_at, false));
        }

        $seconds = $incident->priority?->sla_seconds
            ?? $incident->relatedEvent?->eventSeverity?->response_sla_seconds;

        // Un SLA de 0 (o ausente) cae al default, como el `?:` original.
        return $seconds !== null && $seconds !== 0 ? $seconds : self::DEFAULT_SLA_SECONDS;
    }

    private function slaSeconds(Incident $incident, CarbonInterface $now): int
    {
        if ($incident->isTerminal()) {
            return 0;
        }

        if ($incident->sla_due_at !== null) {
            return (int) $now->diffInSeconds($incident->sla_due_at, false);
        }

        return $this->slaTotal($incident) - (int) $incident->opened_at->diffInSeconds($now);
    }

    /**
     * The SLA runs from when SAM learned of the event, not from when it
     * happened (CreateIncidentFromEvent: due = max(opened_at, now) + sla). A
     * panic delivered hours late opens with `opened_at` in the past; measuring
     * the total from there made a fresh 5-min SLA look 99 % consumed.
     */
    private function slaClockStart(Incident $incident): CarbonInterface
    {
        $opened = $incident->opened_at;
        $created = $incident->created_at;

        return $created !== null && $created->gt($opened) ? $created : $opened;
    }

    private function ageMin(Incident $incident, CarbonInterface $now): int
    {
        return (int) $incident->opened_at->diffInMinutes($now);
    }

    private function eventType(Incident $incident): string
    {
        return $incident->relatedEvent?->eventType?->code
            ?? $incident->type?->code
            ?? '—';
    }

    private function location(?NormalizedEvent $event): string
    {
        // `normalized_events.context_json` is never written by the pipeline;
        // the event payload is the location source for list rows.
        $location = $event?->payload_normalized_json['location'] ?? null;

        if (is_string($location) && trim($location) !== '') {
            return $location;
        }

        // Ingested events carry location as an array (lat/lng plus an optional
        // reverse-geocoded address) rather than a display string.
        if (is_array($location)) {
            $formatted = $location['formatted_location']
                ?? $location['formattedLocation']
                ?? $location['address']
                ?? ($location['reverseGeo']['formattedLocation'] ?? null);

            if (is_string($formatted) && trim($formatted) !== '') {
                return $formatted;
            }

            $lat = $location['latitude'] ?? null;
            $lng = $location['longitude'] ?? null;

            if (is_numeric($lat) && is_numeric($lng)) {
                return sprintf('%.5f, %.5f', (float) $lat, (float) $lng);
            }
        }

        return '—';
    }

    /**
     * Evaluations from the deterministic stand-in agent (`null-agent:*`)
     * carry a fixed 0.85 that is not a verdict (UI audit P0-3).
     */
    private function isPlaceholder(?AIEventEvaluation $evaluation): bool
    {
        return $evaluation !== null && $evaluation->isPlaceholder();
    }

    private function aiConfidence(?AIEventEvaluation $evaluation): ?float
    {
        if ($evaluation === null || $evaluation->isPlaceholder() || $evaluation->confidence_score === null) {
            return null;
        }

        return round($evaluation->confidence_score, 2);
    }

    private function aiDecision(?AIEventEvaluation $evaluation): string
    {
        if ($evaluation === null || $evaluation->isPlaceholder()) {
            return 'info';
        }

        $decision = match ($evaluation->classification) {
            EventClassification::RealEvent => 'incident',
            EventClassification::FalsePositive,
            EventClassification::Noise,
            EventClassification::Duplicate => 'discard',
            default => 'info',
        };

        if ($evaluation->priority_level === EvaluationPriority::Urgent
            && $evaluation->classification?->isActionable()) {
            return 'escalate';
        }

        return $decision;
    }

    private function aiReason(?AIEventEvaluation $evaluation): string
    {
        if ($evaluation === null || $evaluation->isPlaceholder()) {
            return PlaceholderEvaluation::LABEL.'.';
        }

        return $evaluation->explanation_text ?? PlaceholderEvaluation::LABEL.'.';
    }

    private function model(?AIEventEvaluation $evaluation): string
    {
        if ($evaluation === null || $evaluation->isPlaceholder()) {
            return '—';
        }

        $modelUsed = $evaluation->model_used;
        $model = $modelUsed === null || $modelUsed === '' || $modelUsed === '0' ? '—' : $modelUsed;
        $version = $evaluation->evaluation_version;

        return $version !== 0 ? "{$model} · v{$version}" : $model;
    }

    private function latencyMs(?AIEventEvaluation $evaluation): int
    {
        $summary = $evaluation?->evidence_summary_json ?? [];
        $signals = $evaluation?->signals_json ?? [];

        return (int) ($summary['latency_ms'] ?? $signals['latency_ms'] ?? 0);
    }

    /**
     * @param  Collection<int, User>  $users
     * @return array{type: string, entryType: string|null, actor: string, text: string, tsIso: string|null, sub: string|null, meta: array{result: string|null, confidence: float|null}|null}
     */
    private function timelineEntry(IncidentTimeline $entry, Collection $users): array
    {
        $type = match ($entry->entry_type) {
            TimelineEntryType::Created, TimelineEntryType::Escalated => 'critical',
            TimelineEntryType::SlaBreached, TimelineEntryType::EscalationExhausted, TimelineEntryType::LateArrival => 'sla',
            TimelineEntryType::MediaAssessed => 'media',
            TimelineEntryType::Resolved,
            TimelineEntryType::ExternallyResolved,
            TimelineEntryType::Closed => 'resolved',
            TimelineEntryType::Assigned,
            TimelineEntryType::Claimed,
            TimelineEntryType::Released => 'assign',
            TimelineEntryType::CommentAdded => 'comment',
            TimelineEntryType::EventLinked => 'webhook',
            default => $entry->actor_type === TimelineActorType::Ai ? 'ai' : 'system',
        };

        $actor = match ($entry->actor_type) {
            TimelineActorType::System => 'Sistema',
            TimelineActorType::Ai => 'SAM',
            TimelineActorType::Automation => 'Automatización',
            TimelineActorType::User => $users->get((int) $entry->actor_id)?->name ?? 'Usuario',
        };

        $payload = is_array($entry->payload_json) ? $entry->payload_json : [];
        $result = $payload['result'] ?? null;
        $confidence = $payload['confidence_score'] ?? null;

        return [
            'type' => $type,
            'entryType' => $entry->entry_type?->value,
            'actor' => $actor,
            'text' => $this->timelineText($entry, $payload),
            'tsIso' => $entry->occurred_at?->toIso8601String(),
            'sub' => $entry->description !== null
                ? (self::LEGACY_DESCRIPTION_ES[$entry->description] ?? $entry->description)
                : null,
            'meta' => $entry->entry_type === TimelineEntryType::MediaAssessed
                ? [
                    'result' => is_string($result) ? $result : null,
                    'confidence' => is_numeric($confidence) ? round((float) $confidence, 2) : null,
                ]
                : null,
        ];
    }

    /**
     * Texto de la entrada en español, derivado del tipo — los `title`
     * almacenados vienen de los writers del backend en inglés y no se
     * muestran tal cual. El título crudo queda como fallback para tipos
     * desconocidos.
     *
     * @param  array<string, mixed>  $payload
     */
    private function timelineText(IncidentTimeline $entry, array $payload): string
    {
        $base = match ($entry->entry_type) {
            TimelineEntryType::Created => 'Incidente creado',
            TimelineEntryType::StatusChanged => 'Estado actualizado',
            TimelineEntryType::PriorityChanged => 'Prioridad actualizada',
            TimelineEntryType::Assigned => 'Incidente asignado',
            TimelineEntryType::Escalated => 'Incidente escalado',
            TimelineEntryType::Acknowledged => 'Incidente atendido (ACK)',
            TimelineEntryType::Claimed => 'Incidente tomado',
            TimelineEntryType::Released => 'Incidente liberado',
            TimelineEntryType::SlaBreached => 'SLA incumplido',
            TimelineEntryType::EscalationExhausted => 'Escalación agotada sin atención',
            TimelineEntryType::CommentAdded => 'Comentario agregado',
            TimelineEntryType::EvidenceAdded => 'Evidencia adjuntada',
            TimelineEntryType::ActionExecuted => 'Acción ejecutada',
            TimelineEntryType::Resolved => 'Incidente resuelto',
            TimelineEntryType::ExternallyResolved => 'Resuelto en origen',
            TimelineEntryType::Closed => 'Incidente cerrado',
            TimelineEntryType::Reopened => 'Incidente reabierto',
            TimelineEntryType::Reclassified => 'Incidente reclasificado',
            TimelineEntryType::EventLinked => 'Evento vinculado',
            TimelineEntryType::MediaAssessed => 'Media evaluada',
            TimelineEntryType::VerificationCall => 'Llamada de verificación',
            // El writer (CreateIncidentFromEvent) ya guarda el título en español.
            TimelineEntryType::LateArrival => $entry->title ?? 'Evento recibido con retraso',
            default => null,
        };

        if ($base === null) {
            return $entry->title ?? '';
        }

        if ($entry->entry_type === TimelineEntryType::MediaAssessed) {
            $result = $payload['result'] ?? null;
            $label = is_string($result) ? (self::MEDIA_RESULT_ES[$result] ?? $result) : null;

            return $label !== null ? "{$base}: {$label}" : $base;
        }

        if ($entry->entry_type === TimelineEntryType::EventLinked) {
            $eventId = $payload['normalized_event_id'] ?? null;

            return is_numeric($eventId) ? "Evento #{$eventId} vinculado" : $base;
        }

        return $base;
    }

    /**
     * Resumen operativo propio del incidente (no el de la IA). Null cuando
     * no hay texto útil que mostrar.
     */
    private function incidentSummary(Incident $incident): ?string
    {
        $summary = trim($incident->summary ?? '');

        return $summary !== '' ? $summary : null;
    }

    /**
     * @return list<string>
     */
    private function reasoningSteps(?AIEventEvaluation $evaluation): array
    {
        $steps = $evaluation?->signals_json['reasoning_steps'] ?? [];

        if (! is_array($steps)) {
            return [];
        }

        return array_values(array_filter(
            $steps,
            fn ($step) => is_string($step) && trim($step) !== '',
        ));
    }

    /**
     * @return array{code: string|null, summary: string|null, rootCause: string|null, resolvedAt: string|null}|null
     */
    private function resolution(Incident $incident): ?array
    {
        $resolution = $incident->relationLoaded('resolution') ? $incident->resolution : null;

        if ($resolution === null) {
            return null;
        }

        return [
            'code' => $resolution->resolution_code?->value,
            'summary' => $resolution->resolution_summary,
            'rootCause' => $resolution->root_cause,
            'resolvedAt' => $resolution->resolved_at?->toIso8601String(),
        ];
    }

    /**
     * @return array{tsIso: string|null, eventId: int, eventType: string, asset: string, relationType: string|null, severity: string|null}|null
     */
    private function relatedLink(IncidentEventLink $link): ?array
    {
        $event = $link->normalizedEvent;

        if ($event === null) {
            return null;
        }

        return [
            'tsIso' => $event->occurred_at?->toIso8601String(),
            'eventId' => $event->id,
            'eventType' => $event->eventType?->code ?? '—',
            'asset' => $event->asset?->code ?? $event->asset?->name ?? '—',
            'relationType' => $link->relation_type?->value,
            'severity' => $this->eventSeverity($event),
        ];
    }

    private function eventSeverity(NormalizedEvent $event): ?string
    {
        return match ($event->eventSeverity?->code) {
            'critical' => 'critical',
            'high' => 'high',
            'medium' => 'medium',
            'low' => 'low',
            'info' => 'info',
            default => null,
        };
    }

    /**
     * @param  Collection<int, User>  $users
     * @return array{authorInitials: string, authorName: string, visibility: string, body: string, relativeTime: string}
     */
    private function comment(IncidentComment $comment, Collection $users, CarbonInterface $now): array
    {
        $user = $users->get($comment->user_id);
        $name = $user?->name ?? 'Usuario';

        $visibility = match ($comment->visibility) {
            CommentVisibility::TenantVisible => 'tenant',
            CommentVisibility::AuditOnly => 'audit',
            default => 'internal',
        };

        return [
            'authorInitials' => $this->initials($name),
            'authorName' => $name,
            'visibility' => $visibility,
            'body' => $comment->comment ?? '',
            'relativeTime' => $this->relativeTime($comment->created_at, $now),
        ];
    }

    /**
     * @return array{label: string, sub: string, type: string}
     */
    private function evidenceItem(IncidentEvidence $evidence): array
    {
        $type = match ($evidence->evidence_type) {
            EvidenceType::Video => 'video',
            EvidenceType::Image,
            EvidenceType::EventSnapshot,
            EvidenceType::TelemetrySnapshot => 'chart',
            default => 'payload',
        };

        $label = $evidence->title ?? ucfirst(str_replace('_', ' ', $evidence->evidence_type->value));

        return [
            'label' => $label,
            'sub' => $evidence->description ?? '',
            'type' => $type,
            'fileUrl' => $evidence->downloadUrl(),
        ];
    }

    /**
     * Operational context of the incident's source event, read from the
     * snapshot the Context pipeline persists (`event_context_snapshots`).
     * Weather, traffic and driving hours have no data source yet, so they stay
     * "—" and the card hides them.
     *
     * @return array{weather: string, traffic: string, driverRisk: int, geofenceStatus: string, drivingHours: string}
     */
    private function operationalContext(Incident $incident): array
    {
        $snapshot = $incident->related_event_id === null ? null : EventContextSnapshot::query()
            ->where('team_id', $incident->team_id)
            ->where('normalized_event_id', $incident->related_event_id)
            ->first();

        $risk = Arr::get($snapshot?->driver_snapshot_json ?? [], 'risk_profile.risk_score')
            ?? $incident->driver?->riskProfile?->risk_score
            ?? 0;

        return [
            'weather' => '—',
            'traffic' => '—',
            'driverRisk' => (int) round((float) $risk),
            'geofenceStatus' => $snapshot === null ? '—' : $this->geofenceStatus($snapshot->geofence_snapshot_json ?? []),
            'drivingHours' => '—',
        ];
    }

    /**
     * @param  array<int, array<string, mixed>>  $matches  Stored geofence matches of the event.
     */
    private function geofenceStatus(array $matches): string
    {
        $named = fn (array $match): string => (string) ($match['name'] ?? $match['code'] ?? 'geocerca');

        foreach ($matches as $match) {
            if (($match['match_type'] ?? null) === GeofenceMatchType::Inside->value) {
                return 'Dentro de '.$named($match);
            }
        }

        foreach ($matches as $match) {
            if (($match['match_type'] ?? null) === GeofenceMatchType::NearBoundary->value) {
                return 'Cerca de '.$named($match);
            }
        }

        return 'Fuera de geocercas';
    }

    /**
     * Truthiness de PHP para un string: null, '' y '0' cuentan como vacíos.
     *
     * @phpstan-assert-if-false non-falsy-string $value
     */
    private static function isBlank(?string $value): bool
    {
        return $value === null || $value === '' || $value === '0';
    }

    private function initials(string $name): string
    {
        $parts = preg_split('/\s+/', trim($name));
        // Mismo descarte que el array_filter sin callback sobre strings ('' y '0').
        $parts = $parts === false ? [] : array_values(array_filter(
            $parts,
            fn (string $part): bool => $part !== '' && $part !== '0',
        ));

        if ($parts === []) {
            return '?';
        }

        if (count($parts) === 1) {
            return mb_strtoupper(mb_substr($parts[0], 0, 1));
        }

        return mb_strtoupper(mb_substr($parts[0], 0, 1).mb_substr($parts[count($parts) - 1], 0, 1));
    }

    private function relativeTime(?CarbonInterface $time, CarbonInterface $now): string
    {
        if ($time === null) {
            return '';
        }

        $minutes = (int) $time->diffInMinutes($now);

        if ($minutes < 1) {
            return 'hace instantes';
        }

        if ($minutes < 60) {
            return "hace {$minutes} min";
        }

        $hours = intdiv($minutes, 60);

        if ($hours < 24) {
            return "hace {$hours} h";
        }

        $days = intdiv($hours, 24);

        return "hace {$days} d";
    }
}
