<?php

namespace App\Domains\Assets\Actions;

use App\Domains\Assets\Enums\AssetStatus;
use App\Domains\Assets\Models\Asset;
use App\Domains\Assets\Support\MovementCriterion;
use App\Domains\Ingestion\Actions\QueueRawEventForProcessing;
use App\Domains\Ingestion\Actions\StoreRawEvent;
use App\Domains\Ingestion\Enums\EventSourceType;
use App\Domains\TenantConfig\Data\ResolvedSchedule;
use App\Support\LoggableCode;
use App\Support\SystemLog;
use Carbon\CarbonInterface;

/**
 * After-hours movement detector (Roadmap V2-C2): an asset moving while the
 * tenant's schedule profile says the operation is closed raises an internal
 * `after_hours_movement` event through the full pipeline — a unit rolling at
 * 3 AM is a theft/misuse signal in the Mexican monitoring context.
 *
 * Evaluated inline by the telematics feed on every fresh moving point, so the
 * alert fires within one feed cycle instead of waiting for a sweep.
 *
 * Requires a persisted, active `TenantScheduleProfile`: tenants without one
 * are treated as always-operating and never alerted. Anti-spam: one event
 * per asset per `telematics.after_hours_cooldown_hours` (12 h), tracked on
 * `assets.after_hours_alerted_at`, so a night of driving is one alert even
 * when it crosses local midnight.
 */
class RaiseAfterHoursMovement
{
    public const string EVENT_TYPE_CODE = 'after_hours_movement';

    /**
     * @deprecated El umbral vive en MovementCriterion (config telematics.moving_speed_kph).
     */
    public const float MIN_SPEED_KPH = 5.0;

    /** Positions older than this are not evidence of current movement. */
    public const int FRESHNESS_MINUTES = 15;

    public function __construct(
        private readonly StoreRawEvent $storeRawEvent,
        private readonly QueueRawEventForProcessing $queueForProcessing,
    ) {}

    /**
     * Returns whether an event was raised.
     */
    public function execute(
        Asset $asset,
        ResolvedSchedule $schedule,
        float $latitude,
        float $longitude,
        ?float $speedKph,
        CarbonInterface $recordedAt,
    ): bool {
        $evaluation = $this->evaluate($asset, $schedule, $latitude, $longitude, $speedKph, $recordedAt);

        $this->log($asset, $evaluation);

        return $evaluation['raised'];
    }

