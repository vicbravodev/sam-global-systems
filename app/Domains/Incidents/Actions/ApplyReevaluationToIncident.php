<?php

namespace App\Domains\Incidents\Actions;

use App\Domains\AI\Enums\EventClassification;
use App\Domains\AI\Models\AIEventEvaluation;
use App\Domains\Decisions\Models\Decision;
use App\Domains\Incidents\Enums\TimelineActorType;
use App\Domains\Incidents\Enums\TimelineEntryType;
use App\Domains\Incidents\Models\Incident;
use App\Domains\Incidents\Models\IncidentEventLink;
use App\Domains\Incidents\Models\IncidentPriority;
use App\Domains\Incidents\Models\IncidentTimeline;
use App\Domains\Incidents\Support\IncidentUpdatedBroadcast;
use App\Domains\Normalization\Models\NormalizedEvent;
use App\Support\LoggableCode;
use App\Support\SystemLog;
use App\Support\TenantContext;
use Illuminate\Support\Facades\DB;

/**
 * Cada reevaluación de IA produce una nueva Decision para el MISMO evento.
 * En vez de abrir un incidente duplicado, esta Action actualiza el incidente
 * que ya existe para ese evento:
 *
 * - sube la prioridad si la nueva decisión es más grave (nunca la baja sola),
 * - apunta `related_decision_id` a la decisión más nueva,
 * - deja en el timeline "Reevaluación v{N}: {clasificación} ({confianza}%)",
 * - si la IA ahora sugiere falso positivo en un incidente abierto, lo avisa
 *   y lo deja abierto: cerrar es siempre decisión del operador.
 *
 * Idempotente por decisión: aplicar dos veces la misma decisión (o una más
 * vieja que la vigente) no hace nada.
 *
 * Corre dentro de EvaluateDecisionRules (vía el listener) o suelta (vía
 * CreateIncidentJob): todas sus líneas van por DB::afterCommit. Nunca registra
 * `decision_reason` ni el título de la línea de tiempo.
 */
class ApplyReevaluationToIncident
{
    /**
     * Prioridades de Decisions sin equivalente 1:1 en el catálogo de incidentes.
     */
    private const PRIORITY_ALIASES = [
        'normal' => 'medium',
        'urgent' => 'critical',
    ];

    private const CLASSIFICATION_LABELS = [
        'real_event' => 'evento real',
        'false_positive' => 'falso positivo',
        'noise' => 'ruido',
        'duplicate' => 'duplicado',
        'unclear' => 'no concluyente',
        'pending_evidence' => 'pendiente de evidencia',
    ];

    public function __construct(
        private readonly AppendTimelineEntry $appendTimelineEntry,
    ) {}

    /**
     * Incidente que ya existe para el evento (como origen o vinculado), o null.
     */
    public function findExistingFor(NormalizedEvent $event): ?Incident
    {
        return TenantContext::for($event->team_id, function () use ($event) {
            $linkedIncidentIds = IncidentEventLink::query()
                ->where('normalized_event_id', $event->id)
                ->pluck('incident_id');

            return Incident::query()
                ->where('team_id', $event->team_id)
                ->where(fn ($q) => $q
                    ->where('related_event_id', $event->id)
                    ->orWhereIn('id', $linkedIncidentIds))
                ->orderByDesc('id')
                ->first();
        });
    }

