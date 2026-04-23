<?php

namespace App\Domains\AI\Support;

use App\Domains\AI\Enums\EvaluationMode;
use App\Domains\AI\Enums\EventClassification;
use App\Domains\Normalization\Models\NormalizedEvent;

/**
 * Deterministic rules & heuristics stage. Runs before the AI agent and can
 * short-circuit the pipeline when the outcome is obvious (known noise signatures,
 * recent duplicates). Pure PHP, no external services, no AI calls.
 */
class HeuristicRulesRunner
{
    /** @var array<int, string> */
    private const KNOWN_NOISE_SIGNATURES = [
        'camera_obstruction_calibration',
        'idle_ping',
        'heartbeat',
        'test_event',
    ];

    /**
     * Returns null when no deterministic decision can be made. Otherwise returns
     * a two-element array with the short-circuit classification and mode.
     *
     * @param  array<string, mixed>  $signals
     * @return array{classification: EventClassification, mode: EvaluationMode, reason: string}|null
     */
    public function evaluate(NormalizedEvent $event, array $signals): ?array
    {
        $payload = $event->payload_normalized_json ?? [];
        $signatureCandidate = (string) ($payload['signature'] ?? $payload['event_signature'] ?? '');

        if ($signatureCandidate !== '' && in_array($signatureCandidate, self::KNOWN_NOISE_SIGNATURES, true)) {
            return [
                'classification' => EventClassification::FalsePositive,
                'mode' => EvaluationMode::RulesOnly,
                'reason' => 'known_noise_signature:'.$signatureCandidate,
            ];
        }

        if (($signals['recent_duplicates_count'] ?? 0) >= 3) {
            return [
                'classification' => EventClassification::Duplicate,
                'mode' => EvaluationMode::RulesOnly,
                'reason' => 'recent_duplicates_in_window',
            ];
        }

        return null;
    }
}
