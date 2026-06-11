<?php

namespace App\Domains\Context\Actions;

use App\Domains\Normalization\Models\EventType;
use App\Domains\Normalization\Models\NormalizedEvent;
use DateTimeInterface;
use Illuminate\Support\Carbon;

class LoadRecentAssetHistory
{
    /**
     * Event-type codes that indicate harsh driving or evasive maneuvering —
     * near a panic they weigh toward a real assault/forced-stop scenario
     * (Roadmap V2-A2).
     *
     * @var array<int, string>
     */
    public const array HARSH_DRIVING_CODES = [
        'harsh_braking',
        'harsh_acceleration',
        'harsh_turn',
        'aggressive_driving',
        'near_collision',
        'forward_collision_warning',
        'yaw_control',
    ];

    /**
     * Load recent normalized events for an asset within a time window ending
     * at `$before`, plus the safety correlation around the event itself
     * (Roadmap V2-A2): safety/emergency events of the same asset inside
     * `$before ± $correlationMinutes` (the current event excluded), broken
     * down by type and flagged when any harsh-driving maneuver is present.
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
        int $correlationMinutes = 30,
        ?int $excludeEventId = null,
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

        $correlation = $this->correlateNearbySafetyEvents($assetId, $windowEnd, $correlationMinutes, $excludeEventId);

        return [
            'window_start' => $windowStart,
            'window_end' => $windowEnd,
            'recent_events_count' => $events->count(),
            'recent_incidents_count' => 0,
            'recent_same_type_count' => $recentSameTypeCount,
            'recent_high_severity_count' => $highSeverityCount,
            'repeated_panic_count_24h' => $this->repeatedPanicCount24h($assetId, $windowEnd),
            'nearby_safety_events_count' => $correlation['count'],
            'nearby_safety_breakdown' => $correlation['breakdown'],
            'harsh_driving_near_event' => $correlation['harsh'],
            'recent_locations_json' => $locations,
            'recent_flags_json' => [
                'harsh_driving_near_event' => $correlation['harsh'],
                'nearby_safety_events_count' => $correlation['count'],
                'nearby_safety_breakdown' => $correlation['breakdown'],
            ],
        ];
    }

    /**
     * Safety/emergency events of the same asset in the centered window
     * `$around ± $correlationMinutes` — evidence of what was happening on the
     * road right before AND right after the event under evaluation.
     *
     * @return array{count: int, breakdown: array<string, int>, harsh: bool}
     */
    private function correlateNearbySafetyEvents(
        int $assetId,
        Carbon $around,
        int $correlationMinutes,
        ?int $excludeEventId,
    ): array {
        $events = NormalizedEvent::withoutGlobalScopes()
            ->where('asset_id', $assetId)
            ->when($excludeEventId !== null, fn ($query) => $query->whereKeyNot($excludeEventId))
            ->whereBetween('occurred_at', [
                $around->copy()->subMinutes($correlationMinutes),
                $around->copy()->addMinutes($correlationMinutes),
            ])
            ->whereHas('eventType.category', fn ($query) => $query->whereIn('code', ['safety', 'emergency']))
            ->with('eventType')
            ->get();

        $breakdown = [];

        foreach ($events as $event) {
            $code = $event->eventType?->code ?? 'unknown';
            $breakdown[$code] = ($breakdown[$code] ?? 0) + 1;
        }

        $harsh = $events->contains(
            fn (NormalizedEvent $event) => in_array($event->eventType?->code, self::HARSH_DRIVING_CODES, true),
        );

        return [
            'count' => $events->count(),
            'breakdown' => $breakdown,
            'harsh' => $harsh,
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
            'nearby_safety_events_count' => 0,
            'nearby_safety_breakdown' => [],
            'harsh_driving_near_event' => false,
            'recent_locations_json' => [],
            'recent_flags_json' => [],
        ];
    }
}
