# Analytics

## 1. Purpose

Generate operational KPIs, analytics snapshots, and reports to measure system effectiveness, AI performance, and operational trends per tenant. This domain transforms raw audit trails, usage data, and operational records into actionable metrics — providing dashboards, scheduled reports, and exportable data for executive review, SLA compliance, and continuous AI improvement.

## 2. Responsibilities

- Define and maintain a catalog of metric definitions with formulas and aggregation rules
- Calculate KPIs on configurable schedules (hourly, daily, weekly, monthly)
- Build analytics snapshots for common views (tenant overview, AI performance, asset risk)
- Generate reports in multiple output formats (dashboard, PDF, CSV, XLSX, JSON)
- Store generated report files in RustFS for retrieval
- Evaluate AI effectiveness as a first-class metric category (accuracy, false positive rate, confidence calibration)
- Support multi-dimensional KPIs (by tenant, asset, driver, zone, event type)
- Broadcast report readiness to connected clients

## 3. Inputs / Outputs

### Inputs

| Source | Data | Channel |
|--------|------|---------|
| Audit module | Domain event logs, change histories, system traces | Database queries |
| Tenancy module | Usage events, usage counters, subscription data | Database queries |
| Incidents module | Incident records, resolution times, escalation counts | Database queries |
| AI module | AI evaluation results, confidence scores, accuracy metrics | Database queries |
| Decisions module | Decision outcomes, human review results | Database queries |
| Assets module | Asset state, risk profiles | Database queries |
| Scheduler | Periodic KPI calculation and snapshot triggers | `routes/console.php` |
| Admin / API | Report generation requests, custom filters | Inertia pages / API |

### Outputs

| Target | Data | Channel |
|--------|------|---------|
| Frontend | Dashboard data, KPI charts, report downloads | Inertia pages / API |
| RustFS | Generated report files (PDF, CSV, XLSX) | `ObjectStorage` contract via `rustfs` disk |
| Frontend (Soketi) | Report ready notification | `ReportReadyBroadcast` on `private-accounts.{teamId}` |
| Tenancy module | `generated_reports` usage events | `RecordUsageEvent` |

## 4. Entities

### 4.1 Metric Definitions (`metric_definitions`)

Catalog of all metrics the system can calculate. Not tenant-scoped — these are system-wide definitions.

```php
Schema::create('metric_definitions', function (Blueprint $table) {
    $table->id();
    $table->string('code')->unique();
    $table->string('name');
    $table->text('description')->nullable();
    $table->text('formula_description')->nullable();
    $table->string('unit');
    $table->string('aggregation_type'); // enum
    $table->jsonb('source_modules_json')->nullable();
    $table->boolean('is_active')->default(true);
    $table->timestamps();
});
```

**Enum `MetricAggregationType`**: `Sum`, `Avg`, `Max`, `Min`, `Count`, `Rate`

### 4.2 KPI Records (`kpi_records`)

Calculated KPI values per tenant, period, and optional dimension.

```php
Schema::create('kpi_records', function (Blueprint $table) {
    $table->id();
    $table->foreignId('team_id')->constrained()->cascadeOnDelete();
    $table->string('kpi_code');
    $table->string('period_type'); // enum
    $table->timestamp('period_start');
    $table->timestamp('period_end');
    $table->string('dimension_type')->nullable(); // enum
    $table->string('dimension_reference')->nullable();
    $table->decimal('value', 12, 4);
    $table->string('unit')->nullable();
    $table->jsonb('metadata_json')->nullable();
    $table->timestamp('calculated_at');
    $table->timestamps();

    $table->index(['team_id', 'kpi_code', 'period_start']);
    $table->index(['team_id', 'dimension_type', 'dimension_reference']);
});
```

**Enum `PeriodType`**: `Hourly`, `Daily`, `Weekly`, `Monthly`, `Custom`

**Enum `DimensionType`**: `Tenant`, `Asset`, `Driver`, `Operator`, `Zone`, `Geofence`, `EventType`, `IncidentType`

### 4.3 Analytics Snapshots (`analytics_snapshots`)

Pre-computed JSON snapshots for dashboard views, avoiding expensive real-time aggregation.

```php
Schema::create('analytics_snapshots', function (Blueprint $table) {
    $table->id();
    $table->foreignId('team_id')->constrained()->cascadeOnDelete();
    $table->string('snapshot_type'); // enum
    $table->string('entity_type')->nullable();
    $table->unsignedBigInteger('entity_id')->nullable();
    $table->date('period_start');
    $table->date('period_end');
    $table->jsonb('snapshot_json');
    $table->timestamps();

    $table->index(['team_id', 'snapshot_type', 'period_start']);
});
```

