<?php

namespace App\Domains\Incidents\Actions;

use App\Domains\AI\Models\AIEventEvaluation;
use App\Domains\Assets\Jobs\DetectOfflineAssetsJob;
use App\Domains\Context\Models\EventContextSnapshot;
use App\Domains\Incidents\Enums\EventRelationType;
use App\Domains\Incidents\Enums\EvidenceSourceType;
use App\Domains\Incidents\Enums\EvidenceType;
use App\Domains\Incidents\Enums\IncidentCreatorType;
use App\Domains\Incidents\Enums\IncidentSourceType;
use App\Domains\Incidents\Enums\IncidentStatusCode;
use App\Domains\Incidents\Enums\IncidentTypeCode;
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
use App\Domains\TenantConfig\Actions\ResolveIncidentSla;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

class CreateIncidentFromEvent
{
    private const int DEDUP_LOCK_SECONDS = 30;

    private const int DEDUP_LOCK_WAIT_SECONDS = 15;

    /** Minutos en los que varios device_offline del tenant cuentan como ráfaga. */
    public const int OFFLINE_BURST_WINDOW_MINUTES = 10;

    /** device_offline de activos distintos dentro de la ventana que abren el incidente agregado. */
    public const int OFFLINE_BURST_THRESHOLD = 3;

    public function __construct(
        private readonly AppendTimelineEntry $appendTimelineEntry,
        private readonly LinkEventToIncident $linkEventToIncident,
        private readonly AddIncidentEvidence $addIncidentEvidence,
        private readonly RecordUsageEvent $recordUsageEvent,
        private readonly ApplyExternalResolution $applyExternalResolution,
        private readonly ResolveIncidentSla $resolveIncidentSla,
    ) {}

    /**
     * Creates a new incident triggered by a normalized event. If an open incident of the same
     * incident type already exists for the same asset/driver inside the dedup window, links the
     * event to that incident instead of creating a new one (raising its priority if the new
     * event is more severe).
     *
     * @param  array<string, mixed>  $context  Optional payload with `decision_id`, `priority_code`, `incident_type_code`, `title`, `summary`.
     */
    public function execute(NormalizedEvent $event, array $context = []): Incident
    {
        $incidentType = $this->resolveIncidentType($context['incident_type_code'] ?? null, $event);
        $lockKey = $this->dedupLockKey($event, $incidentType);

        if ($lockKey === null) {
            return $this->createOrLink($event, $context, $incidentType);
        }

        // Dos eventos casi simultáneos del mismo activo (o una ráfaga de
        // device_offline del tenant) no pueden abrir dos incidentes: el
        // chequeo de duplicado y la creación van bajo el mismo candado. La
        // clave incluye el team_id (§2.1.7).
        return Cache::lock($lockKey, self::DEDUP_LOCK_SECONDS)
            ->block(
                (int) config('incidents.dedup_lock_wait_seconds', self::DEDUP_LOCK_WAIT_SECONDS),
                fn () => $this->createOrLink($event, $context, $incidentType),
            );
    }

