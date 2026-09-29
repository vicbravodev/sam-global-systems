<?php

namespace App\Domains\AI\Support;

use App\Domains\AI\Enums\EvaluationMode;
use App\Domains\AI\Enums\EventClassification;
use App\Domains\Normalization\Models\NormalizedEvent;
use App\Support\SystemLog;

/**
 * Deterministic rules & heuristics stage. Runs before the AI agent and can
 * short-circuit the pipeline when the outcome is obvious (known noise signatures,
 * recent duplicates). Pure PHP, no external services, no AI calls.
 */
class HeuristicRulesRunner
{
    private const int DUPLICATE_THRESHOLD = 3;

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
        $signatureSource = match (true) {
            isset($payload['signature']) => 'signature',
            isset($payload['event_signature']) => 'event_signature',
            default => null,
        };
        $signatureCandidate = (string) ($payload['signature'] ?? $payload['event_signature'] ?? '');
        $noiseMatch = $signatureCandidate !== '' && in_array($signatureCandidate, self::KNOWN_NOISE_SIGNATURES, true)
            ? $signatureCandidate
            : null;
        $duplicatesPresent = array_key_exists('recent_duplicates_count', $signals);

        $decision = null;
        $rule = null;

        if ($noiseMatch !== null) {
            $rule = 'known_noise_signature';
            $decision = [
                'classification' => EventClassification::FalsePositive,
                'mode' => EvaluationMode::RulesOnly,
                'reason' => 'known_noise_signature:'.$signatureCandidate,
            ];
        } elseif (($signals['recent_duplicates_count'] ?? 0) >= self::DUPLICATE_THRESHOLD) {
            $rule = 'recent_duplicates_in_window';
            $decision = [
                'classification' => EventClassification::Duplicate,
                'mode' => EvaluationMode::RulesOnly,
                'reason' => 'recent_duplicates_in_window',
            ];
        }

        SystemLog::ok(
            'ai.heuristics.evaluated',
            input: ['normalized_event_id' => $event->id],
            calc: [
                'signature_source' => $signatureSource,
                'noise_match' => $noiseMatch,
                'known_noise_signatures_count' => count(self::KNOWN_NOISE_SIGNATURES),
                'duplicates_signal_present' => $duplicatesPresent,
                'recent_duplicates_count' => $duplicatesPresent ? (int) $signals['recent_duplicates_count'] : null,
                'duplicate_threshold' => self::DUPLICATE_THRESHOLD,
                'signals_missing' => array_values(array_filter([
                    $signatureSource === null ? 'payload.signature' : null,
                    $duplicatesPresent ? null : 'signals.recent_duplicates_count',
                ])),
            ],
            result: ['short_circuit' => $decision !== null, 'rule' => $rule],
        );

        return $decision;
    }
}
