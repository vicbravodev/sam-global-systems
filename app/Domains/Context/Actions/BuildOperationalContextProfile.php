<?php

namespace App\Domains\Context\Actions;

use App\Domains\Context\Enums\RiskLevel;
use App\Domains\Context\Models\EventContextSnapshot;
use App\Domains\Context\Models\OperationalContextProfile;

class BuildOperationalContextProfile
{
    /**
     * Derive risk level, priority score, and recurrence score from a snapshot.
     */
    public function execute(EventContextSnapshot $snapshot): OperationalContextProfile
    {
        $signals = $snapshot->signals_json ?? [];
        $recentHistory = $snapshot->recent_history_snapshot_json ?? [];

        $severityWeight = $this->severityWeight($snapshot);
        $recurrenceScore = $this->recurrenceScore($recentHistory);
        $contextBoost = $this->contextBoost($signals);

        $priorityScore = min(100.0, $severityWeight + $recurrenceScore + $contextBoost);
        $riskLevel = $this->riskLevelFromScore($priorityScore);
        $profileCode = $this->profileCodeFor($riskLevel, $signals);

        return OperationalContextProfile::withoutGlobalScopes()->updateOrCreate(
            ['normalized_event_id' => $snapshot->normalized_event_id],
            [
                'team_id' => $snapshot->team_id,
                'profile_code' => $profileCode,
                'risk_level' => $riskLevel,
                'priority_score' => round($priorityScore, 2),
                'recurrence_score' => round($recurrenceScore, 2),
                'contextual_flags_json' => $this->extractFlags($signals),
                'summary_json' => [
                    'severity_weight' => $severityWeight,
                    'recurrence_score' => $recurrenceScore,
                    'context_boost' => $contextBoost,
                    'priority_score' => round($priorityScore, 2),
                    'risk_level' => $riskLevel->value,
                ],
            ],
        );
    }

    private function severityWeight(EventContextSnapshot $snapshot): float
    {
        $severityCode = $snapshot->normalizedEvent?->eventSeverity?->code;

        return match ($severityCode) {
            'critical' => 60.0,
            'high' => 40.0,
            'medium' => 20.0,
            'low' => 10.0,
            default => 5.0,
        };
    }

    /**
     * @param  array<string, mixed>  $recentHistory
     */
    private function recurrenceScore(array $recentHistory): float
    {
        $sameType = (int) ($recentHistory['recent_same_type_count'] ?? 0);
        $highSeverity = (int) ($recentHistory['recent_high_severity_count'] ?? 0);

        return min(30.0, ($sameType * 5.0) + ($highSeverity * 4.0));
    }

    /**
     * @param  array<string, mixed>  $signals
     */
    private function contextBoost(array $signals): float
    {
        $boost = 0.0;

        if (! empty($signals['is_in_sensitive_geofence'])) {
            $boost += 10.0;
        }

        if (! empty($signals['has_open_incident'])) {
            $boost += 10.0;
        }

        if (! empty($signals['driver_has_recent_risk_events'])) {
            $boost += 5.0;
        }

        if (! empty($signals['gps_signal_weak'])) {
            $boost += 2.0;
        }

        return $boost;
    }

    private function riskLevelFromScore(float $score): RiskLevel
    {
        return match (true) {
            $score >= 80.0 => RiskLevel::Critical,
            $score >= 60.0 => RiskLevel::High,
            $score >= 30.0 => RiskLevel::Medium,
            default => RiskLevel::Low,
        };
    }

    /**
     * @param  array<string, mixed>  $signals
     */
    private function profileCodeFor(RiskLevel $riskLevel, array $signals): string
    {
        if (! empty($signals['is_in_sensitive_geofence']) && $riskLevel === RiskLevel::Critical) {
            return 'sensitive_zone_critical';
        }

        if ($riskLevel === RiskLevel::Critical) {
            return 'critical';
        }

        if ($riskLevel === RiskLevel::High) {
            return 'elevated';
        }

        return 'baseline';
    }

    /**
     * @param  array<string, mixed>  $signals
     * @return array<string, bool>
     */
    private function extractFlags(array $signals): array
    {
        $flags = [];

        foreach ($signals as $key => $value) {
            if (is_bool($value) && $value === true) {
                $flags[$key] = true;
            }
        }

        return $flags;
    }
}
