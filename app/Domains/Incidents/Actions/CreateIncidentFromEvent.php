<?php

namespace App\Domains\Incidents\Actions;

use App\Domains\AI\Models\AIEventEvaluation;
use App\Domains\Assets\Jobs\DetectOfflineAssetsJob;
use App\Domains\Assets\Models\Asset;
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
use App\Domains\Normalization\Models\EventType;
use App\Domains\Normalization\Models\NormalizedEvent;
use App\Domains\TenantConfig\Actions\ResolveIncidentSla;
use App\Support\LoggableCode;
use App\Support\PipelineTrace;
use App\Support\SystemLog;
use Carbon\CarbonInterface;
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
        private readonly RecordIncidentWorkflowUsage $recordIncidentWorkflowUsage,
        private readonly ApplyExternalResolution $applyExternalResolution,
        private readonly ResolveIncidentSla $resolveIncidentSla,
        private readonly AssessIncidentLateArrival $assessIncidentLateArrival,
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
            $resolved = $this->resolvePriority($context['priority_code'] ?? null, $incidentType);
            $priority = $resolved['priority'];

            SystemLog::ok('incidents.priority.resolved',
                input: ['normalized_event_id' => $event->id, 'incident_type_id' => $incidentType->id],
                calc: [
                    'source' => $resolved['source'],
                    'requested_code' => LoggableCode::guard($context['priority_code'] ?? null),
                    'requested_found' => $resolved['requested_found'],
                    'type_default_priority_id' => $incidentType->default_priority_id,
                ],
                result: ['priority_code' => $priority->code, 'priority_level' => (int) $priority->level],
            );

            // Solo se deduplica contra un incidente abierto DEL MISMO TIPO: un
            // pánico no puede quedar absorbido como evento de soporte de, por
            // ejemplo, un movimiento fuera de horario del mismo activo (sin
            // IncidentCreated, sin notificaciones, sin subir prioridad).
            $dedupWindowMinutes = (int) config('incidents.duplicate_window_minutes', 30);
            $dedupWindowStart = $this->windowStart($event, $dedupWindowMinutes);
            $dedupWindowEnd = $this->windowEnd($event, $dedupWindowMinutes);
            $existing = $this->findOpenDuplicate($event, $incidentType, $dedupWindowStart, $dedupWindowEnd);

            if ($existing !== null) {
                $link = $this->linkEventToIncident->execute(
                    $existing,
                    $event,
                    EventRelationType::SupportingEvent,
                );

                $raise = $this->raisePriorityIfHigher($existing, $priority, $event);

                // Derivado tras el match, sin repetir la query: qué rama del
                // orWhere (activo/conductor) y de activeWithin lo sostiene.
                $matchedOn = $event->asset_id !== null && $existing->asset_id === $event->asset_id ? 'asset' : 'driver';
                $matchBasis = $existing->opened_at->between($dedupWindowStart, $dedupWindowEnd)
                    ? 'opened_in_window'
                    : 'linked_event_in_window';

                $dedupLine = [
                    'input' => ['normalized_event_id' => $event->id, 'incident_type_id' => $incidentType->id],
                    'calc' => [
                        'window_minutes' => $dedupWindowMinutes,
                        'window_start' => $dedupWindowStart->toIso8601String(),
                        'window_end' => $dedupWindowEnd->toIso8601String(),
                        'matched_on' => $matchedOn,
                        'match_basis' => $matchBasis,
                    ],
                    'result' => [
                        'existing_incident_id' => $existing->id,
                        'link_created' => $link->wasRecentlyCreated,
                        'priority_raised' => $raise['raised'],
                        'previous_priority_code' => $raise['previous_priority_code'],
                        'new_priority_code' => $raise['raised'] ? $raise['candidate_priority_code'] : null,
                    ],
                ];
                DB::afterCommit(fn () => SystemLog::ok('incidents.dedup.linked', ...$dedupLine));

                return $existing;
            }

            // Ráfaga de device_offline en el tenant (caída de red, gateway
            // compartido, corte del proveedor): un solo incidente agregado en
            // vez de un incidente —y una notificación— por activo.
            ['outcome' => $burst, 'calc' => $burstCalc] = $this->offlineBurst($event, $incidentType);

            if ($burst instanceof Incident) {
                $link = $this->linkEventToIncident->execute($burst, $event, EventRelationType::SupportingEvent);

                $burstLine = [
                    'input' => ['normalized_event_id' => $event->id],
                    // Con agregado encontrado offlineBurst siempre trae calc.
                    'calc' => [...($burstCalc ?? []), 'branch' => 'linked_to_aggregate'],
                    'result' => ['aggregate_incident_id' => $burst->id, 'link_created' => $link->wasRecentlyCreated],
                ];
                DB::afterCommit(fn () => SystemLog::ok('incidents.offline_burst.aggregated', ...$burstLine));

                return $burst;
            }

            if ($burstCalc !== null) {
                $burstCalc['branch'] = $burst === true ? 'opened_aggregate' : 'below_threshold';
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
            $sla = $this->resolveIncidentSla->resolve($teamId, $priority->id);
            $slaSeconds = $sla['sla_seconds'];
            // El SLA corre desde que SAM se entera, no desde que ocurrió: un
            // evento atrasado o de backfill no puede nacer ya vencido y
            // escalar (SMS/llamadas) en el mismo segundo en que se crea.
            $now = now();
            $slaDueAt = $slaSeconds !== null
                ? $openedAt->copy()->max($now)->addSeconds($slaSeconds)
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

            $slaInput = ['incident_id' => $incident->id, 'incident_priority_id' => $priority->id, 'team_id' => $teamId];

            // SLA watchdog: one delayed job instead of a per-minute cron. It
            // no-ops if the incident was acknowledged or closed by then.
            if ($slaDueAt !== null) {
                CheckIncidentAcknowledgementJob::dispatch($incident->id)
                    ->delay($slaDueAt)
                    ->afterCommit();

                // sla_due_at = max(opened_at, now_at) + sla_seconds.
                $slaLine = [
                    'input' => $slaInput,
                    'calc' => [
                        'sla_seconds' => $slaSeconds,
                        'sla_source' => $sla['sla_source'],
                        'opened_at' => $openedAt->toIso8601String(),
                        'now_at' => $now->toIso8601String(),
                        'base_at' => $openedAt->copy()->max($now)->toIso8601String(),
                        'base_source' => $now->gt($openedAt) ? 'now' : 'opened_at',
                        'late_by_seconds' => max(0, (int) $openedAt->diffInSeconds($now, false)),
                        'backfill_adjusted' => $now->gt($openedAt),
                    ],
                    'result' => ['sla_due_at' => $slaDueAt->toIso8601String(), 'watchdog_requested' => true],
                ];
                DB::afterCommit(fn () => SystemLog::ok('incidents.sla.calculated', ...$slaLine));
            } else {
                DB::afterCommit(fn () => SystemLog::skipped('incidents.sla.calculated',
                    reason: 'no_sla_for_priority',
                    input: $slaInput,
                    calc: ['sla_source' => 'none'],
                    result: ['watchdog_requested' => false],
                ));
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

            $this->flagLateArrival($incident, $event, $now);

            $rootLink = $this->linkEventToIncident->execute(
                $incident,
                $event,
                EventRelationType::RootTrigger,
            );

            if ($aggregateBurst) {
                $burstLine = [
                    'input' => ['normalized_event_id' => $event->id],
                    'calc' => $burstCalc,
                    'result' => ['aggregate_incident_id' => $incident->id, 'link_created' => $rootLink->wasRecentlyCreated],
                ];
                DB::afterCommit(fn () => SystemLog::ok('incidents.offline_burst.aggregated', ...$burstLine));
            }

            $this->autoAttachEvidence($incident, $event);

            // An event that arrives already resolved at the provider still opens
            // its incident (a cancelled panic can be coercion) — annotate only,
            // never auto-close on creation regardless of the tenant setting.
            if (($event->payload_normalized_json['is_resolved'] ?? null) === true) {
                $this->applyExternalResolution->execute($incident, $event, allowClose: false);
            }

            // El cobro nunca tumba la apertura: savepoint propio y no fatal.
            $usageRecorded = $this->recordIncidentWorkflowUsage->execute($incident, [
                'incident_id' => $incident->id,
                'source_type' => $sourceType->value,
                'normalized_event_id' => $event->id,
            ]);

            $fresh = $incident->freshOrFail(['type', 'status', 'priority']);

            PipelineTrace::add(['incident_id' => $fresh->id]);

            // Registrado antes de IncidentCreated: en el commit sale antes que
            // las líneas de los listeners (que corren tras el commit).
            $createdLine = [
                'input' => [
                    'normalized_event_id' => $event->id,
                    'decision_id' => $context['decision_id'] ?? null,
                    'source_type' => $sourceType->value,
                ],
                'calc' => [
                    'fast_path' => ($context['metadata']['emergency_fast_path'] ?? false) === true,
                    'dedup_checked' => $event->asset_id !== null || $event->driver_id !== null,
                    'dedup_window_minutes' => $dedupWindowMinutes,
                    'offline_burst' => $burstCalc,
                    'aggregate_burst' => $aggregateBurst,
                    'resolved_on_arrival' => ($event->payload_normalized_json['is_resolved'] ?? null) === true,
                ],
                'result' => [
                    'incident_id' => $fresh->id,
                    'incident_type_code' => $fresh->type?->code,
                    'priority_code' => $fresh->priority?->code,
                    'status_code' => $fresh->status?->code,
                    'asset_id' => $fresh->asset_id,
                    'driver_id' => $fresh->driver_id,
                    'usage_event_key' => RecordIncidentWorkflowUsage::eventKey((int) $fresh->id),
                    'usage_recorded' => $usageRecorded,
                ],
            ];
            DB::afterCommit(fn () => SystemLog::ok('incidents.incident.created', ...$createdLine));

            // Efectos y socket, sólo tras el commit: IncidentCreated es
            // ShouldDispatchAfterCommit y cada listener corre aislado (su
            // fallo no revierte el incidente ni frena a los demás). El socket
            // se registra primero: la UI no espera a los efectos en línea.
            DB::afterCommit(fn () => broadcast(IncidentCreatedBroadcast::fromModel($fresh)));
            IncidentCreated::dispatch($fresh);

            return $fresh;
        });
    }

    /**
     * Un evento que se abre tarde (rescate, webhook atrasado, cursor viejo)
     * deja el retraso en `metadata_json.late_arrival` y una entrada explícita
     * en la línea de tiempo; NotifyOnIncidentCreated lo lleva a los avisos.
     * Nunca cambia prioridad, SLA ni canales: un pánico tardío sigue siendo
     * emergencia.
     */
    private function flagLateArrival(Incident $incident, NormalizedEvent $event, CarbonInterface $now): void
    {
        $assessment = $this->assessIncidentLateArrival->assess($event, $now);
        $input = ['incident_id' => $incident->id, 'normalized_event_id' => $event->id];
        $calc = $assessment['calc'];

        if (! $assessment['late']) {
            DB::afterCommit(fn () => SystemLog::ok('incidents.late_arrival.assessed', input: $input, calc: $calc, result: ['late' => false], debug: true));

            return;
        }

        $notice = $assessment['notice'];

        $incident->forceFill([
            'metadata_json' => array_merge($incident->metadata_json ?? [], ['late_arrival' => $notice]),
        ])->save();

        $entry = $this->appendTimelineEntry->execute(
            incident: $incident,
            entryType: TimelineEntryType::LateArrival,
            actorType: TimelineActorType::System,
            title: $notice['timeline_title'],
            description: $notice['timeline_description'],
            payload: [
                'normalized_event_id' => $event->id,
                'delay_seconds' => $notice['delay_seconds'],
                'receive_delay_seconds' => $notice['receive_delay_seconds'],
                'cause' => $notice['cause'],
                'rescued' => $notice['rescued'],
                'reprocess_attempts' => $notice['reprocess_attempts'],
                'timezone' => $notice['timezone'],
            ],
            occurredAt: $now,
        );

        $result = ['late' => true, 'timeline_entry_id' => $entry->id, 'metadata_key' => 'late_arrival'];

        DB::afterCommit(fn () => SystemLog::degraded('incidents.late_arrival.assessed', reason: 'late_arrival', input: $input, calc: $calc, result: $result));
    }

    /**
     * Un evento de soporte más grave que el incidente al que se une sube la
     * prioridad del incidente (nunca la baja) y lo deja en la línea de tiempo.
     */
    /**
     * @return array{raised: bool, previous_priority_code: ?string, previous_level: ?int, candidate_priority_code: string, candidate_level: int}
     */
    private function raisePriorityIfHigher(Incident $incident, IncidentPriority $candidate, NormalizedEvent $event): array
    {
        $current = IncidentPriority::query()->find($incident->incident_priority_id);

        $outcome = [
            'raised' => false,
            'previous_priority_code' => $current?->code,
            'previous_level' => $current !== null ? (int) $current->level : null,
            'candidate_priority_code' => $candidate->code,
            'candidate_level' => (int) $candidate->level,
        ];

        if ($current !== null && (int) $candidate->level <= (int) $current->level) {
            return $outcome;
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

        return ['raised' => true] + $outcome;
    }

    /**
     * Incidente abierto del mismo tipo y del mismo activo/conductor que
     * sigue "vivo": abierto dentro de la ventana o con algún evento vinculado
     * dentro de ella. Un flujo continuo de eventos (p. ej. device_offline cada
     * hora de un activo parado) extiende la ventana y queda en un solo
     * incidente; un evento tras un silencio largo abre uno nuevo.
     *
     * La ventana rodea la ocurrencia por ambos lados: un pánico entregado con
     * días de retraso (rescate, reintento del proveedor) no puede colgarse de
     * un incidente abierto DESPUÉS por otro pánico de la misma unidad.
     */
    private function findOpenDuplicate(NormalizedEvent $event, IncidentType $incidentType, Carbon $threshold, Carbon $until): ?Incident
    {
        if ($event->asset_id === null && $event->driver_id === null) {
            return null;
        }

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
            ->where(fn ($q) => $this->activeWithin($q, $threshold, $until))
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
     *
     * `calc` lleva los términos del cálculo (null si no es device_offline).
     *
     * @return array{outcome: Incident|bool|null, calc: ?array<string, mixed>}
     */
    private function offlineBurst(NormalizedEvent $event, IncidentType $incidentType): array
    {
        if (! $this->isDeviceOffline($event)) {
            return ['outcome' => null, 'calc' => null];
        }

        $windowMinutes = (int) config('incidents.offline_burst_window_minutes', self::OFFLINE_BURST_WINDOW_MINUTES);
        $threshold = $this->windowStart($event, $windowMinutes);

        $base = fn () => Incident::query()
            ->where('team_id', $event->team_id)
            ->where('incident_type_id', $incidentType->id)
            ->whereHas('status', fn ($q) => $q->where('is_terminal', false))
            ->whereHas('relatedEvent.eventType', fn ($q) => $q->where('code', DetectOfflineAssetsJob::EVENT_TYPE_CODE));

        $aggregate = $base()
            ->whereNull('asset_id')
            ->whereNull('driver_id')
            ->where(fn ($q) => $this->activeWithin($q, $threshold, $this->windowEnd($event, $windowMinutes)))
            ->orderByDesc('opened_at')
            ->first();

        $configuredThreshold = (int) config('incidents.offline_burst_threshold', self::OFFLINE_BURST_THRESHOLD);
        $burstThreshold = max(2, $configuredThreshold);

        $calc = [
            'window_minutes' => $windowMinutes,
            'window_start' => $threshold->toIso8601String(),
            'aggregate_found' => $aggregate !== null,
            'recent_singles_count' => null,
            'configured_threshold' => $configuredThreshold,
            'effective_threshold' => $burstThreshold,
        ];

        if ($aggregate !== null) {
            return ['outcome' => $aggregate, 'calc' => $calc];
        }

        $recentSingles = $base()
            ->whereNotNull('asset_id')
            ->where('opened_at', '>=', $threshold)
            ->count();

        $calc['recent_singles_count'] = $recentSingles;

        return ['outcome' => $recentSingles + 1 >= $burstThreshold ? true : null, 'calc' => $calc];
    }

    /**
     * @param  Builder<Incident>  $query
     */
    private function activeWithin(Builder $query, Carbon $from, Carbon $until): void
    {
        $query->whereBetween('opened_at', [$from, $until])
            ->orWhereHas('eventLinks.normalizedEvent', fn ($q) => $q->whereBetween('occurred_at', [$from, $until]));
    }

    private function windowStart(NormalizedEvent $event, int $minutes): Carbon
    {
        return Carbon::instance($event->occurred_at ?? now())->subMinutes($minutes);
    }

    private function windowEnd(NormalizedEvent $event, int $minutes): Carbon
    {
        return Carbon::instance($event->occurred_at ?? now())->addMinutes($minutes);
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
            self::aliasFor($eventTypeCode)?->value,
            self::categoryBucketFor($eventType?->category?->code)?->value,
            IncidentTypeCode::Other->value,
        ])));

        $tried = [];

        foreach ($candidates as $candidate) {
            $tried[] = $candidate;
            $type = IncidentType::query()->where('code', $candidate)->where('is_active', true)->first();

            if ($type !== null) {
                $this->logIncidentType($code, $event, $eventType, $candidates, $tried, $type, usedLastResort: false);

                return $type;
            }
        }

        // Catalog predates the generic buckets (seeder not yet run): keep the
        // legacy any-active-type resort over failing incident creation.
        $type = IncidentType::query()->where('is_active', true)->orderBy('id')->firstOrFail();

        $this->logIncidentType($code, $event, $eventType, $candidates, $tried, $type, usedLastResort: true);

        return $type;
    }

    /**
     * Un evento sin tipo (o tipo sin categoría) no tiene alias: se evita
     * indexar con null, deprecado desde PHP 8.5.
     */
    private static function aliasFor(?string $eventTypeCode): ?IncidentTypeCode
    {
        return $eventTypeCode === null ? null : (self::EVENT_TYPE_INCIDENT_ALIASES[$eventTypeCode] ?? null);
    }

    private static function categoryBucketFor(?string $categoryCode): ?IncidentTypeCode
    {
        return $categoryCode === null ? null : (self::CATEGORY_INCIDENT_FALLBACKS[$categoryCode] ?? null);
    }

    /**
     * @param  list<string>  $candidates
     * @param  list<string>  $tried
     */
    private function logIncidentType(?string $code, NormalizedEvent $event, ?EventType $eventType, array $candidates, array $tried, IncidentType $type, bool $usedLastResort): void
    {
        $eventTypeCode = $eventType?->code;

        SystemLog::ok('incidents.type.resolved',
            input: ['normalized_event_id' => $event->id],
            calc: [
                'requested_code' => LoggableCode::guard($code),
                'event_type_code' => $eventTypeCode,
                'alias_code' => self::aliasFor($eventTypeCode)?->value,
                'category_code' => $eventType?->category?->code,
                'category_bucket_code' => self::categoryBucketFor($eventType?->category?->code)?->value,
                'candidates' => array_map(LoggableCode::guard(...), $candidates),
                'tried' => array_map(LoggableCode::guard(...), $tried),
                'used_last_resort' => $usedLastResort,
            ],
            result: ['incident_type_id' => $type->id, 'incident_type_code' => $type->code],
        );
    }

    /**
     * @return array{priority: IncidentPriority, source: 'context_code'|'type_default'|'lowest_level_fallback', requested_found: bool}
     */
    private function resolvePriority(?string $code, IncidentType $type): array
    {
        if ($code !== null) {
            $priority = IncidentPriority::query()->where('code', $code)->first();
            if ($priority !== null) {
                return ['priority' => $priority, 'source' => 'context_code', 'requested_found' => true];
            }
        }

        if ($type->default_priority_id !== null) {
            $priority = IncidentPriority::query()->find($type->default_priority_id);
            if ($priority !== null) {
                return ['priority' => $priority, 'source' => 'type_default', 'requested_found' => false];
            }
        }

        return [
            'priority' => IncidentPriority::query()->orderBy('level')->firstOrFail(),
            'source' => 'lowest_level_fallback',
            'requested_found' => false,
        ];
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

        if ($event->asset_id === null) {
            return $label;
        }

        // The unit's name is what operators say, search and type into the
        // inbox ("T-879"); the internal id is only a fallback.
        $assetName = trim((string) Asset::query()->whereKey($event->asset_id)->value('name'));

        return $label.' — '.($assetName !== '' ? $assetName : "activo #{$event->asset_id}");
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
