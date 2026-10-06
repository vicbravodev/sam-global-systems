<?php

/*
|--------------------------------------------------------------------------
| Telematics feed
|--------------------------------------------------------------------------
|
| Near-real-time fleet state follows the provider's stats feed
| (Samsara: GET /fleet/vehicles/stats/feed) with a persisted cursor per
| integration and feed, instead of polling snapshots. Every interval is a
| floor: Samsara asks for at least 5 seconds between requests once a feed
| has no more pages, so nothing here goes below that.
|
| Per integration, `config_json.sync.feed_enabled` (default true) turns the
| feed off and `config_json.sync.feed_interval_seconds` slows the motion
| feed down for that tenant.
|
*/

return [

    /*
    | Seconds between two polls of the motion feed (GPS, engine state, fuel).
    | 5 is the provider's floor; 10–15 is a prudent production value while
    | the load profile is still unknown.
    */
    'interval_seconds' => max(5, (int) env('TELEMATICS_FEED_INTERVAL', 5)),

    /*
    | Seconds between two polls of the diagnostics feed (odometer, battery,
    | ambient temperature). These move slowly; polling them as often as GPS
    | spends requests without adding readings.
    */
    'diagnostics_interval_seconds' => max(5, (int) env('TELEMATICS_DIAGNOSTICS_INTERVAL', 30)),

    /*
    | Seconds between two polls of the trailers feed (GPS of the AG asset
    | gateways). A moving trailer reports every 5 s to 3 min, and the coupling
    | is judged over minutes, so a slower cadence loses nothing. Only polled
    | for integrations that have trailers.
    */
    'trailers_interval_seconds' => max(5, (int) env('TELEMATICS_TRAILERS_INTERVAL', 60)),

    /*
    | Tractor–trailer coupling (EvaluateTrailerCouplingsJob), judged by
    | co-movement — never by proximity alone: parked in a yard, one tractor is
    | the nearest unit to dozens of trailers.
    |
    | Every moving trailer point in the last `window_minutes` is compared with
    | the tractor's position at that same instant, interpolated between the
    | tractor's points that bracket it (at most `max_bracket_gap_seconds`
    | apart; otherwise the point is not comparable). A point matches when the
    | two are within `match_radius_m`. A tractor is coupled when at least
    | `min_compared_points` points were comparable over `min_span_seconds`
    | or more and `couple_ratio` of them matched.
    |
    | A coupling ends when the trailer, moving, matches its tractor in at most
    | `decouple_ratio` of the comparable points, or when the tractor drives
    | (`min_compared_points` moving points) while the trailer stays still
    | farther than `left_behind_m` away. While both are stopped it holds.
    */
    'coupling' => [
        'window_minutes' => 10,
        'max_bracket_gap_seconds' => 120,
        'match_radius_m' => 250,
        'min_compared_points' => 3,
        'min_span_seconds' => 90,
        'couple_ratio' => 0.8,
        'decouple_ratio' => 0.2,
        'left_behind_m' => 2000,
    ],

    /*
    | Hard cap on pages followed in one cycle, so a feed that keeps saying
    | `hasNextPage` (a large backlog after an outage) yields the worker back
    | instead of pinning it; the next cycle continues from the saved cursor.
    */
    'max_pages_per_cycle' => (int) env('TELEMATICS_MAX_PAGES_PER_CYCLE', 20),

    /*
    | When a cursor is lost or rejected, the gap since the last received point
    | is refilled from the history endpoint — at most this many hours back.
    */
    'backfill_hours' => (int) env('TELEMATICS_BACKFILL_HOURS', 24),

    'http' => [
        'connect_timeout' => (int) env('TELEMATICS_CONNECT_TIMEOUT', 2),
        'timeout' => (int) env('TELEMATICS_TIMEOUT', 8),
    ],

    /*
    | Exponential backoff after a 5xx or a timeout: base * 2^(failures - 1),
    | capped. A 429 uses the provider's Retry-After instead.
    */
    'backoff' => [
        'base_seconds' => 5,
        'max_seconds' => 300,
    ],

    /*
    | Motion state kept on the asset (`last_moving_at` / `stopped_since`).
    |
    | A parked unit's GPS reports phantom speeds of 1–3 km/h (seen live: the
    | same spot, ±2 m, reading 2.95 km/h). So a point counts as moving only at
    | `moving_speed_kph` or more — the threshold every other SAM surface uses —
    | and a stopped unit is only moving again once it is also farther than
    | `stop_exit_radius_m` from where it stopped.
    */
    'moving_speed_kph' => 5.0,
    'stop_exit_radius_m' => 50,

    /*
    | Unauthorized-stop alerts: a new stop within `stop_realert_radius_m` of
    | the last alerted one, within `stop_realert_hours`, is the same place and
    | is not alerted again (a unit shuffling around a yard).
    */
    'stop_realert_radius_m' => 200,
    'stop_realert_hours' => 6,

    /*
    | After-hours movement: at most one alert per unit per this many hours, so
    | one night of driving is one alert even when it crosses local midnight.
    */
    'after_hours_cooldown_hours' => 12,

    /*
    | After-hours movement inside one of these geofence categories of the
    | tenant (its own base, a client's site) is expected yard or delivery
    | activity, not misuse: no alert.
    */
    'after_hours_safe_geofence_categories' => ['base', 'client_site'],

    'retention' => [
        // Raw GPS points. Every reader of the history looks at most 24 h back;
        // incident trails are frozen into the incident before this runs.
        'location_days' => (int) env('TELEMATICS_LOCATION_RETENTION_DAYS', 30),
    ],

    /*
    | Minutes of trail frozen into an incident's evidence on each side of the
    | moment it was opened, so the purge never destroys what an incident needs.
    */
    'incident_trail_minutes' => 30,

    /*
    |--------------------------------------------------------------------------
    | Integration health (decisión 2026-09-28)
    |--------------------------------------------------------------------------
    |
    | Minutes without provider data (feed, webhooks, events) — with monitored
    | units — before the tenant admin is warned that the integration went
    | silent. CheckIntegrationHealthJob.
    |
    */
    'integration_silence_minutes' => (int) env('TELEMATICS_INTEGRATION_SILENCE_MINUTES', 30),
];
