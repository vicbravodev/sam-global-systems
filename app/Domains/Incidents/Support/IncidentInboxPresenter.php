<?php

namespace App\Domains\Incidents\Support;

use App\Domains\AI\Enums\EvaluationPriority;
use App\Domains\AI\Enums\EventClassification;
use App\Domains\AI\Models\AIEventEvaluation;
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
            'id' => $this->reference($incident),
            'incidentId' => (int) $incident->id,
            'title' => (string) ($incident->title ?? 'Incidente'),
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
            'aiEvaluationId' => $evaluation?->id !== null ? (int) $evaluation->id : null,
            'model' => $this->model($evaluation),
            'latencyMs' => $this->latencyMs($evaluation),
            'summary' => $this->incidentSummary($incident),
            'openedAt' => $incident->opened_at?->toIso8601String(),
            'slaDueAt' => $incident->sla_due_at?->toIso8601String(),
            'eventOccurredAt' => $incident->relatedEvent?->occurred_at?->toIso8601String(),
            'aiRiskScore' => $evaluation?->risk_score !== null ? round((float) $evaluation->risk_score, 2) : null,
            'aiMode' => $evaluation?->evaluation_mode?->value,
            'aiEvaluatedAt' => $evaluation?->evaluated_at?->toIso8601String(),
            'aiReasoningSteps' => $this->reasoningSteps($evaluation),
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

    private function reference(Incident $incident): string
    {
        $year = $incident->opened_at?->year ?? Carbon::now()->year;

        return sprintf('INC-%d-%05d', $year, (int) $incident->id);
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
        return IncidentStatusPresenter::uiStatus(
            $incident->status?->code,
            $this->activeAssignment($incident) !== null,
        );
    }

    private function provider(?NormalizedEvent $event): string
    {
        return (string) ($event?->provider?->name ?? '—');
    }

    private function asset(Incident $incident): string
    {
        $asset = $incident->asset;

        if ($asset === null) {
            return '—';
        }

        if ($asset->code && $asset->name) {
            return "{$asset->code} · {$asset->name}";
        }

        return (string) ($asset->name ?? $asset->code ?? '—');
    }

    private function driver(Incident $incident): string
    {
        $driver = $incident->driver;

        if ($driver === null) {
            return '—';
        }

        return (string) ($driver->full_name
            ?? trim("{$driver->first_name} {$driver->last_name}")
            ?: '—');
    }

    /**
     * @param  Collection<int, User>  $users
     * @return array{id: int, name: string, initials: string}|null
     */
    /**
     * Quién tiene tomado el incidente, con la misma forma que `assignee` para
     * que la bandeja pueda reutilizar el avatar de iniciales. El usuario sale
     * de la colección ya resuelta: no dispara consulta por fila.
     *
     * @param  Collection<int, User>  $users
     * @return array<string, mixed>|null
     */
    private function claimedBy(Incident $incident, Collection $users): ?array
    {
        if ($incident->claimed_by_user_id === null) {
            return null;
        }

        $name = (string) ($users->get((int) $incident->claimed_by_user_id)?->name ?? 'Usuario');

        return [
            'id' => (int) $incident->claimed_by_user_id,
            'name' => $name,
            'initials' => $this->initials($name),
        ];
    }

    private function assignee(Incident $incident, Collection $users): ?array
    {
        $assignment = $this->activeAssignment($incident);

        if ($assignment === null || $assignment->assigned_to_type !== AssigneeType::User) {
            return null;
        }

        $user = $users->get((int) $assignment->assigned_to_id);
        $name = (string) ($user?->name ?? 'Usuario');

        return [
            'id' => (int) $assignment->assigned_to_id,
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
        if ($incident->sla_due_at !== null && $incident->opened_at !== null) {
            return (int) $incident->opened_at->diffInSeconds($incident->sla_due_at);
        }

        $seconds = $incident->priority?->sla_seconds
            ?? $incident->relatedEvent?->eventSeverity?->response_sla_seconds;

        return (int) ($seconds ?: self::DEFAULT_SLA_SECONDS);
    }

    private function slaSeconds(Incident $incident, CarbonInterface $now): int
    {
        if ($incident->isTerminal()) {
            return 0;
        }

        $opened = $incident->opened_at;

        if ($opened === null) {
            return $this->slaTotal($incident);
        }

        return $this->slaTotal($incident) - (int) $opened->diffInSeconds($now);
    }

    private function ageMin(Incident $incident, CarbonInterface $now): int
    {
        $opened = $incident->opened_at;

        if ($opened === null) {
            return 0;
        }

        return (int) $opened->diffInMinutes($now);
    }

    private function eventType(Incident $incident): string
    {
        return (string) ($incident->relatedEvent?->eventType?->code
            ?? $incident->type?->code
            ?? '—');
    }

    private function location(?NormalizedEvent $event): string
    {
        $context = $event?->context_json ?? [];
        $location = $context['location'] ?? ($event?->payload_normalized_json['location'] ?? null);

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

    private function aiConfidence(?AIEventEvaluation $evaluation): float
    {
        return round((float) ($evaluation?->confidence_score ?? 0), 2);
    }

    private function aiDecision(?AIEventEvaluation $evaluation): string
    {
        if ($evaluation === null) {
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
        return (string) ($evaluation?->explanation_text ?? 'Sin evaluación de IA disponible.');
    }

    private function model(?AIEventEvaluation $evaluation): string
    {
        if ($evaluation === null) {
            return '—';
        }

        $model = $evaluation->model_used ?: '—';
        $version = $evaluation->evaluation_version;

        return $version ? "{$model} · v{$version}" : (string) $model;
    }

    private function latencyMs(?AIEventEvaluation $evaluation): int
    {
        $summary = $evaluation?->evidence_summary_json ?? [];
        $signals = $evaluation?->signals_json ?? [];

        return (int) ($summary['latency_ms'] ?? $signals['latency_ms'] ?? 0);
    }

    /**
     * @param  Collection<int, User>  $users
     * @return array{type: string, entryType: string|null, actor: string, text: string, ts: string, tsIso: string|null, sub: string|null, meta: array{result: string|null, confidence: float|null}|null}
     */
    private function timelineEntry(IncidentTimeline $entry, Collection $users): array
    {
        $type = match ($entry->entry_type) {
            TimelineEntryType::Created, TimelineEntryType::Escalated => 'critical',
            TimelineEntryType::SlaBreached => 'sla',
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
            default => 'Sistema',
        };

        $payload = is_array($entry->payload_json) ? $entry->payload_json : [];
        $result = $payload['result'] ?? null;
        $confidence = $payload['confidence_score'] ?? null;

        return [
            'type' => $type,
            'entryType' => $entry->entry_type?->value,
            'actor' => (string) $actor,
            'text' => $this->timelineText($entry, $payload),
            'ts' => $entry->occurred_at?->format('H:i:s') ?? '',
            'tsIso' => $entry->occurred_at?->toIso8601String(),
            'sub' => $entry->description !== null
                ? (self::LEGACY_DESCRIPTION_ES[$entry->description] ?? (string) $entry->description)
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
            default => null,
        };

        if ($base === null) {
            return (string) ($entry->title ?? '');
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
        $summary = trim((string) ($incident->summary ?? ''));

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
     * @return array{ts: string, eventId: int, eventType: string, asset: string, relationType: string|null, severity: string|null}|null
     */
    private function relatedLink(IncidentEventLink $link): ?array
    {
        $event = $link->normalizedEvent;

        if ($event === null) {
            return null;
        }

        return [
            'ts' => $event->occurred_at?->format('H:i:s') ?? '',
            'eventId' => (int) $event->id,
            'eventType' => (string) ($event->eventType?->code ?? '—'),
            'asset' => (string) ($event->asset?->code ?? $event->asset?->name ?? '—'),
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
        $user = $users->get((int) $comment->user_id);
        $name = (string) ($user?->name ?? 'Usuario');

        $visibility = match ($comment->visibility) {
            CommentVisibility::TenantVisible => 'tenant',
            CommentVisibility::AuditOnly => 'audit',
            default => 'internal',
        };

        return [
            'authorInitials' => $this->initials($name),
            'authorName' => $name,
            'visibility' => $visibility,
            'body' => (string) ($comment->comment ?? ''),
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

        $label = (string) ($evidence->title ?? ucfirst(str_replace('_', ' ', $evidence->evidence_type->value)));

        return [
            'label' => $label,
            'sub' => (string) ($evidence->description ?? ''),
            'type' => $type,
            'fileUrl' => $evidence->file_url,
        ];
    }

    /**
     * @return array{weather: string, traffic: string, driverRisk: int, geofenceStatus: string, drivingHours: string}
     */
    private function operationalContext(Incident $incident): array
    {
        $context = $incident->relatedEvent?->context_json ?? [];
        $risk = $context['driver_risk']
            ?? $incident->driver?->riskProfile?->risk_score
            ?? 0;

        return [
            'weather' => (string) ($context['weather'] ?? '—'),
            'traffic' => (string) ($context['traffic'] ?? '—'),
            'driverRisk' => (int) $risk,
            'geofenceStatus' => (string) ($context['geofence_status'] ?? '—'),
            'drivingHours' => (string) ($context['driving_hours'] ?? '—'),
        ];
    }

    private function initials(string $name): string
    {
        $parts = preg_split('/\s+/', trim($name)) ?: [];
        $parts = array_values(array_filter($parts));

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
