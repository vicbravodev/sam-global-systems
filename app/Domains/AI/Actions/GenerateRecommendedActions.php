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
     * Map classification + priority + risk into a prioritized list of
     * AIRecommendedAction records.
     *
     * @return Collection<int, AIRecommendedAction>
     */
    public function execute(AIEventEvaluation $evaluation): Collection
    {
        $plan = $this->plan(
            $evaluation->classification,
            $evaluation->priority_level,
            (float) $evaluation->risk_score,
        );

        $created = collect();

        foreach ($plan as $index => $entry) {
            $created->push(AIRecommendedAction::query()->create([
                'evaluation_id' => $evaluation->id,
                'action_type' => $entry['action']->value,
                'priority' => $index + 1,
                'parameters_json' => $entry['parameters'] ?? [],
                'requires_confirmation' => $entry['requires_confirmation'] ?? false,
            ]));
        }

        return $created;
    }

    /**
     * @return array<int, array{action: RecommendedActionType, parameters?: array<string, mixed>, requires_confirmation?: bool}>
     */
    private function plan(
        EventClassification $classification,
        EvaluationPriority $priority,
        float $riskScore,
    ): array {
        return match ($classification) {
            EventClassification::RealEvent => $priority === EvaluationPriority::Urgent || $riskScore >= 0.9
                ? [
                    ['action' => RecommendedActionType::TriggerEmergencyProtocol, 'requires_confirmation' => true],
                    ['action' => RecommendedActionType::EscalateToOperator],
                    ['action' => RecommendedActionType::NotifySupervisor],
                ]
                : [
                    ['action' => RecommendedActionType::EscalateToOperator],
                    ['action' => RecommendedActionType::NotifySupervisor],
                ],

            EventClassification::PendingEvidence => [
                ['action' => RecommendedActionType::WaitForMedia],
                ['action' => RecommendedActionType::RequestVideoReview],
            ],

            EventClassification::Unclear => [
                ['action' => RecommendedActionType::RequestVideoReview],
            ],

            EventClassification::FalsePositive,
            EventClassification::Noise,
            EventClassification::Duplicate => [
                ['action' => RecommendedActionType::IgnoreEvent],
            ],
        };
    }
}