**Enum `SnapshotType`**: `TenantOverview`, `OperationalSummary`, `AiPerformance`, `AssetRiskProfile`, `OperatorPerformance`, `ZoneAnalysis`

### 4.4 Report Definitions (`report_definitions`)

Configurable report templates. System-wide reports have `team_id = null`; tenants can create custom reports.

```php
Schema::create('report_definitions', function (Blueprint $table) {
    $table->id();
    $table->foreignId('team_id')->nullable()->constrained()->cascadeOnDelete();
    $table->string('code');
    $table->string('name');
    $table->text('description')->nullable();
    $table->string('report_type'); // enum
    $table->jsonb('data_sources_json')->nullable();
    $table->jsonb('filters_schema_json')->nullable();
    $table->jsonb('metrics_json')->nullable();
    $table->jsonb('visualization_config_json')->nullable();
    $table->jsonb('schedule_config_json')->nullable();
    $table->boolean('is_active')->default(true);
    $table->timestamps();
});
```

**Enum `ReportType`**: `Operational`, `Executive`, `Sla`, `AiPerformance`, `IncidentAnalysis`, `AssetRisk`, `Custom`

### 4.5 Report Executions (`report_executions`)

Each run of a report definition, tracking status, output, and requesting actor.

```php
Schema::create('report_executions', function (Blueprint $table) {
    $table->id();
    $table->foreignId('report_definition_id')->constrained()->cascadeOnDelete();
    $table->foreignId('team_id')->constrained()->cascadeOnDelete();
    $table->string('requested_by_type'); // enum
    $table->unsignedBigInteger('requested_by_id')->nullable();
    $table->jsonb('filters_json')->nullable();
    $table->string('status'); // enum
    $table->string('output_format'); // enum
    $table->string('file_path')->nullable();
    $table->jsonb('result_snapshot_json')->nullable();
    $table->text('error_message')->nullable();
    $table->timestamp('started_at')->nullable();
    $table->timestamp('finished_at')->nullable();
    $table->timestamps();
});
```

**Enum `ReportRequestedByType`**: `User`, `System`, `Scheduler`

**Enum `ReportExecutionStatus`**: `Pending`, `Running`, `Completed`, `Failed`, `Expired`

**Enum `ReportOutputFormat`**: `Dashboard`, `Pdf`, `Csv`, `Xlsx`, `Json`

## 5. Services

| Service | Responsibility |
|---------|---------------|
| `CalculateKPI` | Given a metric definition, team, period, and optional dimension, execute the appropriate query and store the result in `kpi_records`. Uses the `aggregation_type` and `source_modules_json` to determine the data source and aggregation logic. |
| `BuildAnalyticsSnapshot` | Assemble a pre-computed JSON snapshot for a given `snapshot_type`, team, and period. Pulls from `kpi_records` and direct queries, stores in `analytics_snapshots`. |
| `GenerateReport` | Execute a report definition: load data sources, apply filters, compute metrics, render output, store result. Creates a `report_executions` record. |
| `ExportReport` | Render a report execution into the requested output format (PDF, CSV, XLSX, JSON). Stores the file in RustFS and records the `file_path`. |
| `EvaluateAIEffectiveness` | Calculate AI-specific KPIs: accuracy rate (correct decisions / total decisions), false positive rate, average confidence, human override rate, response time. Stores results as KPI records with `kpi_code` prefixed `ai_`. |
| `GetOperationalMetrics` | Retrieve current KPI values for a tenant dashboard. Returns cached snapshots when available, falls back to real-time calculation. |

## 6. Jobs

### `CalculateDailyKPIsJob`

- **Queue**: `analytics`
- **Schedule**: Daily at 03:00 UTC via `routes/console.php`
- **Logic**:
  1. For each active team with an active subscription
  2. For each active `metric_definitions` record
  3. Call `CalculateKPI` for the previous day's period
  4. Dispatch `KPIsCalculated` domain event per team

### `BuildAnalyticsSnapshotJob`

- **Queue**: `analytics`
- **Schedule**: Daily at 04:00 UTC (after KPI calculation)
- **Logic**:
  1. For each active team
  2. Build snapshots for each `SnapshotType`
  3. Store in `analytics_snapshots`

### `GenerateScheduledReportJob`

- **Queue**: `analytics`
- **Schedule**: Runs on schedule defined in `report_definitions.schedule_config_json` (e.g., weekly Monday 08:00)
- **Logic**:
  1. Find all `report_definitions` with `schedule_config_json` matching the current schedule window
  2. For each, create a `report_executions` record with `requested_by_type = scheduler`
  3. Call `GenerateReport` and `ExportReport`
  4. Broadcast `ReportReadyBroadcast` to the tenant
  5. Dispatch `ReportGenerated` domain event