    /**
     * Run the detector and say which branch decided it, with the terms it
     * judged: speed against the moving threshold, the asset's motion state,
     * the position's age against the freshness window and the last alert's
     * age against the cooldown. Never the coordinates, the local time or the
     * schedule's timezone.
     *
     * @return array{raised: bool, branch: 'outside_schedule_gate'|'asset_inactive'|'not_moving'|'stale_position'|'cooldown_active'|'raised', calc: array<string, mixed>, raw_event_id: ?int}
     */
    public function evaluate(
        Asset $asset,
        ResolvedSchedule $schedule,
        float $latitude,
        float $longitude,
        ?float $speedKph,
        CarbonInterface $recordedAt,
    ): array {
        $cooldownHours = (int) config('telematics.after_hours_cooldown_hours', 12);

        $calc = [
            'speed_kph' => $speedKph,
            'moving_threshold_kph' => MovementCriterion::speedThresholdKph(),
            'motion_state_moving' => MovementCriterion::assetIsMoving($asset),
            'position_age_s' => (int) $recordedAt->diffInSeconds(now()),
            'freshness_s' => self::FRESHNESS_MINUTES * 60,
            'cooldown_s' => $cooldownHours * 3600,
            'last_alert_age_s' => $asset->after_hours_alerted_at !== null
                ? (int) $asset->after_hours_alerted_at->diffInSeconds(now())
                : null,
            'schedule_profile_code' => LoggableCode::guard($schedule->profileCode),
        ];

        if (! $schedule->isPersisted || $schedule->withinOperatingHours) {
            return $this->outcome('outside_schedule_gate', $calc);
        }

        if (in_array($asset->status, [AssetStatus::Inactive, AssetStatus::Maintenance], true)) {
            return $this->outcome('asset_inactive', [...$calc, 'asset_status' => $asset->status->value]);
        }

        // Criterio único de movimiento: velocidad Y estado (un pico de GPS de
        // un tracto estacionado en el patio no es "movimiento fuera de horario").
        if (! MovementCriterion::isMoving($asset, $speedKph)) {
            return $this->outcome('not_moving', $calc);
        }

        if ($recordedAt->lt(now()->subMinutes(self::FRESHNESS_MINUTES))) {
            return $this->outcome('stale_position', $calc);
        }

        // One alert per unit per closed stretch: a night of driving crosses
        // local midnight, and a per-day key alerted it twice.
        if ($asset->after_hours_alerted_at !== null && $asset->after_hours_alerted_at->gt(now()->subHours($cooldownHours))) {
            return $this->outcome('cooldown_active', $calc);
        }

        $deduplicationKey = sprintf('after_hours:%d:%d', $asset->id, now()->getTimestamp());

        $rawEvent = $this->storeRawEvent->execute(
            payload: [
                'eventType' => self::EVENT_TYPE_CODE,
                'time' => now()->toIso8601String(),
                'internal' => [
                    'monitor' => 'after_hours_watchdog',
                    'asset_id' => $asset->id,
                ],
                'asset_name' => $asset->name,
                'asset_code' => $asset->code,
                'speed_kph' => $speedKph,
                'local_time' => now()->setTimezone($schedule->timezone)->toIso8601String(),
                'schedule_profile' => $schedule->profileCode,
                'location' => [
                    'latitude' => $latitude,
                    'longitude' => $longitude,
                ],
            ],
            sourceType: EventSourceType::InternalMonitor->value,
            teamId: (int) $asset->team_id,
            providerId: null,
            deduplicationKey: $deduplicationKey,
            eventTypeRaw: self::EVENT_TYPE_CODE,
        );

        $this->queueForProcessing->execute($rawEvent);

        $asset->forceFill(['after_hours_alerted_at' => now()])->save();

        return $this->outcome('raised', $calc, $rawEvent->id);
    }

    /**
     * @param  'outside_schedule_gate'|'asset_inactive'|'not_moving'|'stale_position'|'cooldown_active'|'raised'  $branch
     * @param  array<string, mixed>  $calc
     * @return array{raised: bool, branch: 'outside_schedule_gate'|'asset_inactive'|'not_moving'|'stale_position'|'cooldown_active'|'raised', calc: array<string, mixed>, raw_event_id: ?int}
     */
    private function outcome(string $branch, array $calc, ?int $rawEventId = null): array
    {
        return [
            'raised' => $branch === 'raised',
            'branch' => $branch,
            'calc' => $calc,
            'raw_event_id' => $rawEventId,
        ];
    }

    /**
     * @param  array{raised: bool, branch: string, calc: array<string, mixed>, raw_event_id: ?int}  $evaluation
     */
    private function log(Asset $asset, array $evaluation): void
    {
        $input = ['team_id' => (int) $asset->team_id, 'asset_id' => $asset->id];

        if ($evaluation['raised']) {
            SystemLog::ok('assets.after_hours.evaluated', input: $input, calc: $evaluation['calc'], result: [
                'raised' => true,
                'raw_event_id' => $evaluation['raw_event_id'],
                'job_requested' => true,
            ], channel: 'telematics');

            return;
        }

        // The schedule gate repeats the feed's own check; an inactive unit is
        // the only branch worth reading at info.
        $reason = $evaluation['branch'] === 'outside_schedule_gate' ? 'within_operating_hours' : $evaluation['branch'];

        SystemLog::skipped(
            'assets.after_hours.evaluated',
            reason: $reason,
            input: $input,
            calc: $evaluation['calc'],
            debug: $reason !== 'asset_inactive',
            channel: 'telematics',
        );
    }
}
