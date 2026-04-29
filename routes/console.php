<?php

use App\Domains\Analytics\Jobs\BuildAnalyticsSnapshotJob;
use App\Domains\Analytics\Jobs\CalculateDailyKPIsJob;
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
