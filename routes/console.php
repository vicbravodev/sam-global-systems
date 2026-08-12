<?php

use App\Domains\Analytics\Jobs\BuildAnalyticsSnapshotJob;
use App\Domains\Analytics\Jobs\CalculateDailyKPIsJob;
use App\Domains\Analytics\Jobs\ExpireOldReportsJob;
use App\Domains\Assets\Jobs\DetectAfterHoursMovementJob;
use App\Domains\Assets\Jobs\DetectOfflineAssetsJob;
use App\Domains\Assets\Jobs\DetectUnauthorizedStopJob;
use App\Domains\Assets\Jobs\PollAllAssetLocationsJob;
use App\Domains\Assets\Jobs\PollAllAssetTelemetryJob;
use App\Domains\Assets\Jobs\PurgeOldAssetTelemetryJob;
use App\Domains\Drivers\Jobs\RecalculateDriverRiskProfilesJob;
use App\Domains\Ingestion\Jobs\PollSamsaraSafetyEventsJob;
use App\Domains\Ingestion\Jobs\PruneDeduplicationKeysJob;
use App\Domains\Integrations\Jobs\SyncDueIntegrationsJob;
use App\Domains\Tenancy\Jobs\AggregateUsageJob;
use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

Schedule::command('horizon:snapshot')->everyFiveMinutes()->onOneServer();

Schedule::job(new AggregateUsageJob)->dailyAt('02:00')->onOneServer();
Schedule::job(new CalculateDailyKPIsJob)->dailyAt('03:00')->onOneServer();

// Deduplication keys expire after 24h but nothing removed the rows: the table
// grew unbounded with every ingested event. Daily purge keeps it bounded.
Schedule::job(new PruneDeduplicationKeysJob)->dailyAt('03:15')->onOneServer();

// ExpireOldReports (spec 15) is a per-tenant Action, not a console command:
// this job fans it out across every team with an active/trialing/past-due
// subscription, same pattern as CalculateDailyKPIsJob/BuildAnalyticsSnapshotJob.
// Without this, report retention policy was written but never enforced —
// expired report files never got deleted from storage.
Schedule::job(new ExpireOldReportsJob)->dailyAt('03:30')->onOneServer();

Schedule::job(new BuildAnalyticsSnapshotJob)->dailyAt('04:00')->onOneServer();

// Daily driver risk recalculation (Roadmap V2-D1): aggregates safety events
// into DriverRiskProfile and raises preventive deterioration alerts.
Schedule::job(new RecalculateDriverRiskProfilesJob)->dailyAt('04:30')->onOneServer();

// Background syncing of every active integration. The orchestrators fan out
// per-tenant work and self-gate by interval (configurable per integration via
// config_json.sync), so these ticks are the floor cadence, not the exact rate.
Schedule::job(new SyncDueIntegrationsJob)->everyFifteenMinutes()->onOneServer();
Schedule::job(new PollAllAssetLocationsJob)->everyFiveMinutes()->onOneServer();
Schedule::job(new PollSamsaraSafetyEventsJob)->everyTwoMinutes()->onOneServer();

// Onboard diagnostics (fuel, odometer, battery, engine state, temperature).
// Slower floor than positions on purpose: these stats change by the percent or
// the kilometre, so a faster tick spends requests without yielding readings.
Schedule::job(new PollAllAssetTelemetryJob)->everyFifteenMinutes()->onOneServer();
Schedule::job(new PurgeOldAssetTelemetryJob)->dailyAt('03:45')->onOneServer();

// Offline-asset watchdog (Roadmap V2-C1): silence beyond the tenant/asset
// threshold raises an internal `device_offline` event through the pipeline.
Schedule::job(new DetectOfflineAssetsJob)->everyFiveMinutes()->onOneServer();

// After-hours movement detector (Roadmap V2-C2): a unit moving while the
// tenant's schedule says "closed" raises an internal event (theft/misuse).
Schedule::job(new DetectAfterHoursMovementJob)->everyFiveMinutes()->onOneServer();

// Unauthorized-stop detector (Roadmap V2-C3): a prolonged stop outside every
// known geofence raises an internal `suspicious_stop` event.
Schedule::job(new DetectUnauthorizedStopJob)->everyFiveMinutes()->onOneServer();
