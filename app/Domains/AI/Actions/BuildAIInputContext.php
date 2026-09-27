<?php

namespace App\Domains\AI\Actions;

use App\Domains\AI\Data\AIInputContext;
use App\Domains\AI\Data\TenantAIProfileData;
use App\Domains\AI\Models\AIEventEvaluation;
use App\Domains\AI\Models\AIMediaAssessment;
use App\Domains\Context\Models\EventContextSnapshot;
use App\Domains\Context\Models\OperationalContextProfile;
use App\Domains\Drivers\Models\Driver;
use App\Domains\Normalization\Models\NormalizedEvent;
use App\Models\Team;
use DateTimeZone;
use Illuminate\Support\Carbon;
use Throwable;

/**
 * Builds the compact, PII-free payload the event classifier receives.
 *
 * Everything the model needs to judge an event travels here: what the event
 * is (type, category, severity, local time), where the unit was (location,
 * geofences, telemetry), what the unit is (asset), the driver's operational
 * risk (never their identity), related incidents, the operational context
 * profile computed by the Context domain and prior visual verdicts. Noise
 * (tenant quotas, ids, pre-signed URLs) is left out.
 */
class BuildAIInputContext
{
    public const string DEFAULT_TIMEZONE = 'America/Mexico_City';

    public const int MAX_RECENT_LOCATIONS = 5;

    public const int MAX_INCIDENT_ITEMS = 5;

    /**
     * Keys whose value is personal data: replaced by `[redacted]` at any depth.
     *
     * @var list<string>
     */
    private const REDACTED_KEYS = [
        'driver_license_number', 'license', 'license_number',
        'phone', 'phone_number', 'email', 'ssn',
        'name', 'first_name', 'last_name', 'full_name', 'driver_name',
    ];

    public function execute(
        NormalizedEvent $event,
        ?EventContextSnapshot $snapshot,
        TenantAIProfileData $profile,
    ): AIInputContext {
        $event->loadMissing(['eventType.category', 'eventCategory', 'eventSeverity']);

        $recentHistory = $snapshot?->recent_history_snapshot_json ?? ['event_count' => 0];

        if (isset($recentHistory['recent_locations']) && is_array($recentHistory['recent_locations'])) {
            $recentHistory['recent_locations'] = array_slice($recentHistory['recent_locations'], 0, self::MAX_RECENT_LOCATIONS);
        }

        return new AIInputContext(
            teamId: $event->team_id,
            normalizedEventId: $event->id,
            normalizedEvent: $this->eventBlock($event),
            contextSignals: $snapshot?->signals_json ?? [],
            operationalProfile: $this->operationalProfile($event),
            recentHistory: $recentHistory,
            tenantProfile: ['automation_level' => $profile->automationLevel],
            mediaAssessments: $this->mediaVerdicts($event),
            telemetry: $this->telemetry($event, $snapshot),
            location: $this->location($snapshot),
            asset: $this->asset($event, $snapshot),
            driver: $this->driver($event, $snapshot),
            incidents: $this->incidents($snapshot),
        );
    }

    /**
     * @return array<string, mixed>
     */
    private function eventBlock(NormalizedEvent $event): array
    {
        $timezone = $this->timezoneFor((int) $event->team_id);
        $local = $event->occurred_at !== null
            ? Carbon::instance($event->occurred_at)->setTimezone($timezone)
            : null;

        return [
            'id' => $event->id,
            'type_code' => $event->eventType?->code,
            'type_name' => $event->eventType?->name,
            'category' => $event->eventCategory?->code ?? $event->eventType?->category?->code,
            'severity' => $event->eventSeverity?->code,
            'severity_level' => $event->eventSeverity?->level,
            'occurred_at' => $event->occurred_at?->toIso8601String(),
            'occurred_at_local' => $local?->format('Y-m-d H:i:s'),
            'local_timezone' => $timezone,
            'local_day_of_week' => $local !== null ? strtolower($local->englishDayOfWeek) : null,
            'status' => $event->status->value,
            'payload' => $this->redact($event->payload_normalized_json ?? []),
        ];
    }

    private function timezoneFor(int $teamId): string
    {
        $timezone = Team::query()->whereKey($teamId)->value('timezone');

        if (is_string($timezone) && $timezone !== '') {
            try {
                new DateTimeZone($timezone);

                return $timezone;
            } catch (Throwable) {
                // Invalid identifier stored on the team: use the default.
            }
        }

        return self::DEFAULT_TIMEZONE;
    }

