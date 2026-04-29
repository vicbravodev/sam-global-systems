<?php

namespace Tests\Feature\Domains\Analytics;

use App\Domains\Analytics\Enums\ReportExecutionStatus;
use App\Domains\Analytics\Enums\ReportOutputFormat;
use App\Domains\Analytics\Enums\SnapshotType;
use App\Domains\Analytics\Jobs\GenerateReportJob;
use App\Domains\Analytics\Models\AnalyticsSnapshot;
use App\Domains\Analytics\Models\KpiRecord;
use App\Domains\Analytics\Models\ReportDefinition;
use App\Domains\Analytics\Models\ReportExecution;
use App\Models\User;
use Database\Seeders\AccessSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class AnalyticsControllerTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(AccessSeeder::class);
    }

    public function test_dashboard_returns_overview_and_kpis_for_team_member(): void
    {
        $user = User::factory()->create();
        $team = $user->currentTeam;

        AnalyticsSnapshot::factory()->create([
            'team_id' => $team->id,
            'snapshot_type' => SnapshotType::TenantOverview,
        ]);
        KpiRecord::factory()->create(['team_id' => $team->id]);

        $this->actingAs($user);

        $response = $this->getJson("/api/{$team->slug}/analytics/dashboard");

        $response->assertOk();
        $this->assertNotNull($response->json('overview'));
        $this->assertNotEmpty($response->json('kpis'));
    }

    public function test_kpi_index_filters_by_kpi_code(): void
    {
        $user = User::factory()->create();
        $team = $user->currentTeam;

        KpiRecord::factory()->create(['team_id' => $team->id, 'kpi_code' => 'incidents_total']);
        KpiRecord::factory()->create(['team_id' => $team->id, 'kpi_code' => 'ai_total_evaluations']);

        $this->actingAs($user);

        $response = $this->getJson("/api/{$team->slug}/analytics/kpis?kpi_code=incidents_total");

        $response->assertOk();
        $this->assertCount(1, $response->json('data'));
        $this->assertSame('incidents_total', $response->json('data.0.kpi_code'));
    }

    public function test_snapshot_show_returns_latest_for_type(): void
    {
        $user = User::factory()->create();
        $team = $user->currentTeam;

        AnalyticsSnapshot::factory()->create([
            'team_id' => $team->id,
            'snapshot_type' => SnapshotType::AiPerformance,
        ]);

        $this->actingAs($user);

        $response = $this->getJson("/api/{$team->slug}/analytics/snapshots/ai_performance");

        $response->assertOk();
        $this->assertSame('ai_performance', $response->json('data.snapshot_type'));
    }

    public function test_report_generate_dispatches_job(): void
    {
        Bus::fake();

        $user = User::factory()->create();
        $team = $user->currentTeam;

        $definition = ReportDefinition::factory()->create(['team_id' => $team->id]);

        $this->actingAs($user);

        $response = $this->postJson(
            "/api/{$team->slug}/analytics/reports/{$definition->id}/generate",
            ['format' => 'json'],
        );

        $response->assertStatus(202);
        Bus::assertDispatched(GenerateReportJob::class, function (GenerateReportJob $job) use ($definition, $team) {
            return $job->reportDefinitionId === $definition->id
                && $job->teamId === $team->id
                && $job->outputFormat === 'json';
        });
    }

    public function test_execution_download_streams_file_from_rustfs(): void
    {
        Storage::fake('rustfs');

        $user = User::factory()->create();
        $team = $user->currentTeam;

        $definition = ReportDefinition::factory()->create(['team_id' => $team->id]);
        $execution = ReportExecution::factory()->completed()->create([
            'report_definition_id' => $definition->id,
            'team_id' => $team->id,
            'output_format' => ReportOutputFormat::Json,
            'status' => ReportExecutionStatus::Completed,
            'file_path' => "reports/{$team->id}/sample.json",
        ]);

        Storage::disk('rustfs')->put($execution->file_path, '{"hello":"world"}');

        $this->actingAs($user);

        $response = $this->get("/api/{$team->slug}/analytics/reports/executions/{$execution->id}/download");

        $response->assertOk();
        $this->assertSame('{"hello":"world"}', $response->getContent());
        $response->assertHeader('Content-Disposition', 'attachment; filename="sample.json"');
    }

    public function test_dashboard_forbidden_when_user_lacks_permission(): void
    {
        $user = User::factory()->create();
        $stranger = User::factory()->create();
        $team = $user->currentTeam;

        $this->actingAs($stranger);

        $response = $this->getJson("/api/{$team->slug}/analytics/dashboard");

        $response->assertForbidden();
    }
}
