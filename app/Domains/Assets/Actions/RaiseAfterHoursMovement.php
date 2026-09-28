<?php

namespace App\Domains\Assets\Actions;

use App\Domains\Assets\Enums\AssetStatus;
use App\Domains\Assets\Models\Asset;
use App\Domains\Assets\Support\MovementCriterion;
use App\Domains\Ingestion\Actions\QueueRawEventForProcessing;
use App\Domains\Ingestion\Actions\StoreRawEvent;
use App\Domains\Ingestion\Enums\EventSourceType;
use App\Domains\TenantConfig\Data\ResolvedSchedule;
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
        if (! $schedule->isPersisted || $schedule->withinOperatingHours) {
            return false;
        }

        if (in_array($asset->status, [AssetStatus::Inactive, AssetStatus::Maintenance], true)) {
            return false;
        }

        // Criterio único de movimiento: velocidad Y estado (un pico de GPS de
        // un tracto estacionado en el patio no es "movimiento fuera de horario").
        if (
            ! MovementCriterion::isMoving($asset, $speedKph)
            || $recordedAt->lt(now()->subMinutes(self::FRESHNESS_MINUTES))
        ) {
            return false;
        }

        // One alert per unit per closed stretch: a night of driving crosses
        // local midnight, and a per-day key alerted it twice.
        $cooldownHours = (int) config('telematics.after_hours_cooldown_hours', 12);

        if ($asset->after_hours_alerted_at !== null && $asset->after_hours_alerted_at->gt(now()->subHours($cooldownHours))) {
            return false;
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

        return true;
    }
}
