<?php

namespace App\Domains\AI\Data;

/**
 * Structured input sent to the AI agent. Immutable DTO.
 *
 * `teamId` / `normalizedEventId` stay as properties for persistence and
 * tracing, but only the event id travels in the payload the model sees.
 */
final readonly class AIInputContext
{
    /**
     * @param  array<string, mixed>  $normalizedEvent  Event block: type/category/severity, UTC + tenant-local time, redacted payload.
     * @param  array<string, mixed>  $contextSignals
     * @param  array<string, mixed>  $operationalProfile  `OperationalContextProfile` of the event (profile_code, risk_level, priority_score, flags).
     * @param  array<string, mixed>  $recentHistory
     * @param  array<string, mixed>  $tenantProfile  Only `automation_level`.
     * @param  list<array<string, mixed>>  $mediaAssessments  Último veredicto visual por media del evento (result, confidence, summary, extracted_signals).
     * @param  array<string, mixed>  $telemetry  speed_kph, heading_degrees, position_stale, gps_accuracy_meters.
     * @param  array<string, mixed>  $location  Coordinates + geofence matches (name, category, match_type).
     * @param  array<string, mixed>  $asset  type, name, plate_or_code, has_camera.
     * @param  array<string, mixed>  $driver  Operational risk context without PII.
     * @param  array<string, mixed>  $incidents  Related open / prior similar incidents summary.
     */
    public function __construct(
        public int $teamId,
        public int $normalizedEventId,
        public array $normalizedEvent,
        public array $contextSignals,
        public array $operationalProfile,
        public array $recentHistory,
        public array $tenantProfile,
        public array $mediaAssessments = [],
        public array $telemetry = [],
        public array $location = [],
        public array $asset = [],
        public array $driver = [],
        public array $incidents = [],
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'normalized_event_id' => $this->normalizedEventId,
            'normalized_event' => $this->normalizedEvent,
            'telemetry' => $this->telemetry,
            'location' => $this->location,
            'asset' => $this->asset,
            'driver' => $this->driver,
            'incidents' => $this->incidents,
            'context_signals' => $this->contextSignals,
            'operational_profile' => $this->operationalProfile,
            'recent_history' => $this->recentHistory,
            'tenant_profile' => $this->tenantProfile,
            'media_assessments' => $this->mediaAssessments,
        ];
    }
}
