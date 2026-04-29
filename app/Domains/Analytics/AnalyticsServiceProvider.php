<?php

namespace App\Domains\Analytics;

use App\Contracts\Audit\AuditLogQuery;
use App\Contracts\Decisions\DecisionMetricsQuery;
use App\Contracts\Incidents\IncidentMetricsQuery;
use App\Contracts\NullImplementations\NullAuditLogQuery;
use App\Contracts\NullImplementations\NullDecisionMetricsQuery;
use App\Contracts\NullImplementations\NullIncidentMetricsQuery;
use App\Contracts\NullImplementations\NullTenantAnalyticsConfig;
use App\Contracts\TenantConfig\TenantAnalyticsConfig;
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
        // SPEC-11-DEFERRED: real Incidents-domain query lands with spec 11.
        $this->app->singletonIf(IncidentMetricsQuery::class, NullIncidentMetricsQuery::class);

        // SPEC-10-DEFERRED: real Decisions-domain query lands with spec 10.
        $this->app->singletonIf(DecisionMetricsQuery::class, NullDecisionMetricsQuery::class);

        // SPEC-14-DEFERRED: real Audit-domain query lands with spec 14.
        $this->app->singletonIf(AuditLogQuery::class, NullAuditLogQuery::class);

        // SPEC-16-DEFERRED: tenant-level analytics config lands with spec 16.
        $this->app->singletonIf(TenantAnalyticsConfig::class, NullTenantAnalyticsConfig::class);
    }

    public function boot(): void
    {
        Gate::policy(KpiRecord::class, KpiRecordPolicy::class);
        Gate::policy(AnalyticsSnapshot::class, AnalyticsSnapshotPolicy::class);
        Gate::policy(ReportDefinition::class, ReportDefinitionPolicy::class);
        Gate::policy(ReportExecution::class, ReportExecutionPolicy::class);
    }
}
