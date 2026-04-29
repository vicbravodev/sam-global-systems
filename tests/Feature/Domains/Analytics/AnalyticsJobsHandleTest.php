<?php

namespace Tests\Feature\Domains\Analytics;

use App\Contracts\TenantConfig\TenantAnalyticsConfig;
use App\Domains\Analytics\Actions\BuildAnalyticsSnapshot;
use App\Domains\Analytics\Actions\CalculateKPIsForTenant;
use App\Domains\Analytics\Actions\GenerateReport;
use App\Domains\Analytics\Enums\ReportOutputFormat;
use App\Domains\Analytics\Enums\ReportRequestedByType;
use App\Domains\Analytics\Enums\SnapshotType;
use App\Domains\Analytics\Jobs\BuildAnalyticsSnapshotJob;
use App\Domains\Analytics\Jobs\GenerateReportJob;
use App\Domains\Analytics\Jobs\RebuildHistoricalMetricsJob;
use App\Domains\Analytics\Models\AnalyticsSnapshot;
use App\Domains\Analytics\Models\ReportDefinition;
use App\Domains\Analytics\Models\ReportExecution;
use App\Domains\Tenancy\Enums\SubscriptionStatus;
use App\Domains\Tenancy\Models\Plan;
use App\Domains\Tenancy\Models\Subscription;
use App\Models\Team;
use Carbon\CarbonInterface;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AnalyticsJobsHandleTest extends TestCase
{
    use RefreshDatabase;

    public function test_build_analytics_snapshot_job_only_processes_teams_with_active_subscription(): void
    {
        $teamWithSub = Team::factory()->create();
        $teamWithoutSub = Team::factory()->create();
        $plan = Plan::factory()->create();
        Subscription::factory()->create([
            'team_id' => $teamWithSub->id,
            'plan_id' => $plan->id,
            'status' => SubscriptionStatus::Active,
        ]);

        $calls = [];
        $action = new class($calls) extends BuildAnalyticsSnapshot
        {
            /** @param array<int, array{int, string}> $calls */
            public function __construct(public array &$calls)
            {
                // Skip parent constructor — we never invoke the real action.
            }

            public function execute(int $teamId, SnapshotType $type, CarbonInterface $start, CarbonInterface $end): AnalyticsSnapshot
            {
                $this->calls[] = [$teamId, $type->value];

                return new AnalyticsSnapshot;
            }
        };

        $config = new class implements TenantAnalyticsConfig
        {
            public function reportRetentionDays(int $teamId): int
            {
                return 90;
            }

            public function enabledSnapshotTypes(int $teamId): array
            {
                return [SnapshotType::TenantOverview->value];
            }
        };

        (new BuildAnalyticsSnapshotJob)->handle($action, $config);

        $teamIds = array_column($action->calls, 0);
        $this->assertContains($teamWithSub->id, $teamIds);
        $this->assertNotContains($teamWithoutSub->id, $teamIds);
    }

    public function test_build_analytics_snapshot_job_filters_by_specific_team_id(): void
    {
        $teamA = Team::factory()->create();
        $teamB = Team::factory()->create();
        $plan = Plan::factory()->create();
        foreach ([$teamA, $teamB] as $team) {
            Subscription::factory()->create([
                'team_id' => $team->id,
                'plan_id' => $plan->id,
                'status' => SubscriptionStatus::Active,
            ]);
        }

        $calls = [];
        $action = new class($calls) extends BuildAnalyticsSnapshot
        {
            /** @param array<int, array{int, string}> $calls */
            public function __construct(public array &$calls) {}

            public function execute(int $teamId, SnapshotType $type, CarbonInterface $start, CarbonInterface $end): AnalyticsSnapshot
            {
                $this->calls[] = [$teamId, $type->value];

                return new AnalyticsSnapshot;
            }
        };

        $config = new class implements TenantAnalyticsConfig
        {
            public function reportRetentionDays(int $teamId): int
            {
                return 90;
            }

            public function enabledSnapshotTypes(int $teamId): array
            {
                return [SnapshotType::TenantOverview->value];
            }
        };

        (new BuildAnalyticsSnapshotJob(teamId: $teamA->id))->handle($action, $config);

        $this->assertSame([[$teamA->id, SnapshotType::TenantOverview->value]], $action->calls);
    }

    public function test_build_analytics_snapshot_job_skips_disabled_snapshot_types(): void
    {
        $team = Team::factory()->create();
        $plan = Plan::factory()->create();
        Subscription::factory()->create([
            'team_id' => $team->id,
            'plan_id' => $plan->id,
            'status' => SubscriptionStatus::Active,
        ]);

        $calls = [];
        $action = new class($calls) extends BuildAnalyticsSnapshot
        {
            /** @param array<int, array{int, string}> $calls */
            public function __construct(public array &$calls) {}

            public function execute(int $teamId, SnapshotType $type, CarbonInterface $start, CarbonInterface $end): AnalyticsSnapshot
            {
                $this->calls[] = [$teamId, $type->value];

                return new AnalyticsSnapshot;
            }
        };

        $config = new class implements TenantAnalyticsConfig
        {
            public function reportRetentionDays(int $teamId): int
            {
                return 90;
            }

            public function enabledSnapshotTypes(int $teamId): array
            {
                return [];
            }
        };

        (new BuildAnalyticsSnapshotJob)->handle($action, $config);

        $this->assertSame([], $action->calls);
    }

    public function test_build_analytics_snapshot_job_uses_analytics_queue(): void
    {
        $job = new BuildAnalyticsSnapshotJob;

        $this->assertSame('analytics', $job->queue);
    }

    public function test_rebuild_historical_metrics_job_iterates_each_day_in_range(): void
    {
        $team = Team::factory()->create();
        $calls = [];
        $action = new class($calls) extends CalculateKPIsForTenant
        {
            /** @param array<int, array{int, string}> $calls */
            public function __construct(public array &$calls) {}

            public function execute(int $teamId, CarbonInterface $periodStart, CarbonInterface $periodEnd): int
            {
                $this->calls[] = [$teamId, $periodStart->toDateString()];

                return 0;
            }
        };

        (new RebuildHistoricalMetricsJob(
            teamId: $team->id,
            fromDate: '2026-01-01',
            toDate: '2026-01-03',
        ))->handle($action);

        $this->assertSame([
            [$team->id, '2026-01-01'],
            [$team->id, '2026-01-02'],
            [$team->id, '2026-01-03'],
        ], $action->calls);
    }

    public function test_rebuild_historical_metrics_job_handles_single_day_range(): void
    {
        $team = Team::factory()->create();
        $calls = [];
        $action = new class($calls) extends CalculateKPIsForTenant
        {
            /** @param array<int, array{int, string}> $calls */
            public function __construct(public array &$calls) {}

            public function execute(int $teamId, CarbonInterface $periodStart, CarbonInterface $periodEnd): int
            {
                $this->calls[] = [$teamId, $periodStart->toDateString()];

                return 0;
            }
        };

        (new RebuildHistoricalMetricsJob(
            teamId: $team->id,
            fromDate: '2026-01-15',
            toDate: '2026-01-15',
        ))->handle($action);

        $this->assertSame([[$team->id, '2026-01-15']], $action->calls);
    }

    public function test_rebuild_historical_metrics_job_uses_analytics_queue(): void
    {
        $job = new RebuildHistoricalMetricsJob(teamId: 1, fromDate: '2026-01-01', toDate: '2026-01-01');

        $this->assertSame('analytics', $job->queue);
    }

    public function test_generate_report_job_resolves_definition_and_invokes_action(): void
    {
        $team = Team::factory()->create();
        $definition = ReportDefinition::factory()->create(['team_id' => $team->id]);

        $calls = [];
        $this->app->bind(GenerateReport::class, function () use (&$calls) {
            return new class($calls) extends GenerateReport
            {
                /** @param array<int, array<string, mixed>> $calls */
                public function __construct(public array &$calls) {}

                public function execute(
                    ReportDefinition $definition,
                    int $teamId,
                    ReportOutputFormat $format,
                    ReportRequestedByType $requestedBy,
                    ?int $requestedById = null,
                    ?array $filters = null,
                ): ReportExecution {
                    $this->calls[] = [
                        'definition_id' => $definition->id,
                        'team_id' => $teamId,
                        'format' => $format->value,
                        'requested_by' => $requestedBy->value,
                        'requested_by_id' => $requestedById,
                        'filters' => $filters,
                    ];

                    return new ReportExecution;
                }
            };
        });

        (new GenerateReportJob(
            reportDefinitionId: $definition->id,
            teamId: $team->id,
            outputFormat: ReportOutputFormat::Json->value,
            requestedByType: ReportRequestedByType::User->value,
            requestedById: 42,
            filters: ['from' => '2026-01-01'],
        ))->handle(app(GenerateReport::class));

        $this->assertCount(1, $calls);
        $this->assertSame($definition->id, $calls[0]['definition_id']);
        $this->assertSame($team->id, $calls[0]['team_id']);
        $this->assertSame('json', $calls[0]['format']);
        $this->assertSame('user', $calls[0]['requested_by']);
        $this->assertSame(42, $calls[0]['requested_by_id']);
        $this->assertSame(['from' => '2026-01-01'], $calls[0]['filters']);
    }

    public function test_generate_report_job_uses_analytics_queue(): void
    {
        $job = new GenerateReportJob(
            reportDefinitionId: 1,
            teamId: 1,
            outputFormat: ReportOutputFormat::Json->value,
            requestedByType: ReportRequestedByType::User->value,
        );

        $this->assertSame('analytics', $job->queue);
    }
}