    /**
     * The Context domain persists the profile as its own model keyed by the
     * event (it never lived inside `signals_json`).
     *
     * @return array<string, mixed>
     */
    private function operationalProfile(NormalizedEvent $event): array
    {
        $profile = OperationalContextProfile::query()
            ->where('team_id', $event->team_id)
            ->where('normalized_event_id', $event->id)
            ->latest('id')
            ->first();

        if ($profile === null) {
            return [];
        }

        return [
            'profile_code' => $profile->profile_code,
            'risk_level' => $profile->risk_level?->value,
            'priority_score' => $profile->priority_score !== null ? (float) $profile->priority_score : null,
            'recurrence_score' => $profile->recurrence_score !== null ? (float) $profile->recurrence_score : null,
            'flags' => $profile->contextual_flags_json ?? [],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function telemetry(NormalizedEvent $event, ?EventContextSnapshot $snapshot): array
    {
        $telemetry = $snapshot?->telemetry_snapshot_json ?? [];
        $payload = $event->payload_normalized_json ?? [];

        $heading = $payload['heading'] ?? $payload['location']['heading'] ?? null;

        if ($heading === null && $event->asset_id !== null) {
            $heading = $event->asset?->latestLocation?->heading;
        }

        if ($telemetry === [] && $heading === null) {
            return [];
        }

        return [
            'speed_kph' => $telemetry['speed_kph'] ?? null,
            'heading_degrees' => is_numeric($heading) ? (float) $heading : null,
            'position_stale' => (bool) ($telemetry['position_stale'] ?? false),
            'gps_accuracy_meters' => $telemetry['gps_accuracy_meters'] ?? null,
            'recorded_at' => $telemetry['recorded_at'] ?? null,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function location(?EventContextSnapshot $snapshot): array
    {
        if ($snapshot === null) {
            return [];
        }

        $location = $snapshot->location_snapshot_json ?? [];

        return [
            'latitude' => $location['latitude'] ?? null,
            'longitude' => $location['longitude'] ?? null,
            'source' => $location['source'] ?? null,
            'geofences' => array_values(array_map(static fn (array $match): array => [
                'name' => $match['name'] ?? null,
                'category' => $match['category'] ?? null,
                'match_type' => $match['match_type'] ?? null,
                'distance_meters' => $match['distance_meters'] ?? null,
            ], array_filter($snapshot->geofence_snapshot_json ?? [], 'is_array'))),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function asset(NormalizedEvent $event, ?EventContextSnapshot $snapshot): array
    {
        $assetSnapshot = $snapshot?->asset_snapshot_json ?? [];

        if ($assetSnapshot === [] && $event->asset_id === null) {
            return [];
        }

        $asset = $event->asset_id !== null ? $event->asset()->with('assetType')->first() : null;

        return [
            'type' => $asset?->assetType?->code,
            'name' => $assetSnapshot['name'] ?? $asset?->name,
            'plate_or_code' => $assetSnapshot['code'] ?? $asset?->code,
            'status' => $assetSnapshot['status'] ?? $asset?->status?->value,
            'has_camera' => (bool) ($assetSnapshot['has_camera'] ?? ($asset?->metadata_json['has_camera'] ?? false)),
            'camera_status' => $assetSnapshot['camera_status'] ?? null,
        ];
    }

    /**
     * Driver operational risk only — never the driver's identity (name,
     * phone, employee code or license).
     *
     * @return array<string, mixed>
     */
    private function driver(NormalizedEvent $event, ?EventContextSnapshot $snapshot): array
    {
        if ($event->driver_id === null) {
            return [];
        }

        $driverSnapshot = $snapshot?->driver_snapshot_json ?? [];

        $driver = Driver::query()
            ->with('riskProfile')
            ->where('team_id', $event->team_id)
            ->find($event->driver_id);

        $riskProfile = $driver?->riskProfile;
        $firstSeen = $driver?->first_seen_at;

        return [
            'status' => $driverSnapshot['status'] ?? $driver?->status?->value,
            'tenure_days' => $firstSeen !== null
                ? (int) Carbon::instance($firstSeen)->diffInDays($event->occurred_at ?? now(), true)
                : null,
            'risk_level' => $riskProfile?->risk_level?->value,
            'risk_score' => $riskProfile?->risk_score !== null ? (float) $riskProfile->risk_score : null,
            'incidents_count' => $riskProfile?->incidents_count,
            'harsh_events_count' => $riskProfile?->harsh_events_count,
            'fatigue_flags_count' => $riskProfile?->fatigue_flags_count,
            'recent_risk_events_count' => $driverSnapshot['recent_risk_events_count'] ?? null,
            'has_unresolved_alerts' => $driverSnapshot['has_unresolved_alerts'] ?? null,
            'has_current_assignment' => ($driverSnapshot['current_assignment'] ?? null) !== null,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function incidents(?EventContextSnapshot $snapshot): array
    {
        $incidents = array_values(array_filter($snapshot?->incidents_snapshot_json ?? [], 'is_array'));

        if ($incidents === []) {
            return ['open_related_count' => 0, 'prior_similar_count' => 0, 'items' => []];
        }

        $prior = array_filter($incidents, static fn (array $incident): bool => ($incident['relation'] ?? null) === 'prior_similar_incident');

        return [
            'open_related_count' => count($incidents) - count($prior),
            'prior_similar_count' => count($prior),
            'items' => array_map(static fn (array $incident): array => [
                'relation' => $incident['relation'] ?? 'open_related',
                'type_code' => $incident['type_code'] ?? null,
                'status_code' => $incident['status_code'] ?? null,
                'priority_code' => $incident['priority_code'] ?? null,
                'opened_at' => $incident['opened_at'] ?? null,
                'closed_at' => $incident['closed_at'] ?? null,
            ], array_slice($incidents, 0, self::MAX_INCIDENT_ITEMS)),
        ];
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
     * Recursive PII redaction. Pre-signed media URLs are dropped entirely:
     * they are credentials with an expiry and carry no signal for the model.
     *
     * @param  array<array-key, mixed>  $payload
     * @return array<array-key, mixed>
     */
    private function redact(array $payload): array
    {
        foreach ($payload as $key => $value) {
            $normalizedKey = is_string($key) ? strtolower($key) : null;

            if ($normalizedKey !== null && in_array($normalizedKey, self::REDACTED_KEYS, true)) {
                $payload[$key] = '[redacted]';

                continue;
            }

            if ($normalizedKey !== null && is_string($value) && str_ends_with($normalizedKey, 'url')) {
                unset($payload[$key]);

                continue;
            }

            if (is_array($value)) {
                $payload[$key] = $this->redact($value);
            }
        }

        return $payload;
    }
}
