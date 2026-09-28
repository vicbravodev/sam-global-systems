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
