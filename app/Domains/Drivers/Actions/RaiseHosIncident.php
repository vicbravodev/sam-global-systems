<?php

namespace App\Domains\Drivers\Actions;

use App\Domains\Drivers\Enums\HosSituation;
use App\Domains\Drivers\Models\HosEpisode;
use App\Domains\Ingestion\Actions\QueueRawEventForProcessing;
use App\Domains\Ingestion\Actions\StoreRawEvent;
use App\Domains\Ingestion\Enums\EventSourceType;
use App\Domains\Ingestion\Models\RawEvent;
use App\Domains\Integrations\Data\HosClockReading;
use App\Support\SystemLog;
use Carbon\CarbonInterface;

/**
 * Hands an HOS episode to the incident pipeline, like the other internal
 * monitors (DetectUnauthorizedStopJob): an `internal_monitor` raw event
 * (`hos_limit_exceeded` when the Samsara clocks show a violation, `hos_unattended` when
 * the reminder ladder ran out) keyed `hos:{episode}`, so it is raised once
 * per episode however many cycles retry. Normalization binds the unit and
 * the driver (same tenant only), the rule engine resolves it without AI
 * (`ai.rule_resolved_event_types`) and the `hos-incident` rule opens the
 * incident.
 *
 * Must run inside the episode's TenantContext.
 */
class RaiseHosIncident
{
    public const string MONITOR = 'hos_watchdog';

    public const string LIMIT_EXCEEDED_EVENT_TYPE = 'hos_limit_exceeded';

    public const string UNATTENDED_EVENT_TYPE = 'hos_unattended';

    public function __construct(
        private readonly StoreRawEvent $storeRawEvent,
        private readonly QueueRawEventForProcessing $queueForProcessing,
    ) {}

    public static function deduplicationKey(HosEpisode $episode): string
    {
        return "hos:{$episode->id}";
    }

    /**
     * @return array{raised: bool, reason: string|null, raw_event_id: int|null}
     */
    public function execute(HosEpisode $episode, ?HosClockReading $current, CarbonInterface $now): array
    {
        $eventType = $episode->situation === HosSituation::Violation
            ? self::LIMIT_EXCEEDED_EVENT_TYPE
            : self::UNATTENDED_EVENT_TYPE;
        $key = self::deduplicationKey($episode);
        $input = ['team_id' => $episode->team_id, 'episode_id' => $episode->id, 'driver_id' => $episode->driver_id];
        $calc = ['situation' => $episode->situation->value, 'event_type_code' => $eventType, 'ladder_step' => $episode->ladder_step];

        // La ruta interna de normalización exige la unidad (internal.asset_id).
        if ($episode->asset_id === null) {
            SystemLog::skipped('hos.incident.raised', reason: 'no_asset', input: $input, calc: $calc);

            return ['raised' => false, 'reason' => 'no_asset', 'raw_event_id' => null];
        }

        $existing = RawEvent::query()
            ->where('team_id', $episode->team_id)
            ->where('deduplication_key', $key)
            ->value('id');

        if (is_numeric($existing)) {
            SystemLog::skipped('hos.incident.raised', reason: 'already_raised', input: $input, calc: $calc, result: ['raw_event_id' => (int) $existing]);

            return ['raised' => false, 'reason' => 'already_raised', 'raw_event_id' => (int) $existing];
        }

        $rawEvent = $this->storeRawEvent->execute(
            payload: [
                'eventType' => $eventType,
                'time' => $now->toIso8601String(),
                'internal' => [
                    'monitor' => self::MONITOR,
                    'asset_id' => $episode->asset_id,
                    'driver_id' => $episode->driver_id,
                    'episode_id' => $episode->id,
                ],
                'hos' => [
                    'situation' => $episode->situation->value,
                    'opened_at' => $episode->opened_at->toIso8601String(),
                    'ladder_step' => $episode->ladder_step,
                    'clocks_at_open' => $episode->snapshot_json,
                    'clocks_now' => $current?->toArray(),
                ],
            ],
            sourceType: EventSourceType::InternalMonitor->value,
            teamId: $episode->team_id,
            providerId: null,
            deduplicationKey: $key,
            eventTypeRaw: $eventType,
        );

        $this->queueForProcessing->execute($rawEvent);

        SystemLog::ok('hos.incident.raised', input: $input + ['asset_id' => $episode->asset_id], calc: $calc, result: [
            'raw_event_id' => $rawEvent->id,
            'job_requested' => true,
        ]);

        return ['raised' => true, 'reason' => null, 'raw_event_id' => $rawEvent->id];
    }
}
