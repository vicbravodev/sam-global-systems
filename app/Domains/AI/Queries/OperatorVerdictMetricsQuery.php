<?php

namespace App\Domains\AI\Queries;

use App\Domains\AI\Enums\EventClassification;
use App\Domains\AI\Enums\OperatorVerdict;
use App\Domains\AI\Models\AIEventEvaluation;
use App\Support\TenantContext;
use DateTimeInterface;

/**
 * Métricas del bucle de feedback humano: cuántas evaluaciones etiquetó el
 * operador (confirmadas vs falso positivo) y con qué frecuencia la IA
 * coincidió con él.
 */
class OperatorVerdictMetricsQuery
{
    /**
     * @return array{total: int, confirmed: int, false_positive: int, agreed: int, agreement_rate: float|null, ai_false_positive_overruled: int, ai_real_event_overruled: int}
     */
    public function execute(int $teamId, ?DateTimeInterface $since = null): array
    {
        return TenantContext::for($teamId, function () use ($teamId, $since) {
            $rows = AIEventEvaluation::query()
                ->where('team_id', $teamId)
                ->whereNotNull('operator_verdict')
                ->when($since !== null, fn ($q) => $q->where('operator_verdict_at', '>=', $since))
                ->get(['id', 'classification', 'operator_verdict']);

            $confirmed = 0;
            $falsePositive = 0;
            $agreed = 0;
            $aiFalsePositiveOverruled = 0;
            $aiRealEventOverruled = 0;

            foreach ($rows as $row) {
                /** @var OperatorVerdict $verdict */
                $verdict = $row->operator_verdict;

                if ($verdict === OperatorVerdict::Confirmed) {
                    $confirmed++;
                    if ($row->classification === EventClassification::FalsePositive) {
                        $aiFalsePositiveOverruled++;
                    }
                } else {
                    $falsePositive++;
                    if ($row->classification === EventClassification::RealEvent) {
                        $aiRealEventOverruled++;
                    }
                }

                if ($verdict->agreesWith($row->classification)) {
                    $agreed++;
                }
            }

            $total = $rows->count();

            return [
                'total' => $total,
                'confirmed' => $confirmed,
                'false_positive' => $falsePositive,
                'agreed' => $agreed,
                'agreement_rate' => $total > 0 ? round($agreed / $total, 4) : null,
                // La IA dijo falso positivo y el operador lo confirmó como real:
                // el error caro (falso negativo).
                'ai_false_positive_overruled' => $aiFalsePositiveOverruled,
                // La IA dijo evento real y el operador lo descartó.
                'ai_real_event_overruled' => $aiRealEventOverruled,
            ];
        });
    }
}
