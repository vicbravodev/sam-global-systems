<?php

namespace App\Domains\Analytics;

use App\Domains\Analytics\Models\AnalyticsSnapshot;
use App\Domains\Analytics\Models\KpiRecord;
use App\Domains\Analytics\Models\ReportDefinition;
use App\Domains\Analytics\Models\ReportExecution;
use App\Domains\Analytics\Policies\AnalyticsSnapshotPolicy;
use App\Domains\Analytics\Policies\KpiRecordPolicy;
use App\Domains\Analytics\Policies\ReportDefinitionPolicy;
use App\Domains\Analytics\Policies\ReportExecutionPolicy;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\ServiceProvider;

class AnalyticsServiceProvider extends ServiceProvider
{
    public function boot(): void
    {
        Gate::policy(KpiRecord::class, KpiRecordPolicy::class);
        Gate::policy(AnalyticsSnapshot::class, AnalyticsSnapshotPolicy::class);
        Gate::policy(ReportDefinition::class, ReportDefinitionPolicy::class);
        Gate::policy(ReportExecution::class, ReportExecutionPolicy::class);
    }
}
