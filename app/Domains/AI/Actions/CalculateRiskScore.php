<?php

namespace App\Domains\AI\Actions;

use App\Domains\Context\Models\EventContextSnapshot;
use App\Domains\Context\Models\OperationalContextProfile;
use App\Domains\Normalization\Models\NormalizedEvent;

class CalculateRiskScore
{
    /**
     * Combine event severity, operational profile priority, and recent-history
     * signals into a normalized 0.00-1.00 risk score.
     */
    public function execute(
        NormalizedEvent $event,
        EventContextSnapshot $context,
        ?OperationalContextProfile $profile = null,
    ): float {
        $severity = $this->severityScore($event->eventSeverity?->code ?? 'unknown');

        $profileScore = $profile ? (float) $profile->priority_score : 0.5;
        $recurrence = $profile ? (float) $profile->recurrence_score : 0.0;

        $signals = $context->signals_json ?? [];
        $sameTypeCount = (int) ($signals['recent_same_type_count'] ?? 0);
        $highSeverityCount = (int) ($signals['recent_high_severity_count'] ?? 0);

        $historyBonus = 0.0;
        if ($highSeverityCount > 0) {
            $historyBonus += 0.15;
        }
        if ($sameTypeCount >= 3) {
            $historyBonus += 0.05;
        }

        $score = (0.55 * $severity) + (0.25 * $profileScore) + (0.10 * $recurrence) + $historyBonus;

        return max(0.0, min(1.0, round($score, 2)));
    }

    private function severityScore(string $severity): float
    {
        return match (strtolower($severity)) {
            'critical', 'urgent' => 1.0,
            'high' => 0.8,
            'medium' => 0.5,
            'low' => 0.2,
            default => 0.3,
        };
    }
}