    /**
     * @param  array<string, mixed>  $context
     */
    private function createOrLink(NormalizedEvent $event, array $context, IncidentType $incidentType): Incident
    {
        return DB::transaction(function () use ($event, $context, $incidentType) {
            $teamId = (int) $event->team_id;
            $priority = $this->resolvePriority($context['priority_code'] ?? null, $incidentType);

            // Solo se deduplica contra un incidente abierto DEL MISMO TIPO: un
            // pánico no puede quedar absorbido como evento de soporte de, por
            // ejemplo, un movimiento fuera de horario del mismo activo (sin
            // IncidentCreated, sin notificaciones, sin subir prioridad).
            $existing = $this->findOpenDuplicate($event, $incidentType);

            if ($existing !== null) {
                $this->linkEventToIncident->execute(
                    $existing,
                    $event,
                    EventRelationType::SupportingEvent,
                );

                $this->raisePriorityIfHigher($existing, $priority, $event);

                return $existing;
            }

            // Ráfaga de device_offline en el tenant (caída de red, gateway
            // compartido, corte del proveedor): un solo incidente agregado en
            // vez de un incidente —y una notificación— por activo.
            $burst = $this->offlineBurst($event, $incidentType);

            if ($burst instanceof Incident) {
                $this->linkEventToIncident->execute($burst, $event, EventRelationType::SupportingEvent);

                return $burst;
            }

            $aggregateBurst = $burst === true;
            $openStatus = IncidentStatus::query()->where('code', IncidentStatusCode::Open->value)->firstOrFail();

            $sourceType = isset($context['decision_id'])
                ? IncidentSourceType::AiDecision
                : IncidentSourceType::NormalizedEvent;

            $title = $aggregateBurst
                ? 'Varios dispositivos sin conexión'
                : ($context['title'] ?? $this->buildTitle($event, $incidentType->name));
            $summary = $aggregateBurst
                ? "Varios activos dejaron de reportar en pocos minutos (evento #{$event->id} y siguientes): probable caída de red o del proveedor."
                : ($context['summary'] ?? $this->buildSummary($event));

            $openedAt = Carbon::instance($event->occurred_at ?? now());
            $slaSeconds = $this->resolveIncidentSla->execute($teamId, $priority->id);
            // El SLA corre desde que SAM se entera, no desde que ocurrió: un
            // evento atrasado o de backfill no puede nacer ya vencido y
            // escalar (SMS/llamadas) en el mismo segundo en que se crea.
            $slaDueAt = $slaSeconds !== null
                ? $openedAt->copy()->max(now())->addSeconds($slaSeconds)
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
                'asset_id' => $aggregateBurst ? null : $event->asset_id,
                'driver_id' => $aggregateBurst ? null : $event->driver_id,
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
                title: 'Incidente creado',
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

    /**
     * Un evento de soporte más grave que el incidente al que se une sube la
     * prioridad del incidente (nunca la baja) y lo deja en la línea de tiempo.
     */
    private function raisePriorityIfHigher(Incident $incident, IncidentPriority $candidate, NormalizedEvent $event): void
    {
        $current = IncidentPriority::query()->find($incident->incident_priority_id);

        if ($current !== null && (int) $candidate->level <= (int) $current->level) {
            return;
        }

        $incident->update(['incident_priority_id' => $candidate->id]);

        $this->appendTimelineEntry->execute(
            incident: $incident,
            entryType: TimelineEntryType::PriorityChanged,
            actorType: TimelineActorType::System,
            title: 'Prioridad elevada por un nuevo evento',
            description: sprintf(
                'El evento #%d, vinculado a este incidente, es más grave: la prioridad sube de %s a %s.',
                $event->id,
                $current?->name ?? 'sin prioridad',
                $candidate->name,
            ),
            payload: [
                'previous_priority_id' => $current?->id,
                'new_priority_id' => $candidate->id,
                'normalized_event_id' => $event->id,
            ],
        );
    }

    /**
     * Incidente abierto del mismo tipo y del mismo activo/conductor que
     * sigue "vivo": abierto dentro de la ventana o con algún evento vinculado
     * dentro de ella. Un flujo continuo de eventos (p. ej. device_offline cada
     * hora de un activo parado) extiende la ventana y queda en un solo
     * incidente; un evento tras un silencio largo abre uno nuevo.
     */
    private function findOpenDuplicate(NormalizedEvent $event, IncidentType $incidentType): ?Incident
    {
        if ($event->asset_id === null && $event->driver_id === null) {
            return null;
        }

        $threshold = $this->windowStart($event, (int) config('incidents.duplicate_window_minutes', 30));

        return Incident::query()
            ->where('team_id', $event->team_id)
            ->where('incident_type_id', $incidentType->id)
            ->whereHas('status', fn ($q) => $q->where('is_terminal', false))
            ->where(function ($q) use ($event) {
                if ($event->asset_id !== null) {
                    $q->orWhere('asset_id', $event->asset_id);
                }
                if ($event->driver_id !== null) {
                    $q->orWhere('driver_id', $event->driver_id);
                }
            })
            ->where(fn ($q) => $this->activeSince($q, $threshold))
            ->orderByDesc('opened_at')
            ->first();
    }

    /**
     * Correlador de ráfagas de device_offline por tenant. Devuelve:
     *  - el incidente agregado abierto al que vincular este evento;
     *  - `true` cuando este evento completa el umbral y debe abrir el
     *    incidente agregado;
     *  - `null` cuando no hay ráfaga (se crea el incidente normal).
     *
     * El incidente agregado se reconoce por no tener activo ni conductor y
     * nacer de un evento device_offline (esos eventos siempre traen activo).
     */
    private function offlineBurst(NormalizedEvent $event, IncidentType $incidentType): Incident|bool|null
    {
        if (! $this->isDeviceOffline($event)) {
            return null;
        }

        $threshold = $this->windowStart($event, (int) config('incidents.offline_burst_window_minutes', self::OFFLINE_BURST_WINDOW_MINUTES));

        $base = fn () => Incident::query()
            ->where('team_id', $event->team_id)
            ->where('incident_type_id', $incidentType->id)
            ->whereHas('status', fn ($q) => $q->where('is_terminal', false))
            ->whereHas('relatedEvent.eventType', fn ($q) => $q->where('code', DetectOfflineAssetsJob::EVENT_TYPE_CODE));

        $aggregate = $base()
            ->whereNull('asset_id')
            ->whereNull('driver_id')
            ->where(fn ($q) => $this->activeSince($q, $threshold))
            ->orderByDesc('opened_at')
            ->first();

        if ($aggregate !== null) {
            return $aggregate;
        }

        $recentSingles = $base()
            ->whereNotNull('asset_id')
            ->where('opened_at', '>=', $threshold)
            ->count();

        $burstThreshold = max(2, (int) config('incidents.offline_burst_threshold', self::OFFLINE_BURST_THRESHOLD));

        return $recentSingles + 1 >= $burstThreshold ? true : null;
    }

    /**
     * @param  Builder<Incident>  $query
     */
    private function activeSince(Builder $query, Carbon $threshold): void
    {
        $query->where('opened_at', '>=', $threshold)
            ->orWhereHas('eventLinks.normalizedEvent', fn ($q) => $q->where('occurred_at', '>=', $threshold));
    }

    private function windowStart(NormalizedEvent $event, int $minutes): Carbon
    {
        return Carbon::instance($event->occurred_at ?? now())->subMinutes($minutes);
    }

    private function isDeviceOffline(NormalizedEvent $event): bool
    {
        $eventType = $event->relationLoaded('eventType') ? $event->eventType : $event->eventType()->first();

        return $eventType?->code === DetectOfflineAssetsJob::EVENT_TYPE_CODE;
    }

    private function dedupLockKey(NormalizedEvent $event, IncidentType $incidentType): ?string
    {
        $teamId = (int) $event->team_id;

        if ($this->isDeviceOffline($event)) {
            return "incident_dedup:{$teamId}:{$incidentType->id}:offline_burst";
        }

        if ($event->asset_id === null && $event->driver_id === null) {
            return null;
        }

        return "incident_dedup:{$teamId}:{$incidentType->id}:a{$event->asset_id}:d{$event->driver_id}";
    }

    /**
     * Event-type codes whose incident type uses a different code 1:1.
     */
    private const EVENT_TYPE_INCIDENT_ALIASES = [
        'panic_button' => IncidentTypeCode::PanicEmergency,
        'geofence_exit' => IncidentTypeCode::GeofenceBreach,
        'geofence_entry' => IncidentTypeCode::GeofenceBreach,
        'tampering' => IncidentTypeCode::EmergencyAlert,
    ];

    /**
     * Per-category buckets when no specific incident type matches the event.
     */
    private const CATEGORY_INCIDENT_FALLBACKS = [
        'emergency' => IncidentTypeCode::EmergencyAlert,
        'safety' => IncidentTypeCode::SafetyViolation,
        'compliance' => IncidentTypeCode::ComplianceViolation,
        'operational' => IncidentTypeCode::OperationalAlert,
        'maintenance' => IncidentTypeCode::OperationalAlert,
    ];

    /**
     * Resolve the incident type by trying, in order: the explicit code from
     * the decision context, the event-type code itself, a 1:1 alias, the
     * event-category bucket, and finally the generic `other` type. Picking an
     * arbitrary type by id is never acceptable here — that once turned a
     * speeding event into a "Panic Emergency" because panic happened to be
     * the first row.
     */
    private function resolveIncidentType(?string $code, NormalizedEvent $event): IncidentType
    {
        $eventType = $event->relationLoaded('eventType')
            ? $event->eventType
            : $event->eventType()->with('category')->first();

        $eventTypeCode = $eventType?->code;

        $candidates = array_values(array_unique(array_filter([
            $code,
            $eventTypeCode,
            (self::EVENT_TYPE_INCIDENT_ALIASES[$eventTypeCode] ?? null)?->value,
            (self::CATEGORY_INCIDENT_FALLBACKS[$eventType?->category?->code] ?? null)?->value,
            IncidentTypeCode::Other->value,
        ])));

        foreach ($candidates as $candidate) {
            $type = IncidentType::query()->where('code', $candidate)->where('is_active', true)->first();

            if ($type !== null) {
                return $type;
            }
        }

        // Catalog predates the generic buckets (seeder not yet run): keep the
        // legacy any-active-type resort over failing incident creation.
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

    /**
     * Build the human-facing incident title. Prefer the specific normalized
     * event-type name (already localized to Spanish in the catalog, e.g.
     * "Exceso de velocidad") over the generic incident-type bucket name
     * ("Safety Violation"); fall back to the bucket name only when the event
     * has no catalogued type.
     */
    private function buildTitle(NormalizedEvent $event, string $typeName): string
    {
        $eventType = $event->relationLoaded('eventType')
            ? $event->eventType
            : $event->eventType()->first();

        $label = $eventType?->name ?: $typeName;

        $assetSegment = $event->asset_id !== null ? " — activo #{$event->asset_id}" : '';

        return $label.$assetSegment;
    }

    private function buildSummary(NormalizedEvent $event): string
    {
        $occurredAt = ($event->occurred_at ?? now())->format('d/m/Y H:i');

        return "Creado automáticamente a partir del evento normalizado #{$event->id} ocurrido el {$occurredAt}.";
    }

    private function autoAttachEvidence(Incident $incident, NormalizedEvent $event): void
    {
        $contextSnapshot = EventContextSnapshot::query()
            ->where('normalized_event_id', $event->id)
            ->first();

        if ($contextSnapshot !== null) {
            $this->addIncidentEvidence->execute(
                incident: $incident,
                evidenceType: EvidenceType::EventSnapshot,
                sourceType: EvidenceSourceType::EventContext,
                sourceReferenceId: (int) $contextSnapshot->id,
                title: 'Instantánea de contexto del evento',
                metadata: [
                    'context_version' => $contextSnapshot->context_version,
                    'snapshot_id' => $contextSnapshot->id,
                ],
            );
        }

        $aiEvaluation = AIEventEvaluation::query()
            ->where('normalized_event_id', $event->id)
            ->latest('id')
            ->first();

        if ($aiEvaluation !== null) {
            $this->addIncidentEvidence->execute(
                incident: $incident,
                evidenceType: EvidenceType::AiExplanation,
                sourceType: EvidenceSourceType::AiEvaluation,
                sourceReferenceId: (int) $aiEvaluation->id,
                title: 'Explicación de la evaluación de IA',
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
