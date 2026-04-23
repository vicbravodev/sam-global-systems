<?php

namespace App\Domains\AI\Actions;

use App\Domains\Context\Models\EventContextSnapshot;
use App\Domains\Context\Models\OperationalContextProfile;
use App\Domains\Normalization\Models\NormalizedEvent;

class BuildAIInputContext
{
    /**
     * Structure normalized event data, context snapshot, and tenant profile
     * into the input array expected by the AiProviderAdapter. Sensitive
     * fields (driver license numbers, raw credentials) are redacted.
     *
     * @return array<string, mixed>
     */
    public function execute(
        NormalizedEvent $event,
        EventContextSnapshot $context,
        ?OperationalContextProfile $profile = null,
    ): array {
        return [
            'event' => [
                'id' => $event->id,
                'type_id' => $event->event_type_id,
                'category_id' => $event->event_category_id,
                'severity' => $event->eventSeverity?->code ?? 'unknown',
                'occurred_at' => $event->occurred_at?->toIso8601String(),
                'payload' => $this->redact($event->payload_normalized_json ?? []),
            ],
            'context' => [
                'snapshot_id' => $context->id,
                'context_version' => $context->context_version,
                'location' => $context->location_snapshot_json,
                'asset' => $context->asset_snapshot_json,
                'telemetry' => $context->telemetry_snapshot_json,
                'geofences' => $context->geofence_snapshot_json,
                'incidents' => $context->incidents_snapshot_json,
                'recent_history' => $context->recent_history_snapshot_json,
                'signals' => $context->signals_json ?? [],
                'profile' => $profile ? [
                    'profile_code' => $profile->profile_code,
                    'risk_level' => $profile->risk_level?->value,
                    'priority_score' => (float) $profile->priority_score,
                    'recurrence_score' => (float) $profile->recurrence_score,
                    'contextual_flags' => $profile->contextual_flags_json,
                ] : null,
            ],
            'media' => $context->media_snapshot_json ?? [],
            'tenant' => [
                'team_id' => $event->team_id,
            ],
        ];
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    private function redact(array $payload): array
    {
        $sensitiveKeys = ['driver_license_number', 'ssn', 'password', 'token', 'api_key'];

        foreach ($sensitiveKeys as $key) {
            if (array_key_exists($key, $payload)) {
                $payload[$key] = '[REDACTED]';
            }
        }

        return $payload;
    }
}
