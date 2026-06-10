<?php

namespace Tests\Feature\Domains\Analytics;

use App\Domains\Analytics\Enums\ReportExecutionStatus;
use App\Domains\Analytics\Jobs\GenerateReportJob;
use App\Domains\Analytics\Models\KpiRecord;
use App\Domains\Analytics\Models\ReportDefinition;
use App\Domains\Analytics\Models\ReportExecution;
use App\Models\Team;
use App\Models\User;
use Database\Seeders\AccessSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

/**
 * Roadmap F13: analytics page (KPIs + report generation/download).
 */
class AnalyticsPageTest extends TestCase
{
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
                ->has('kpis', 1)
                ->where('kpis.0.code', 'incidents_open')
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
            fn (Assert $page) => $page->has('kpis', 0)->has('executions', 0),
        );
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