### `RebuildHistoricalMetricsJob`

- **Queue**: `analytics`
- **Trigger**: Manual dispatch (admin) for backfilling KPIs after metric definition changes
- **Logic**:
  1. Accept a date range and optional metric codes
  2. Recalculate `kpi_records` for the specified range
  3. Rebuild affected `analytics_snapshots`

## 7. Domain Events

| Event | Trigger | Payload |
|-------|---------|---------|
| `KPIsCalculated` | `CalculateDailyKPIsJob` completes for a team | `teamId`, `periodStart`, `periodEnd`, `metricsCount` |
| `ReportGenerated` | `GenerateScheduledReportJob` or on-demand report completes | `teamId`, `reportExecutionId`, `reportType`, `outputFormat` |

## 8. Broadcasting Events

### `ReportReadyBroadcast`

Broadcast on `private-accounts.{teamId}` when a report generation completes.

```php
namespace App\Domains\Analytics\Events;

use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;
use Illuminate\Queue\SerializesModels;

class ReportReadyBroadcast implements ShouldBroadcast
{
    use SerializesModels;

    public function __construct(
        public readonly int $teamId,
        public readonly int $reportExecutionId,
        public readonly string $reportName,
        public readonly string $outputFormat,
    ) {}

    public function broadcastOn(): array
    {
        return [
            new PrivateChannel("accounts.{$this->teamId}"),
        ];
    }

    public function broadcastAs(): string
    {
        return 'report.ready';
    }

    public function broadcastWith(): array
    {
        return [
            'report_execution_id' => $this->reportExecutionId,
            'report_name' => $this->reportName,
            'output_format' => $this->outputFormat,
        ];
    }
}
```

## 9. APIs / Endpoints

All tenant-scoped endpoints are prefixed with `/{current_team}` and protected by `EnsureTeamMembership` middleware.

| Method | URI | Controller Method | Description |
|--------|-----|-------------------|-------------|
| `GET` | `/{current_team}/analytics/dashboard` | `AnalyticsDashboardController@index` | Tenant analytics dashboard with KPIs and snapshots |
| `GET` | `/{current_team}/analytics/kpis` | `KpiController@index` | List KPI values (filterable by code, period, dimension) |
| `GET` | `/{current_team}/analytics/snapshots/{type}` | `AnalyticsSnapshotController@show` | Retrieve latest snapshot for a given type |
| `GET` | `/{current_team}/analytics/ai-performance` | `AiPerformanceController@index` | AI effectiveness dashboard (accuracy, FP rate, confidence) |
| `GET` | `/{current_team}/analytics/reports` | `ReportController@index` | List report definitions available to the tenant |
| `POST` | `/{current_team}/analytics/reports/{report}/generate` | `ReportController@generate` | Trigger on-demand report generation |
| `GET` | `/{current_team}/analytics/reports/executions` | `ReportExecutionController@index` | List report executions (filterable by status) |
| `GET` | `/{current_team}/analytics/reports/executions/{execution}` | `ReportExecutionController@show` | View execution detail with result snapshot |
| `GET` | `/{current_team}/analytics/reports/executions/{execution}/download` | `ReportExecutionController@download` | Download the generated report file from RustFS |

## 10. Business Rules

1. **Reproducible KPIs** — The same metric code, period, and dimension filters must always produce the same result. KPIs are deterministic functions of the underlying data. Recalculating a historical KPI must yield an identical value.
2. **No transactional impact** — Analytics queries must NOT impact transactional performance. All dashboard data comes from pre-computed `analytics_snapshots` and `kpi_records`, never from direct queries against operational tables.
3. **Formal metric definitions** — Every displayed metric has a corresponding `metric_definitions` record with a human-readable `formula_description`. No ad-hoc calculations in the frontend.
4. **AI effectiveness is first-class** — AI accuracy, false positive rate, human override rate, and confidence calibration are tracked as standard KPIs with dedicated snapshot types and dashboard views.
5. **Reports stored in RustFS** — Generated file outputs (PDF, CSV, XLSX) are stored in RustFS under `reports/{teamId}/{executionId}.{format}`. File paths are recorded in `report_executions.file_path`.
6. **Report expiration** — Generated reports expire after a configurable retention period (default 90 days). Expired report files are deleted from RustFS and the execution status is set to `expired`.

## 11. Integration with Other Modules

