<?php

namespace App\Domains\AI\Actions;

use App\Domains\Context\Models\EventContextSnapshot;
use App\Domains\Normalization\Models\NormalizedEvent;

class CalculateRiskScore
{
    /**
     * Combine event severity, operational profile risk, recurrence and
     * geofence context into a normalized 0..1 risk score.
     */
    public function execute(NormalizedEvent $event, ?EventContextSnapshot $snapshot): float
    {
        $base = $this->severityWeight($event);

        if ($snapshot === null) {
            return $this->clamp($base);
        }

        $signals = $snapshot->signals_json ?? [];

        $riskLevelBoost = match ((string) ($signals['operational_profile']['risk_level'] ?? '')) {
            'critical' => 0.35,
            'high' => 0.2,
            'medium' => 0.1,
            default => 0.0,
        };

        $recurrenceBoost = match (true) {
            ($signals['recent_events_count'] ?? 0) >= 10 => 0.15,
            ($signals['recent_events_count'] ?? 0) >= 3 => 0.08,
            default => 0.0,
        };

        $sensitiveGeofenceBoost = ($signals['inside_sensitive_geofence'] ?? false) ? 0.15 : 0.0;

        return $this->clamp($base + $riskLevelBoost + $recurrenceBoost + $sensitiveGeofenceBoost);
    }

    private function severityWeight(NormalizedEvent $event): float
    {
        $payload = $event->payload_normalized_json ?? [];
        $severity = (string) ($payload['severity'] ?? $payload['severity_code'] ?? 'normal');

        return match ($severity) {
            'critical' => 0.6,
            'high' => 0.45,
            'medium' => 0.3,
            'low' => 0.15,
            default => 0.25,
        };
    }

    private function clamp(float $value): float
    {
        return round(max(0.0, min(1.0, $value)), 2);
    }
}
