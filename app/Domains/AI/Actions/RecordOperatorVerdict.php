<?php

namespace App\Domains\AI\Actions;

use App\Domains\AI\Enums\OperatorVerdict;
use App\Domains\AI\Models\AIEventEvaluation;
use App\Domains\Audit\Actions\RecordAuditEntry;
use App\Domains\Audit\Enums\AuditActorType;
use App\Domains\Audit\Enums\AuditCategory;

/**
 * Registra el veredicto humano (human-in-the-loop) del operador sobre la
 * evaluación de IA más reciente de un evento normalizado. Es la etiqueta que
 * alimenta las métricas de concordancia IA-vs-operador y el feedback que se
 * envía al modelo en las reevaluaciones.
 *
 * Sobrescribe un veredicto anterior en la misma evaluación (el operador puede
 * cambiar de opinión); cada cambio queda en la bitácora de auditoría.
 */
class RecordOperatorVerdict
{
    public function __construct(
        private readonly RecordAuditEntry $recordAuditEntry,
    ) {}

    /**
     * @return AIEventEvaluation|null La evaluación etiquetada, o null si el evento aún no tiene evaluación de IA.
     */
    public function execute(
        int $normalizedEventId,
        OperatorVerdict $verdict,
        ?int $userId = null,
        ?string $note = null,
    ): ?AIEventEvaluation {
        $evaluation = AIEventEvaluation::withoutGlobalScopes()
            ->where('normalized_event_id', $normalizedEventId)
            ->orderByDesc('evaluation_version')
            ->orderByDesc('id')
            ->first();

        if ($evaluation === null) {
            return null;
        }

        $note = $note !== null ? trim($note) : null;

        $evaluation->forceFill([
            'operator_verdict' => $verdict,
            'operator_verdict_by' => $userId,
            'operator_verdict_at' => now(),
            'operator_verdict_note' => $note === '' ? null : $note,
        ])->save();

        $this->recordAuditEntry->execute(
            actorType: $userId !== null ? AuditActorType::User : AuditActorType::System,
            actorId: $userId,
            action: 'ai.operator_verdict.recorded',
            category: AuditCategory::Ai,
            entityType: AIEventEvaluation::class,
            entityId: $evaluation->id,
            summary: 'Veredicto del operador sobre la evaluación de IA: '.$verdict->label(),
            teamId: (int) $evaluation->team_id,
            metadata: [
                'normalized_event_id' => $normalizedEventId,
                'evaluation_version' => $evaluation->evaluation_version,
                'verdict' => $verdict->value,
                'ai_classification' => $evaluation->classification?->value,
                'agrees_with_ai' => $verdict->agreesWith($evaluation->classification),
            ],
            sourceReferenceId: $verdict->value.':'.$evaluation->operator_verdict_at?->getTimestamp(),
        );

        return $evaluation;
    }
}
