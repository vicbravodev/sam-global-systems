<?php

namespace App\Domains\AI\Actions;

use App\Domains\Context\Enums\RiskLevel;
use App\Domains\Context\Models\EventContextSnapshot;
use App\Domains\Context\Models\OperationalContextProfile;
use App\Domains\Normalization\Models\NormalizedEvent;
use App\Support\LoggableCode;
use App\Support\SystemLog;

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
     * Mínimo de eventos recientes para el boost de recurrencia alto/medio.
     */
    private const RECURRENCE_THRESHOLDS = ['high' => 10, 'medium' => 3];

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
        $terms = $this->breakdown($event, $snapshot);

        SystemLog::ok(
            'ai.risk.calculated',
            input: ['normalized_event_id' => $event->id, 'snapshot_id' => $snapshot?->id],
            calc: $terms,
            result: ['risk_score' => $terms['final']],
        );

        return $terms['final'];
    }

    /**
     * Todos los términos del score, recomputables:
     * final = round(clamp(base + risk_level_boost + recurrence_boost + geofence_boost + signal_boost_total), 2).
     * Sin snapshot todos los boosts valen 0.0.
     *
     * @return array<string, mixed>
     */
    private function breakdown(NormalizedEvent $event, ?EventContextSnapshot $snapshot): array
    {
        $severity = $this->severityWeight($event);
        $base = $severity['weight'];

        $riskLevel = null;
        $riskLevelBoost = 0.0;
        $recentEventsCount = 0;
        $recurrenceBoost = 0.0;
        $sensitiveGeofence = false;
        $sensitiveGeofenceBoost = 0.0;
        $signalBoosts = array_fill_keys(array_keys(self::SIGNAL_BOOSTS), 0.0);
        $signalBoost = 0.0;

        if ($snapshot !== null) {
            $signals = $snapshot->signals_json ?? [];
            $recentHistory = $snapshot->recent_history_snapshot_json ?? [];

            $riskLevel = $this->operationalRiskLevel($event);

            $riskLevelBoost = match ($riskLevel) {
                RiskLevel::Critical => 0.35,
                RiskLevel::High => 0.2,
                RiskLevel::Medium => 0.1,
                default => 0.0,
            };

            $recentEventsCount = (int) ($recentHistory['recent_events_count'] ?? 0);

            $recurrenceBoost = match (true) {
                $recentEventsCount >= self::RECURRENCE_THRESHOLDS['high'] => 0.15,
                $recentEventsCount >= self::RECURRENCE_THRESHOLDS['medium'] => 0.08,
                default => 0.0,
            };

            $sensitiveGeofence = ($signals['is_in_sensitive_geofence'] ?? false) === true;
            $sensitiveGeofenceBoost = $sensitiveGeofence ? 0.15 : 0.0;

            foreach (self::SIGNAL_BOOSTS as $signal => $boost) {
                if (($signals[$signal] ?? false) === true) {
                    $signalBoosts[$signal] = $boost;
                    $signalBoost += $boost;
                }
            }
        }

        $sum = $base + $riskLevelBoost + $recurrenceBoost + $sensitiveGeofenceBoost + $signalBoost;

        return [
            'severity_code' => LoggableCode::guard($severity['severity_code']),
            'severity_source' => $severity['severity_source'],
            'base' => $base,
            'snapshot_present' => $snapshot !== null,
            'risk_level' => $riskLevel?->value,
            'risk_level_boost' => $riskLevelBoost,
            'recent_events_count' => $recentEventsCount,
            'recurrence_thresholds' => self::RECURRENCE_THRESHOLDS,
            'recurrence_boost' => $recurrenceBoost,
            'sensitive_geofence' => $sensitiveGeofence,
            'geofence_boost' => $sensitiveGeofenceBoost,
            'signal_boosts' => $signalBoosts,
            'signal_boost_total' => $signalBoost,
            'sum' => $sum,
            'clamp' => [0.0, 1.0],
            'final' => $this->clamp($sum),
        ];
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

    /**
     * @return array{weight: float, severity_code: ?string, severity_source: 'payload'|'event_severity'|'default'}
     */
    private function severityWeight(NormalizedEvent $event): array
    {
        $payload = $event->payload_normalized_json ?? [];
        $severity = (string) ($payload['severity'] ?? $payload['severity_code'] ?? '');

        if (array_key_exists($severity, self::SEVERITY_WEIGHTS)) {
            return ['weight' => self::SEVERITY_WEIGHTS[$severity], 'severity_code' => $severity, 'severity_source' => 'payload'];
        }

        $severity = (string) $event->eventSeverity?->code;

        if (array_key_exists($severity, self::SEVERITY_WEIGHTS)) {
            return ['weight' => self::SEVERITY_WEIGHTS[$severity], 'severity_code' => $severity, 'severity_source' => 'event_severity'];
        }

        return ['weight' => 0.25, 'severity_code' => null, 'severity_source' => 'default'];
    }

    private function clamp(float $value): float
    {
        return round(max(0.0, min(1.0, $value)), 2);
    }
}
