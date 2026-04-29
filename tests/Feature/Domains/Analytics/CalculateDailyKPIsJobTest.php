<?php

namespace Tests\Feature\Domains\Analytics;

use App\Contracts\Decisions\DecisionMetricsQuery;
use App\Contracts\Incidents\IncidentMetricsQuery;
use App\Domains\Analytics\Actions\CalculateKPIsForTenant;
use App\Domains\Analytics\Jobs\CalculateDailyKPIsJob;
use App\Domains\Analytics\Models\KpiRecord;
use App\Domains\Analytics\Models\MetricDefinition;
use App\Domains\Tenancy\Enums\SubscriptionStatus;
use App\Domains\Tenancy\Models\Plan;
use App\Domains\Tenancy\Models\Subscription;
use App\Models\Team;
use Carbon\CarbonInterface;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class CalculateDailyKPIsJobTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->bindZeroIncidentMetrics();
        $this->bindZeroDecisionMetrics();
    }

    public function test_job_only_calculates_for_teams_with_active_subscription(): void
    {
        $teamWithSub = Team::factory()->create();
        $teamWithoutSub = Team::factory()->create();
        $plan = Plan::factory()->create();
        Subscription::factory()->create([
            'team_id' => $teamWithSub->id,
            'plan_id' => $plan->id,
            'status' => SubscriptionStatus::Active,
        ]);

        MetricDefinition::factory()->create(['code' => 'incidents_total']);

        (new CalculateDailyKPIsJob)->handle(app(CalculateKPIsForTenant::class));

        $this->assertSame(
            1,
            KpiRecord::withoutGlobalScopes()
                ->where('team_id', $teamWithSub->id)
                ->where('kpi_code', 'incidents_total')
                ->count(),
        );
        $this->assertSame(
            0,
            KpiRecord::withoutGlobalScopes()
                ->where('team_id', $teamWithoutSub->id)
                ->count(),
        );
    }

    public function test_job_uses_analytics_queue(): void
    {
        $job = new CalculateDailyKPIsJob;

        $this->assertSame('analytics', $job->queue);
    }

    public function test_running_job_twice_does_not_duplicate_records(): void
    {
        $team = Team::factory()->create();
        $plan = Plan::factory()->create();
        Subscription::factory()->create([
            'team_id' => $team->id,
            'plan_id' => $plan->id,
            'status' => SubscriptionStatus::Active,
        ]);

        MetricDefinition::factory()->create(['code' => 'incidents_total']);

        (new CalculateDailyKPIsJob)->handle(app(CalculateKPIsForTenant::class));
        (new CalculateDailyKPIsJob)->handle(app(CalculateKPIsForTenant::class));

        $this->assertSame(
            1,
            KpiRecord::withoutGlobalScopes()
                ->where('team_id', $team->id)
                ->where('kpi_code', 'incidents_total')
                ->count(),
        );
    }

    private function bindZeroIncidentMetrics(): void
    {
        $this->app->bind(IncidentMetricsQuery::class, function () {
            return new class implements IncidentMetricsQuery
            {
                public function totalsForTenant(int $teamId, CarbonInterface $from, CarbonInterface $to): array
                {
                    return [
                        'total' => 0,
                        'resolved' => 0,
                        'open' => 0,
                        'mean_resolution_time_minutes' => 0.0,
                        'escalations' => 0,
                    ];
                }
            };
        });
    }

    private function bindZeroDecisionMetrics(): void
    {
        $this->app->bind(DecisionMetricsQuery::class, function () {
            return new class implements DecisionMetricsQuery
            {
                public function totalsForTenant(int $teamId, CarbonInterface $from, CarbonInterface $to): array
                {
                    return [
                        'total' => 0,
                        'human_reviewed' => 0,
                        'human_overrides' => 0,
                        'auto_resolved' => 0,
                    ];
                }
            };
        });
    }
}
