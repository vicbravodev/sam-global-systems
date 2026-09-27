<?php

namespace App\Domains\AI\Actions;

use App\Domains\Context\Enums\RiskLevel;
use App\Domains\Context\Models\EventContextSnapshot;
use App\Domains\Context\Models\OperationalContextProfile;
use App\Domains\Normalization\Models\NormalizedEvent;

class CalculateRiskScore
{
    private const SEVERITY_WEIGHTS = [
        'critical' => 0.6,
        'high' => 0.45,
        'medium' => 0.3,
        'low' => 0.15,
    ];

    /**
     * Señales booleanas de `signals_json` (SignalsBuilder) que empujan el
     * riesgo hacia un escenario real: pánicos repetidos, maniobras bruscas
     * alrededor del evento y pérdida de GPS en movimiento (posible jammer).
     */
    private const SIGNAL_BOOSTS = [
        'repeated_panic_24h' => 0.1,
        'harsh_driving_near_event' => 0.1,
        'gps_lost_in_motion' => 0.1,
    ];

    /**
     * Combina severidad del evento (base), nivel de riesgo del perfil
     * operacional, recurrencia reciente, geocerca sensible y señales de
     * correlación en un score normalizado 0..1.
     *
     * Cada fuente se lee de donde realmente vive: el nivel de riesgo en
     * `operational_context_profiles.risk_level`, la recurrencia en
     * `recent_history_snapshot_json` y las banderas en `signals_json`.
     */
    public function execute(NormalizedEvent $event, ?EventContextSnapshot $snapshot): float
    {
        $base = $this->severityWeight($event);

        if ($snapshot === null) {
            return $this->clamp($base);
        }

        $signals = $snapshot->signals_json ?? [];
        $recentHistory = $snapshot->recent_history_snapshot_json ?? [];

        $riskLevelBoost = match ($this->operationalRiskLevel($event)) {
            RiskLevel::Critical => 0.35,
            RiskLevel::High => 0.2,
            RiskLevel::Medium => 0.1,
            default => 0.0,
        };

        $recentEventsCount = (int) ($recentHistory['recent_events_count'] ?? 0);

        $recurrenceBoost = match (true) {
            $recentEventsCount >= 10 => 0.15,
            $recentEventsCount >= 3 => 0.08,
            default => 0.0,
        };

        $sensitiveGeofenceBoost = ($signals['is_in_sensitive_geofence'] ?? false) === true ? 0.15 : 0.0;

        $signalBoost = 0.0;

        foreach (self::SIGNAL_BOOSTS as $signal => $boost) {
            if (($signals[$signal] ?? false) === true) {
                $signalBoost += $boost;
            }
        }

        return $this->clamp($base + $riskLevelBoost + $recurrenceBoost + $sensitiveGeofenceBoost + $signalBoost);
    }

    private function operationalRiskLevel(NormalizedEvent $event): ?RiskLevel
    {
        $riskLevel = OperationalContextProfile::query()
            ->where('normalized_event_id', $event->id)
            ->value('risk_level');

        if ($riskLevel instanceof RiskLevel) {
            return $riskLevel;
        }

        return is_string($riskLevel) ? RiskLevel::tryFrom($riskLevel) : null;
    }

    private function severityWeight(NormalizedEvent $event): float
    {
        $payload = $event->payload_normalized_json ?? [];
        $severity = (string) ($payload['severity'] ?? $payload['severity_code'] ?? '');

        if (! array_key_exists($severity, self::SEVERITY_WEIGHTS)) {
            $severity = (string) $event->eventSeverity?->code;
        }

        return self::SEVERITY_WEIGHTS[$severity] ?? 0.25;
    }

    private function clamp(float $value): float
    {
        return round(max(0.0, min(1.0, $value)), 2);
    }
}
