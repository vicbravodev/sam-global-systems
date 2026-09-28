<?php

namespace Tests\Feature\Domains\Analytics;

use App\Domains\Analytics\Enums\ReportExecutionStatus;
use App\Domains\Analytics\Enums\ReportRequestedByType;
use App\Domains\Analytics\Jobs\GenerateReportJob;
use App\Domains\Analytics\Models\KpiRecord;
use App\Domains\Analytics\Models\MetricDefinition;
use App\Domains\Analytics\Models\ReportDefinition;
use App\Domains\Analytics\Models\ReportExecution;
use App\Domains\Assets\Models\Asset;
use App\Models\Team;
use App\Models\User;
use Database\Seeders\AccessSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\Concerns\AssertsTenantIsolation;
use Tests\TestCase;

/**
 * Roadmap F13: analytics page (KPIs + report generation/download).
 */
class AnalyticsPageTest extends TestCase
{
    use AssertsTenantIsolation;
    use RefreshDatabase;

    private User $user;

    private Team $team;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(AccessSeeder::class);

        $this->user = User::factory()->create();
        $this->team = $this->user->currentTeam;
    }

    public function test_page_renders_kpis_reports_and_executions(): void
    {
        KpiRecord::factory()->create([
            'team_id' => $this->team->id,
            'kpi_code' => 'incidents_open',
            'value' => 7,
        ]);

        $report = ReportDefinition::factory()->create([
            'team_id' => $this->team->id,
            'is_active' => true,
        ]);

        ReportExecution::factory()->create([
            'team_id' => $this->team->id,
            'report_definition_id' => $report->id,
            'status' => ReportExecutionStatus::Completed,
            'file_path' => 'reports/r1.pdf',
        ]);

        $response = $this->actingAs($this->user)->get(
            route('analytics.show', ['current_team' => $this->team->slug]),
        );

        $response->assertOk();
        $response->assertInertia(
            fn (Assert $page) => $page
                ->component('analytics/index')
                ->has('metrics', 1)
                ->where('metrics.0.code', 'incidents_open')
                ->where('period', 30)
                ->has('reports', 1)
                ->has('executions', 1)
                ->where('executions.0.downloadable', true)
                ->has('formats')
                ->where('canGenerate', true),
        );
    }

    public function test_page_hides_other_tenant_analytics(): void
    {
        $otherTeam = User::factory()->create()->currentTeam;
        KpiRecord::factory()->create(['team_id' => $otherTeam->id]);
        ReportExecution::factory()->create([
            'team_id' => $otherTeam->id,
            'report_definition_id' => ReportDefinition::factory()->create(['team_id' => $otherTeam->id])->id,
        ]);

        $response = $this->actingAs($this->user)->get(
            route('analytics.show', ['current_team' => $this->team->slug]),
        );

        $response->assertInertia(
            fn (Assert $page) => $page->has('metrics', 0)->has('executions', 0),
        );
    }

    public function test_metrics_are_named_grouped_and_aggregated_over_the_period(): void
    {
        MetricDefinition::factory()->create([
            'code' => 'incidents_total',
            'name' => 'Incidentes abiertos en el periodo',
            'unit' => 'count',
        ]);

        foreach ([1 => 4.0, 2 => 6.0, 20 => 100.0] as $daysAgo => $value) {
            $this->dailyKpi('incidents_total', $daysAgo, $value, 'count');
        }

        foreach ([1 => 0.9, 2 => 0.7] as $daysAgo => $value) {
            $this->dailyKpi('ai_accuracy_rate', $daysAgo, $value, 'ratio');
        }

        $response = $this->actingAs($this->user)->get(
            route('analytics.show', ['current_team' => $this->team->slug, 'period' => 7]),
        );

        $response->assertInertia(fn (Assert $page) => $page
            ->where('period', 7)
            ->where('periods', [7, 30, 90])
            ->has('metrics', 2)
            // Incidentes antes que IA; el de hace 20 días queda fuera de 7 d.
            ->where('metrics.0.code', 'incidents_total')
            ->where('metrics.0.name', 'Incidentes abiertos en el periodo')
            ->where('metrics.0.group', 'incidents')
            ->where('metrics.0.aggregation', 'sum')
            ->where('metrics.0.value', 10)
            ->has('metrics.0.series', 2)
            ->where('metrics.1.code', 'ai_accuracy_rate')
            ->where('metrics.1.aggregation', 'avg')
            ->where('metrics.1.value', 0.8));
    }

    public function test_unknown_period_falls_back_to_thirty_days(): void
    {
        $this->actingAs($this->user)
            ->get(route('analytics.show', ['current_team' => $this->team->slug, 'period' => 365]))
            ->assertInertia(fn (Assert $page) => $page->where('period', 30));
    }

    public function test_metrics_do_not_leak_across_tenants(): void
    {
        $otherTeam = User::factory()->create()->currentTeam;
        KpiRecord::factory()->create([
            'team_id' => $otherTeam->id,
            'kpi_code' => 'incidents_total',
            'period_start' => now()->subDay()->startOfDay(),
        ]);

        $response = $this->assertNoTenantLeak($this->team, fn () => $this->actingAs($this->user)->get(
            route('analytics.show', ['current_team' => $this->team->slug]),
        ));

        $response->assertInertia(fn (Assert $page) => $page->has('metrics', 0));
    }

    public function test_metrics_carry_the_previous_period_for_comparison(): void
    {
        // 7-day window = today and the 6 days before; previous = days 7..13.
        foreach ([1 => 4.0, 3 => 6.0] as $daysAgo => $value) {
            $this->dailyKpi('incidents_total', $daysAgo, $value, 'count');
        }

        foreach ([8 => 5.0, 13 => 15.0, 14 => 999.0] as $daysAgo => $value) {
            $this->dailyKpi('incidents_total', $daysAgo, $value, 'count');
        }

        // Only the previous window has this one: no current value, no row.
        $this->dailyKpi('copilot_queries', 9, 3.0, 'count');

        $response = $this->actingAs($this->user)->get(
            route('analytics.show', ['current_team' => $this->team->slug, 'period' => 7]),
        );

        $response->assertInertia(fn (Assert $page) => $page
            ->has('metrics', 1)
            ->where('metrics.0.code', 'incidents_total')
            ->where('metrics.0.value', 10)
            // Day 14 falls outside both windows.
            ->where('metrics.0.previous', 20)
            ->has('metrics.0.series', 2)
            ->where('range.from', now()->subDays(6)->toDateString())
            ->where('range.previousFrom', now()->subDays(13)->toDateString())
            ->where('range.previousTo', now()->subDays(7)->toDateString()));
    }

    public function test_rates_are_weighted_by_their_daily_volume_and_skip_empty_days(): void
    {
        // Day 1: 3 incidents resolved in 10 min on average; day 2: 1 in 50 min;
        // day 3: nothing resolved, so its 0 min is no measurement at all.
        foreach ([1 => [3.0, 10.0], 2 => [1.0, 50.0], 3 => [0.0, 0.0]] as $daysAgo => [$resolved, $minutes]) {
            $this->dailyKpi('incidents_resolved', $daysAgo, $resolved, 'count');
            $this->dailyKpi('incidents_mttr_minutes', $daysAgo, $minutes, 'minutes');
        }

        $response = $this->actingAs($this->user)->get(
            route('analytics.show', ['current_team' => $this->team->slug, 'period' => 7]),
        );

        $response->assertInertia(fn (Assert $page) => $page
            ->where('metrics.0.code', 'incidents_mttr_minutes')
            // (3×10 + 1×50) / 4 = 20, not the plain daily mean (20 / 3 days).
            ->where('metrics.0.value', 20)
            ->has('metrics.0.series', 2)
            ->where('metrics.0.previous', null));
    }

    public function test_fleet_counts_only_this_teams_monitored_assets(): void
    {
        Asset::factory()->count(2)->create(['team_id' => $this->team->id]);
        Asset::factory()->pendingMonitoring()->create(['team_id' => $this->team->id]);

        $otherTeam = User::factory()->create()->currentTeam;
        Asset::factory()->count(4)->create(['team_id' => $otherTeam->id]);

        $response = $this->assertNoTenantLeak($this->team, fn () => $this->actingAs($this->user)->get(
            route('analytics.show', ['current_team' => $this->team->slug]),
        ));

        $response->assertInertia(fn (Assert $page) => $page
            ->where('fleet.monitored', 2)
            ->where('fleet.total', 3));
    }

    public function test_executions_say_which_report_they_belong_to_and_who_asked(): void
    {
        $report = ReportDefinition::factory()->create([
            'team_id' => $this->team->id,
            'is_active' => true,
            'schedule_config_json' => ['frequency' => 'weekly'],
        ]);

        ReportExecution::factory()->create([
            'team_id' => $this->team->id,
            'report_definition_id' => $report->id,
            'requested_by_type' => ReportRequestedByType::Scheduler,
            'status' => ReportExecutionStatus::Failed,
        ]);

        $response = $this->actingAs($this->user)->get(
            route('analytics.show', ['current_team' => $this->team->slug]),
        );

        $response->assertInertia(fn (Assert $page) => $page
            ->where('reports.0.frequency', 'weekly')
            ->where('executions.0.reportId', $report->id)
            ->where('executions.0.automatic', true)
            ->where('executions.0.status', 'failed')
            ->where('executions.0.downloadable', false));
    }

    private function dailyKpi(string $code, int $daysAgo, float $value, string $unit): void
    {
        $start = now()->subDays($daysAgo)->startOfDay();

        KpiRecord::factory()->create([
            'team_id' => $this->team->id,
            'kpi_code' => $code,
            'period_start' => $start,
            'period_end' => $start->copy()->endOfDay(),
            'value' => $value,
            'unit' => $unit,
        ]);
    }

    public function test_report_generation_dispatches_job_via_web_route(): void
    {
        Bus::fake([GenerateReportJob::class]);

        $report = ReportDefinition::factory()->create([
            'team_id' => $this->team->id,
            'is_active' => true,
        ]);

        $response = $this->actingAs($this->user)->postJson(
            route('analytics.reports.generate', [
                'current_team' => $this->team->slug,
                'report' => $report->id,
            ]),
            ['format' => 'pdf'],
        );

        $response->assertStatus(202);

        Bus::assertDispatched(GenerateReportJob::class);
    }
}
