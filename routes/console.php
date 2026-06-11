<?php

use App\Domains\Analytics\Jobs\BuildAnalyticsSnapshotJob;
use App\Domains\Analytics\Jobs\CalculateDailyKPIsJob;
use App\Domains\Assets\Jobs\DetectAfterHoursMovementJob;
use App\Domains\Assets\Jobs\DetectOfflineAssetsJob;
use App\Domains\Assets\Jobs\PollAllAssetLocationsJob;
use App\Domains\Ingestion\Jobs\PollSamsaraSafetyEventsJob;
use App\Domains\Integrations\Jobs\SyncDueIntegrationsJob;
use App\Domains\Tenancy\Jobs\AggregateUsageJob;
use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

Schedule::job(new AggregateUsageJob)->dailyAt('02:00')->onOneServer();
Schedule::job(new CalculateDailyKPIsJob)->dailyAt('03:00')->onOneServer();
Schedule::job(new BuildAnalyticsSnapshotJob)->dailyAt('04:00')->onOneServer();

// Background syncing of every active integration. The orchestrators fan out
// per-tenant work and self-gate by interval (configurable per integration via
// config_json.sync), so these ticks are the floor cadence, not the exact rate.
Schedule::job(new SyncDueIntegrationsJob)->everyFifteenMinutes()->onOneServer();
Schedule::job(new PollAllAssetLocationsJob)->everyFiveMinutes()->onOneServer();
Schedule::job(new PollSamsaraSafetyEventsJob)->everyTwoMinutes()->onOneServer();

// Offline-asset watchdog (Roadmap V2-C1): silence beyond the tenant/asset
// threshold raises an internal `device_offline` event through the pipeline.
Schedule::job(new DetectOfflineAssetsJob)->everyFiveMinutes()->onOneServer();

// After-hours movement detector (Roadmap V2-C2): a unit moving while the
// tenant's schedule says "closed" raises an internal event (theft/misuse).
Schedule::job(new DetectAfterHoursMovementJob)->everyFiveMinutes()->onOneServer();
