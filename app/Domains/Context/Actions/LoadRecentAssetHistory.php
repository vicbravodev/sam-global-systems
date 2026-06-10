<?php

namespace App\Domains\Context\Actions;

use App\Domains\Normalization\Models\EventType;
use App\Domains\Normalization\Models\NormalizedEvent;
use DateTimeInterface;
use Illuminate\Support\Carbon;

class LoadRecentAssetHistory
{
    /**
     * Load recent normalized events for an asset within a time window ending at `$before`.
     *
     * @return array{
     *     window_start: Carbon,
     *     window_end: Carbon,
     *     recent_events_count: int,
     *     recent_incidents_count: int,
     *     recent_same_type_count: int,
     *     recent_high_severity_count: int,
     *     recent_locations_json: array<int, array<string, mixed>>,
     *     recent_flags_json: array<string, mixed>,
     * }
     */
    public function execute(
        ?int $assetId,
        ?int $currentEventTypeId,
        DateTimeInterface $before,
        int $windowMinutes = 60,
    ): array {
        $windowEnd = Carbon::instance($before);
        $windowStart = $windowEnd->copy()->subMinutes($windowMinutes);

        if ($assetId === null) {
            return $this->emptyWindow($windowStart, $windowEnd);
        }

        $events = NormalizedEvent::withoutGlobalScopes()
            ->where('asset_id', $assetId)
            ->whereBetween('occurred_at', [$windowStart, $windowEnd])
            ->with(['eventSeverity', 'eventType'])
            ->get();

        $recentSameTypeCount = $currentEventTypeId
            ? $events->where('event_type_id', $currentEventTypeId)->count()
            : 0;

        $highSeverityCount = $events->filter(function (NormalizedEvent $event) {
            $code = $event->eventSeverity?->code;

            return in_array($code, ['high', 'critical'], true);
        })->count();

        $locations = $events->map(function (NormalizedEvent $event) {
            $location = $event->payload_normalized_json['location'] ?? null;

            if (! is_array($location)) {
                return null;
            }

            return [
                'occurred_at' => $event->occurred_at?->toIso8601String(),
                'latitude' => $location['latitude'] ?? null,
                'longitude' => $location['longitude'] ?? null,
            ];
        })->filter()->values()->all();

        return [
            'window_start' => $windowStart,
            'window_end' => $windowEnd,
            'recent_events_count' => $events->count(),
            'recent_incidents_count' => 0,
            'recent_same_type_count' => $recentSameTypeCount,
            'recent_high_severity_count' => $highSeverityCount,
            'repeated_panic_count_24h' => $this->repeatedPanicCount24h($assetId, $windowEnd),
            'recent_locations_json' => $locations,
            'recent_flags_json' => [],
        ];
    }

    /**
     * Panic events from the same asset over the last 24 hours (independent of
     * the regular history window): repeated panics feed the false-alarm
     * validation signals (Roadmap B6-P7).
     */
    private function repeatedPanicCount24h(int $assetId, Carbon $windowEnd): int
    {
        $panicTypeId = EventType::query()->where('code', 'panic_button')->value('id');

        if ($panicTypeId === null) {
            return 0;
        }

        return NormalizedEvent::withoutGlobalScopes()
            ->where('asset_id', $assetId)
            ->where('event_type_id', $panicTypeId)
            ->whereBetween('occurred_at', [$windowEnd->copy()->subDay(), $windowEnd])
            ->count();
    }

    /**
     * @return array{
     *     window_start: Carbon,
     *     window_end: Carbon,
     *     recent_events_count: int,
     *     recent_incidents_count: int,
     *     recent_same_type_count: int,
     *     recent_high_severity_count: int,
     *     recent_locations_json: array<int, array<string, mixed>>,
     *     recent_flags_json: array<string, mixed>,
     * }
     */
    private function emptyWindow(Carbon $start, Carbon $end): array
    {
        return [
            'window_start' => $start,
            'window_end' => $end,
            'recent_events_count' => 0,
            'recent_incidents_count' => 0,
            'recent_same_type_count' => 0,
            'recent_high_severity_count' => 0,
            'repeated_panic_count_24h' => 0,
            'recent_locations_json' => [],
            'recent_flags_json' => [],
        ];
    }
}
