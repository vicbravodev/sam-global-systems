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
     * @return array{type: string, actor: string, text: string, ts: string, sub: string|null}
     */
    private function timelineEntry(IncidentTimeline $entry, Collection $users): array
    {
        $type = match ($entry->entry_type) {
            TimelineEntryType::Created, TimelineEntryType::Escalated => 'critical',
            TimelineEntryType::Assigned => 'assign',
            TimelineEntryType::CommentAdded => 'comment',
            TimelineEntryType::EventLinked => 'webhook',
            default => 'system',
        };

        $actor = match ($entry->actor_type) {
            TimelineActorType::System => 'Sistema',
            TimelineActorType::Ai => 'SAM',
            TimelineActorType::Automation => 'Automatización',
            TimelineActorType::User => $users->get((int) $entry->actor_id)?->name ?? 'Usuario',
            default => 'Sistema',
        };

        return [
            'type' => $type,
            'actor' => (string) $actor,
            'text' => (string) ($entry->title ?? ''),
            'ts' => $entry->occurred_at?->format('H:i:s') ?? '',
            'sub' => $entry->description !== null ? (string) $entry->description : null,
        ];
    }

    /**
     * @return array{ts: string, eventType: string, asset: string, decision: string, severity: string|null}|null
     */
    private function relatedLink(IncidentEventLink $link): ?array
    {
        $event = $link->normalizedEvent;

        if ($event === null) {
            return null;
        }

        return [
            'ts' => $event->occurred_at?->format('H:i:s') ?? '',
            'eventType' => (string) ($event->eventType?->code ?? '—'),
            'asset' => (string) ($event->asset?->code ?? $event->asset?->name ?? '—'),
            'decision' => 'info',
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
