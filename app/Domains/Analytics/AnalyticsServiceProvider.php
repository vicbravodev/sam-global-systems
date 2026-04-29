<?php

namespace App\Domains\Analytics;

use App\Contracts\Audit\AuditLogQuery;
use App\Contracts\Decisions\DecisionMetricsQuery;
use App\Contracts\Incidents\IncidentMetricsQuery;
use App\Contracts\NullImplementations\NullAuditLogQuery;
use App\Contracts\NullImplementations\NullDecisionMetricsQuery;
use App\Contracts\NullImplementations\NullIncidentMetricsQuery;
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
    public function register(): void
    {
        // Metric queries are still backed by Null implementations: the Incidents,
        // Decisions, and Audit domains expose models and policies but have not
        // yet shipped DB-backed query objects for analytics aggregation.
        $this->app->singletonIf(IncidentMetricsQuery::class, NullIncidentMetricsQuery::class);
        $this->app->singletonIf(DecisionMetricsQuery::class, NullDecisionMetricsQuery::class);
        $this->app->singletonIf(AuditLogQuery::class, NullAuditLogQuery::class);
    }

    public function boot(): void
    {
        Gate::policy(KpiRecord::class, KpiRecordPolicy::class);
        Gate::policy(AnalyticsSnapshot::class, AnalyticsSnapshotPolicy::class);
        Gate::policy(ReportDefinition::class, ReportDefinitionPolicy::class);
        Gate::policy(ReportExecution::class, ReportExecutionPolicy::class);
    }
}
