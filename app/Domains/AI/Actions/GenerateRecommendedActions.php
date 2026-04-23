<?php

namespace App\Domains\AI\Actions;

use App\Domains\AI\Enums\EvaluationPriority;
use App\Domains\AI\Enums\EventClassification;
use App\Domains\AI\Enums\RecommendedActionType;
use App\Domains\AI\Models\AIEventEvaluation;
use App\Domains\AI\Models\AIRecommendedAction;
use Illuminate\Support\Collection;

class GenerateRecommendedActions
{
    /**
     * Persists recommended actions for an evaluation based on its
     * classification and priority. Returns the inserted records.
     *
     * @return Collection<int, AIRecommendedAction>
     */
    public function execute(AIEventEvaluation $evaluation): Collection
    {
        $plans = $this->planFor($evaluation);

        if ($plans === []) {
            return collect();
        }

        return collect($plans)->map(function (array $plan) use ($evaluation) {
            return AIRecommendedAction::create([
                'evaluation_id' => $evaluation->id,
                'action_type' => $plan['action_type'],
                'priority' => $plan['priority'],
                'parameters_json' => $plan['parameters'] ?? [],
                'requires_confirmation' => $plan['requires_confirmation'] ?? false,
            ]);
        });
    }

    /**
     * @return array<int, array{action_type: RecommendedActionType, priority: int, parameters?: array<string, mixed>, requires_confirmation?: bool}>
     */
    private function planFor(AIEventEvaluation $evaluation): array
    {
        if ($evaluation->classification === EventClassification::FalsePositive) {
            return [
                ['action_type' => RecommendedActionType::IgnoreEvent, 'priority' => 10],
            ];
        }

        if ($evaluation->classification === EventClassification::PendingEvidence) {
            return [
                ['action_type' => RecommendedActionType::WaitForMedia, 'priority' => 5],
            ];
        }

        if ($evaluation->classification === EventClassification::Noise
            || $evaluation->classification === EventClassification::Duplicate
        ) {
            return [
                ['action_type' => RecommendedActionType::IgnoreEvent, 'priority' => 20],
            ];
        }

        return match ($evaluation->priority_level) {
            EvaluationPriority::Urgent => [
                ['action_type' => RecommendedActionType::TriggerEmergencyProtocol, 'priority' => 1, 'requires_confirmation' => true],
                ['action_type' => RecommendedActionType::EscalateToOperator, 'priority' => 2],
                ['action_type' => RecommendedActionType::NotifySupervisor, 'priority' => 3],
            ],
            EvaluationPriority::High => [
                ['action_type' => RecommendedActionType::EscalateToOperator, 'priority' => 2],
                ['action_type' => RecommendedActionType::NotifySupervisor, 'priority' => 4],
                ['action_type' => RecommendedActionType::CreateIncident, 'priority' => 5],
            ],
            EvaluationPriority::Normal => [
                ['action_type' => RecommendedActionType::NotifySupervisor, 'priority' => 5],
                ['action_type' => RecommendedActionType::CreateIncident, 'priority' => 6],
            ],
            EvaluationPriority::Low => [
                ['action_type' => RecommendedActionType::CreateIncident, 'priority' => 8],
            ],
        };
    }
}
