<?php

namespace App\Domains\AI\Support;

use App\Domains\AI\Enums\ReevaluationTrigger;
use App\Domains\AI\Models\AIEventEvaluation;
use App\Domains\AI\Models\AIReevaluationRequest;
use App\Domains\Normalization\Models\NormalizedEvent;
use Illuminate\Support\Str;
use LogicException;

/**
 * Reúne el feedback humano sobre un evento para que llegue al modelo en la
 * siguiente evaluación: los veredictos del operador (confirmado / falso
 * positivo) sobre versiones previas y los últimos motivos escritos en el
 * diálogo "Feedback" de la bandeja.
 *
 * Sin PII: no incluye ids ni nombres de usuario, sólo el contenido del
 * feedback y cuándo se dio.
 */
class OperatorFeedbackCollector
{
    public const MAX_MANUAL_FEEDBACK = 3;

    private const MAX_TEXT_LENGTH = 500;

    private const SUPERSEDED_SUFFIX = '| superseded by new request';

    /**
     * @return array{operator_verdicts?: list<array{evaluation_version: int, verdict: string, ai_classification: string|null, recorded_at: string|null, note: string|null}>, manual_feedback?: list<array{reason: string, requested_at: string|null}>}
     */
    public function collect(NormalizedEvent $event): array
    {
        $verdicts = AIEventEvaluation::query()
            ->where('team_id', $event->team_id)
            ->where('normalized_event_id', $event->id)
            ->whereNotNull('operator_verdict')
            ->orderBy('evaluation_version')
            ->get()
            ->map(fn (AIEventEvaluation $evaluation): array => [
                'evaluation_version' => $evaluation->evaluation_version,
                // whereNotNull('operator_verdict') arriba: siempre hay veredicto.
                'verdict' => ($evaluation->operator_verdict ?? throw new LogicException("ai_event_evaluation {$evaluation->id} sin operator_verdict"))->value,
                'ai_classification' => $evaluation->classification?->value,
                'recorded_at' => $evaluation->operator_verdict_at?->toIso8601String(),
                'note' => $this->clean($evaluation->operator_verdict_note),
            ])
            ->values()
            ->all();

        $manual = AIReevaluationRequest::query()
            ->where('normalized_event_id', $event->id)
            ->where('trigger_type', ReevaluationTrigger::ManualReviewRequested)
            ->whereNotNull('reason')
            ->orderByDesc('requested_at')
            ->orderByDesc('id')
            ->get()
            ->map(fn (AIReevaluationRequest $request): ?array => ($reason = $this->clean($request->reason)) === null
                ? null
                : [
                    'reason' => $reason,
                    'requested_at' => $request->requested_at?->toIso8601String(),
                ])
            ->filter()
            ->take(self::MAX_MANUAL_FEEDBACK)
            ->values()
            ->all();

        return array_filter([
            'operator_verdicts' => array_values($verdicts),
            'manual_feedback' => array_values($manual),
        ], static fn (array $items): bool => $items !== []);
    }

    private function clean(?string $text): ?string
    {
        if ($text === null) {
            return null;
        }

        $text = trim(str_replace(self::SUPERSEDED_SUFFIX, '', $text));

        return $text === '' ? null : Str::limit($text, self::MAX_TEXT_LENGTH);
    }
}
