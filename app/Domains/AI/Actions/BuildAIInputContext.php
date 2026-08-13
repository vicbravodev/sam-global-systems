<?php

namespace App\Domains\AI\Actions;

use App\Domains\AI\Data\AIInputContext;
use App\Domains\AI\Data\TenantAIProfileData;
use App\Domains\AI\Models\AIEventEvaluation;
use App\Domains\AI\Models\AIMediaAssessment;
use App\Domains\Context\Models\EventContextSnapshot;
use App\Domains\Normalization\Models\NormalizedEvent;

class BuildAIInputContext
{
    /**
     * Keys that MUST be redacted from the payload before the agent receives it.
     *
     * @var array<int, string>
     */
    private const REDACTED_KEYS = ['driver_license_number', 'phone', 'email', 'ssn'];

    public function execute(
        NormalizedEvent $event,
        ?EventContextSnapshot $snapshot,
        TenantAIProfileData $profile,
    ): AIInputContext {
        $normalized = $this->redact($event->payload_normalized_json ?? []);

        $signals = $snapshot?->signals_json ?? [];
        $operationalProfile = $signals['operational_profile'] ?? [];
        $recentHistory = $snapshot?->recent_history_snapshot_json ?? ['event_count' => 0];

        return new AIInputContext(
            teamId: $event->team_id,
            normalizedEventId: $event->id,
            normalizedEvent: [
                'id' => $event->id,
                'occurred_at' => optional($event->occurred_at)->toIso8601String(),
                'status' => $event->status->value,
                'payload' => $normalized,
            ],
            contextSignals: $signals,
            operationalProfile: $operationalProfile,
            recentHistory: $recentHistory,
            tenantProfile: $profile->toArray(),
            mediaAssessments: $this->mediaVerdicts($event),
        );
    }

    /**
     * Último veredicto visual por media del evento, a través de todas las
     * versiones de evaluación. En la primera evaluación aún no hay
     * assessments y la lista queda vacía: el comportamiento no cambia.
     *
     * @return list<array<string, mixed>>
     */
    private function mediaVerdicts(NormalizedEvent $event): array
    {
        $evaluationIds = AIEventEvaluation::query()
            ->where('normalized_event_id', $event->id)
            ->select('id');

        return AIMediaAssessment::query()
            ->whereIn('evaluation_id', $evaluationIds)
            ->orderByDesc('assessed_at')
            ->orderByDesc('id')
            ->get()
            ->unique('event_media_context_id')
            ->map(fn (AIMediaAssessment $assessment): array => [
                'media_context_id' => (int) $assessment->event_media_context_id,
                'media_type' => $assessment->media_type?->value,
                'result' => $assessment->result?->value,
                'confidence' => $assessment->confidence_score !== null
                    ? round((float) $assessment->confidence_score, 2)
                    : null,
                'summary' => $assessment->summary_text,
                'extracted_signals' => $assessment->extracted_signals_json ?? [],
            ])
            ->values()
            ->all();
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    private function redact(array $payload): array
    {
        foreach (self::REDACTED_KEYS as $key) {
            if (array_key_exists($key, $payload)) {
                $payload[$key] = '[redacted]';
            }
        }

        return $payload;
    }
}