| Module | Interaction |
|--------|------------|
| **Audit** | Primary data source — queries `domain_event_logs`, `change_histories`, and `system_traces` for operational metrics and trace durations. |
| **Tenancy** | Queries `usage_events` and `usage_daily_aggregates` for usage-based KPIs. Emits `generated_reports` usage events. Uses `BelongsToTenant` trait. |
| **Incidents** | Queries incident data for mean time to resolution (MTTR), escalation rate, and incident volume KPIs. |
| **AI** | Queries AI evaluation results for accuracy, confidence, and false positive rate metrics. `EvaluateAIEffectiveness` is a dedicated service. |
| **Decisions** | Queries decision outcomes for human review rate and override metrics. |
| **Assets** | Queries asset state for risk profile snapshots and asset utilization metrics. |
| **Access** | Policies check team membership and permissions for viewing analytics and generating reports. |

## 12. Usage Metering

| Meter Code | When Recorded |
|------------|---------------|
| `generated_reports` | 1 event per `report_executions` record completed (via `RecordUsageEvent`) |

```php
app(RecordUsageEvent::class)->execute(
    teamId: $execution->team_id,
    meterCode: 'generated_reports',
    quantity: 1,
    eventKey: "report_exec_{$execution->id}",
);
```

## 13. Technical Considerations

### Performance

- KPI calculations run during off-peak hours (03:00–04:00 UTC) to minimize database contention.
- Analytics snapshots are the primary read source for dashboards. Frontend pages should never trigger real-time aggregation queries.
- `kpi_records` uses a composite index `(team_id, kpi_code, period_start)` for efficient time-series retrieval.
- For tenants with high data volume, consider materialized views for frequently accessed metrics.

### RustFS File Storage

```php
Storage::disk('rustfs')->put(
    "reports/{$teamId}/{$executionId}.pdf",
    $pdfContent,
);
```

Download URLs are generated via temporary signed URLs:

```php
Storage::disk('rustfs')->temporaryUrl(
    $execution->file_path,
    now()->addMinutes(30),
);
```

### Snapshot Schema

Each `snapshot_type` has a defined JSON schema. Example for `TenantOverview`:

```json
{
    "total_incidents": 142,
    "resolved_incidents": 128,
    "mean_resolution_time_minutes": 45.3,
    "ai_accuracy_rate": 0.91,
    "active_assets": 350,
    "active_integrations": 4,
    "usage_summary": {
        "api_requests": 15420,
        "ai_calls": 892,
        "outbound_notifications": 1203
    }
}
```

### Scheduling

Reports use `schedule_config_json` with a cron-like format:

```json
{
    "frequency": "weekly",
    "day_of_week": "monday",
    "time": "08:00",
    "timezone": "America/New_York"
}
```

The scheduler evaluates active report definitions every 15 minutes and dispatches `GenerateScheduledReportJob` for matching schedules.

### Caching

- Current-period KPIs are cached in Valkey with 15-minute TTL for dashboard responsiveness.
- Analytics snapshots are cached by `(team_id, snapshot_type, period_start)` with 1-hour TTL.

## 14. Test Scenarios

| Test Name | Description |
|-----------|-------------|
| `test_daily_kpis_calculate_correctly` | `CalculateDailyKPIsJob` produces `kpi_records` with accurate values matching manual calculation against the same data |
| `test_ai_effectiveness_metrics_computed` | `EvaluateAIEffectiveness` calculates accuracy rate, false positive rate, and confidence calibration as KPI records |
| `test_report_generates_and_stores_file` | `GenerateReport` creates a `report_executions` record, generates a PDF, and stores it in RustFS |
| `test_analytics_snapshot_builds_for_period` | `BuildAnalyticsSnapshot` produces a `TenantOverview` snapshot with correct incident counts and AI accuracy |
| `test_kpi_calculation_is_reproducible` | Calculating the same KPI for the same period twice yields identical `value` results |
| `test_report_download_returns_file_from_rustfs` | The download endpoint returns the correct file content from RustFS with appropriate headers |
| `test_scheduled_report_dispatches_on_matching_schedule` | A report definition with weekly Monday schedule triggers `GenerateScheduledReportJob` on Monday |
| `test_report_ready_broadcasts_on_completion` | Completing a report execution dispatches `ReportReadyBroadcast` on `private-accounts.{teamId}` |
| `test_expired_reports_cleaned_up` | Reports older than the retention period have their files deleted from RustFS and status set to `expired` |
| `test_usage_event_emitted_per_report_execution` | Each completed report execution emits a `generated_reports` usage event via `RecordUsageEvent` |
| `test_kpi_dimension_filtering` | KPIs calculated with `dimension_type = asset` produce per-asset records that can be filtered correctly |
| `test_analytics_data_is_tenant_isolated` | Querying KPIs and snapshots with a tenant scope returns only that tenant's analytics data |
