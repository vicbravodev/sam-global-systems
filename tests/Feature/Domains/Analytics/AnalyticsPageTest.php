<?php

namespace Tests\Feature\Domains\Analytics;

use App\Domains\Analytics\Enums\ReportExecutionStatus;
use App\Domains\Analytics\Jobs\GenerateReportJob;
use App\Domains\Analytics\Models\KpiRecord;
use App\Domains\Analytics\Models\MetricDefinition;
use App\Domains\Analytics\Models\ReportDefinition;
use App\Domains\Analytics\Models\ReportExecution;
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
