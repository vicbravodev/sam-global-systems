<?php

namespace App\Contracts\NullImplementations;

use App\Contracts\AiProviderAdapter;
use App\Domains\AI\Data\AIEvaluationResult;
use App\Domains\AI\Enums\EvaluationMode;
use App\Domains\AI\Enums\EvaluationPriority;
use App\Domains\AI\Enums\EventClassification;

class NullAiProviderAdapter implements AiProviderAdapter
{
    public const MODEL_IDENTIFIER = 'rules-only-v1';

    public function evaluate(array $input): AIEvaluationResult
    {
        $event = $input['event'] ?? [];
        $context = $input['context'] ?? [];
        $signals = $context['signals'] ?? [];

        $severity = $this->normalizeSeverity($event['severity'] ?? 'unknown');
        $riskFromProfile = $this->toFloat($context['profile']['priority_score'] ?? 0.5);
        $recurrence = $this->toFloat($context['profile']['recurrence_score'] ?? 0.0);
        $sameTypeCount = (int) ($signals['recent_same_type_count'] ?? 0);
        $highSeverityCount = (int) ($signals['recent_high_severity_count'] ?? 0);

        $riskScore = $this->clamp(0.5 * $severity + 0.5 * $riskFromProfile);

        [$classification, $confidence, $isRealEvent, $explanation] = $this->classify(
            severity: $severity,
            riskScore: $riskScore,
            recurrence: $recurrence,
            sameTypeCount: $sameTypeCount,
            highSeverityCount: $highSeverityCount,
        );

        $priority = $this->mapPriority($classification, $riskScore);
        $requiresAction = in_array($classification, [
            EventClassification::RealEvent,
            EventClassification::PendingEvidence,
        ], true);

        $decisionSignals = [
            ['code' => 'severity_weight', 'value' => (string) $severity, 'weight' => 0.50, 'description' => 'Event severity normalized 0-1'],
            ['code' => 'profile_priority', 'value' => (string) $riskFromProfile, 'weight' => 0.30, 'description' => 'Operational context priority_score'],
            ['code' => 'recurrence', 'value' => (string) $recurrence, 'weight' => 0.20, 'description' => 'Recurrence score from context profile'],
        ];

        return new AIEvaluationResult(
            mode: EvaluationMode::RulesOnly,
            classification: $classification,
            confidenceScore: $confidence,
            riskScore: $riskScore,
            priorityLevel: $priority,
            isRealEvent: $isRealEvent,
            requiresAction: $requiresAction,
            modelUsed: self::MODEL_IDENTIFIER,
            explanationSummary: $explanation,
            signals: $decisionSignals,
            reasoningSteps: [
                ['step' => 1, 'name' => 'normalize_severity', 'result' => $severity],
                ['step' => 2, 'name' => 'compute_risk_score', 'result' => $riskScore],
                ['step' => 3, 'name' => 'classify', 'result' => $classification->value],
            ],
            keyFactors: [
                'severity' => $severity,
                'risk_score' => $riskScore,
                'recurrence' => $recurrence,
                'recent_same_type_count' => $sameTypeCount,
                'recent_high_severity_count' => $highSeverityCount,
            ],
            confidenceBreakdown: [
                'severity_confidence' => $severity,
                'context_confidence' => $riskFromProfile,
            ],
            evidenceSummary: [
                'sources' => ['normalized_event', 'context_snapshot', 'operational_profile'],
                'media_used' => false,
            ],
            tokensUsed: 0,
            latencyMs: 0,
            costEstimate: 0.0,
        );
    }

    public function describe(): string
    {
        return self::MODEL_IDENTIFIER;
    }

    private function normalizeSeverity(string $severity): float
    {
        return match (strtolower($severity)) {
            'critical', 'urgent' => 1.0,
            'high' => 0.8,
            'medium' => 0.5,
            'low' => 0.2,
            default => 0.3,
        };
    }

    /**
     * @return array{0: EventClassification, 1: float, 2: ?bool, 3: string}
     */
    private function classify(
        float $severity,
        float $riskScore,
        float $recurrence,
        int $sameTypeCount,
        int $highSeverityCount,
    ): array {
        if ($sameTypeCount >= 5 && $highSeverityCount === 0 && $severity <= 0.5) {
            return [
                EventClassification::Noise,
                0.85,
                false,
                'High recurrence of low-severity events suggests noise.',
            ];
        }

        if ($severity >= 0.8 || $riskScore >= 0.75) {
            return [
                EventClassification::RealEvent,
                0.82,
                true,
                'High severity or contextual risk indicates a real event.',
            ];
        }

        if ($severity <= 0.3 && $recurrence <= 0.2) {
            return [
                EventClassification::FalsePositive,
                0.70,
                false,
                'Low severity with negligible recurrence is likely a false positive.',
            ];
        }

        return [
            EventClassification::Unclear,
            0.55,
            null,
            'Rules-only classification did not reach a confident verdict.',
        ];
    }

    private function mapPriority(EventClassification $classification, float $riskScore): EvaluationPriority
    {
        if ($classification === EventClassification::RealEvent && $riskScore >= 0.9) {
            return EvaluationPriority::Urgent;
        }

        if ($classification === EventClassification::RealEvent) {
            return EvaluationPriority::High;
        }

        if ($classification === EventClassification::Unclear) {
            return EvaluationPriority::Normal;
        }

        return EvaluationPriority::Low;
    }

    private function toFloat(mixed $value): float
    {
        if (is_numeric($value)) {
            return (float) $value;
        }

        return 0.0;
    }

    private function clamp(float $value, float $min = 0.0, float $max = 1.0): float
    {
        return max($min, min($max, $value));
    }
}