    /**
     * @return bool true si se aplicó; false si no había nada nuevo que aplicar.
     */
    public function execute(Incident $incident, Decision $decision): bool
    {
        if ((int) $decision->team_id !== (int) $incident->team_id) {
            // El incidente es de otro tenant: nunca su id.
            $decisionId = $decision->id;
            DB::afterCommit(fn () => SystemLog::skipped('incidents.reevaluation.applied',
                reason: 'team_mismatch',
                input: ['decision_id' => $decisionId],
                calc: ['team_matches' => false],
            ));

            return false;
        }

        $logInput = ['decision_id' => $decision->id, 'incident_id' => $incident->id];

        return TenantContext::for($incident->team_id, fn () => DB::transaction(function () use ($incident, $decision, $logInput) {
            $incident = Incident::query()->whereKey($incident->id)->lockForUpdate()->first();

            if ($incident === null) {
                DB::afterCommit(fn () => SystemLog::skipped('incidents.reevaluation.applied', reason: 'incident_missing', input: $logInput));

                return false;
            }

            $currentDecisionId = $incident->related_decision_id !== null ? (int) $incident->related_decision_id : null;

            // El evento puede ser el origen del incidente o un evento de
            // soporte vinculado (agrupado por activo/conductor). Sólo el de
            // origen mueve `related_decision_id`.
            $isRootEvent = (int) $incident->related_event_id === (int) $decision->normalized_event_id;

            if ($isRootEvent && $currentDecisionId !== null && $currentDecisionId >= (int) $decision->id) {
                DB::afterCommit(fn () => SystemLog::skipped('incidents.reevaluation.applied',
                    reason: 'decision_not_newer',
                    input: $logInput,
                    calc: ['current_decision_id' => $currentDecisionId, 'is_root_event' => true],
                ));

                return false;
            }

            $alreadyApplied = IncidentTimeline::query()
                ->where('incident_id', $incident->id)
                ->where('entry_type', TimelineEntryType::AiReevaluated)
                ->where('payload_json->decision_id', $decision->id)
                ->exists();

            if ($alreadyApplied) {
                DB::afterCommit(fn () => SystemLog::skipped('incidents.reevaluation.applied', reason: 'already_applied', input: $logInput));

                return false;
            }

            $evaluation = $decision->ai_evaluation_id !== null
                ? AIEventEvaluation::query()->where('team_id', $incident->team_id)->find($decision->ai_evaluation_id)
                : null;

            $isTerminal = $incident->isTerminal();
            $updates = $isRootEvent ? ['related_decision_id' => $decision->id] : [];

            $previousPriority = IncidentPriority::query()->find($incident->incident_priority_id);
            $newPriority = $this->resolvePriority($decision->priority_level?->value);
            $raisePriority = ! $isTerminal
                && $newPriority !== null
                && (int) $newPriority->level > (int) ($previousPriority->level ?? 0);

            if ($raisePriority) {
                $updates['incident_priority_id'] = $newPriority->id;
            }

            if ($updates !== []) {
                $incident->update($updates);
            }

            $version = $evaluation?->evaluation_version;
            $classification = $evaluation?->classification;
            $confidence = $evaluation?->confidence_score !== null
                ? (int) round(((float) $evaluation->confidence_score) * 100)
                : null;

            $this->appendTimelineEntry->execute(
                incident: $incident,
                entryType: TimelineEntryType::AiReevaluated,
                actorType: TimelineActorType::Ai,
                title: sprintf(
                    'Reevaluación v%s: %s (%s%%)',
                    $version ?? '?',
                    self::CLASSIFICATION_LABELS[$classification?->value] ?? ($classification?->value ?? 'sin clasificación'),
                    $confidence ?? '—',
                ),
                description: $decision->decision_reason,
                payload: [
                    'decision_id' => $decision->id,
                    'previous_decision_id' => $currentDecisionId,
                    'ai_evaluation_id' => $evaluation?->id,
                    'evaluation_version' => $version,
                    'classification' => $classification?->value,
                    'confidence_score' => $evaluation?->confidence_score,
                    'decision_code' => $decision->decision_code,
                ],
            );

            if ($raisePriority) {
                $this->appendTimelineEntry->execute(
                    incident: $incident,
                    entryType: TimelineEntryType::PriorityChanged,
                    actorType: TimelineActorType::Ai,
                    title: sprintf('Prioridad elevada por reevaluación: %s → %s', $previousPriority->name ?? '—', $newPriority->name),
                    payload: [
                        'previous_priority_id' => $previousPriority?->id,
                        'new_priority_id' => $newPriority->id,
                        'previous_level' => $previousPriority?->level,
                        'new_level' => $newPriority->level,
                        'decision_id' => $decision->id,
                    ],
                );
            }

            if (! $isTerminal && $classification === EventClassification::FalsePositive) {
                $this->appendTimelineEntry->execute(
                    incident: $incident,
                    entryType: TimelineEntryType::AiReevaluated,
                    actorType: TimelineActorType::Ai,
                    title: 'La IA sugiere falso positivo — requiere confirmación del operador',
                    payload: [
                        'decision_id' => $decision->id,
                        'ai_evaluation_id' => $evaluation?->id,
                        'suggestion' => 'false_positive',
                    ],
                );
            }

            broadcast(IncidentUpdatedBroadcast::fromModel($incident->fresh(['status', 'priority'])));

            $decisionPriorityCode = $decision->priority_level?->value;
            $appliedLine = [
                'input' => [
                    'incident_id' => $incident->id,
                    'decision_id' => $decision->id,
                    'decision_code' => LoggableCode::guard($decision->decision_code),
                ],
                'calc' => [
                    'is_root_event' => $isRootEvent,
                    'is_terminal' => $isTerminal,
                    'previous_decision_id' => $currentDecisionId,
                    'previous_priority_code' => $previousPriority?->code,
                    'previous_level' => $previousPriority?->level,
                    'decision_priority_code' => $decisionPriorityCode,
                    'mapped_priority_code' => $newPriority?->code,
                    'priority_alias_used' => $newPriority !== null && $newPriority->code !== $decisionPriorityCode,
                    'mapped_level' => $newPriority?->level,
                ],
                'result' => [
                    'priority_raised' => $raisePriority,
                    'related_decision_moved' => $isRootEvent,
                    'false_positive_notice' => ! $isTerminal && $classification === EventClassification::FalsePositive,
                    'evaluation_version' => $version,
                    'classification' => $classification?->value,
                ],
            ];
            DB::afterCommit(fn () => SystemLog::ok('incidents.reevaluation.applied', ...$appliedLine));

            return true;
        }));
    }

    private function resolvePriority(?string $code): ?IncidentPriority
    {
        if ($code === null) {
            return null;
        }

        return IncidentPriority::query()->where('code', $code)->first()
            ?? (isset(self::PRIORITY_ALIASES[$code])
                ? IncidentPriority::query()->where('code', self::PRIORITY_ALIASES[$code])->first()
                : null);
    }
}
