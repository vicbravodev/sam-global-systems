<?php

namespace App\Domains\Assets\Jobs;

use App\Contracts\TenantConfig\TenantScheduleResolver;
use App\Domains\Assets\Enums\AssetStatus;
use App\Domains\Assets\Models\Asset;
use App\Domains\Ingestion\Actions\QueueRawEventForProcessing;
use App\Domains\Ingestion\Actions\StoreRawEvent;
use App\Domains\Ingestion\Enums\EventSourceType;
use App\Domains\Ingestion\Models\RawEvent;
use App\Domains\TenantConfig\Data\ResolvedSchedule;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

/**
 * After-hours movement detector (Roadmap V2-C2): an asset moving while the
 * tenant's schedule profile says the operation is closed raises an internal
 * `after_hours_movement` event through the full pipeline — a unit rolling at
 * 3 AM is a theft/misuse signal in the Mexican monitoring context.
 *
 * Requires a persisted, active `TenantScheduleProfile`: tenants without one
 * are treated as always-operating and never alerted. Anti-spam: one event
 * per asset per local day (`after_hours:{asset}:{local date}`).
 */
class DetectAfterHoursMovementJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public const string EVENT_TYPE_CODE = 'after_hours_movement';

    /** Speed (km/h) below which a fresh position does not count as moving. */
    public const float MIN_SPEED_KPH = 5.0;

    /** Positions older than this are not evidence of current movement. */
    public const int FRESHNESS_MINUTES = 15;

    public function __construct()
    {
        $this->onQueue('ingestion');
    }

    public function handle(
        TenantScheduleResolver $scheduleResolver,
        StoreRawEvent $storeRawEvent,
        QueueRawEventForProcessing $queueForProcessing,
    ): void {
        /** @var array<int, ResolvedSchedule> $schedules */
        $schedules = [];

        Asset::withoutGlobalScopes()
            ->whereNotNull('team_id')
            ->whereNotIn('status', [AssetStatus::Inactive, AssetStatus::Maintenance])
            ->with('latestLocation')
            ->chunkById(200, function ($assets) use (&$schedules, $scheduleResolver, $storeRawEvent, $queueForProcessing) {
                foreach ($assets as $asset) {
                    $teamId = (int) $asset->team_id;
                    $schedules[$teamId] ??= $scheduleResolver->resolve($teamId);

                    $this->inspectAsset($asset, $schedules[$teamId], $storeRawEvent, $queueForProcessing);
                }
            });
    }

    private function inspectAsset(
        Asset $asset,
        ResolvedSchedule $schedule,
        StoreRawEvent $storeRawEvent,
        QueueRawEventForProcessing $queueForProcessing,
    ): void {
        if (! $schedule->isPersisted || $schedule->withinOperatingHours) {
            return;
        }

        $location = $asset->latestLocation;

        if (
            $location === null
            || $location->speed === null
            || (float) $location->speed < self::MIN_SPEED_KPH
            || $location->recorded_at === null
            || $location->recorded_at->lt(now()->subMinutes(self::FRESHNESS_MINUTES))
        ) {
            return;
        }

        $localDate = now()->setTimezone($schedule->timezone)->toDateString();
        $deduplicationKey = sprintf('after_hours:%d:%s', $asset->id, $localDate);

        $alreadyRaised = RawEvent::withoutGlobalScopes()
            ->where('team_id', $asset->team_id)
            ->where('deduplication_key', $deduplicationKey)
            ->exists();

        if ($alreadyRaised) {
            return;
        }

        $rawEvent = $storeRawEvent->execute(
            payload: [
                'eventType' => self::EVENT_TYPE_CODE,
                'time' => now()->toIso8601String(),
                'internal' => [
                    'monitor' => 'after_hours_watchdog',
                    'asset_id' => $asset->id,
                ],
                'asset_name' => $asset->name,
                'asset_code' => $asset->code,
                'speed_kph' => (float) $location->speed,
                'local_time' => now()->setTimezone($schedule->timezone)->toIso8601String(),
                'schedule_profile' => $schedule->profileCode,
                'location' => $location->latitude !== null ? [
                    'latitude' => (float) $location->latitude,
                    'longitude' => (float) $location->longitude,
                ] : null,
            ],
            sourceType: EventSourceType::InternalMonitor->value,
            teamId: (int) $asset->team_id,
            providerId: null,
            deduplicationKey: $deduplicationKey,
            eventTypeRaw: self::EVENT_TYPE_CODE,
        );

        $queueForProcessing->execute($rawEvent);
    }
}
