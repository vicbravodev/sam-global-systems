<?php

namespace App\Domains\AI\Actions;

use App\Domains\AI\Data\AIInputContext;
use App\Domains\AI\Data\TenantAIProfileData;
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
        );
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
