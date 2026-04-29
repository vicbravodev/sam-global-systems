<?php

namespace Tests\Feature\Domains\Analytics;

use App\Domains\Analytics\Actions\GenerateReport;
use App\Domains\Analytics\Enums\ReportExecutionStatus;
use App\Domains\Analytics\Enums\ReportOutputFormat;
use App\Domains\Analytics\Enums\ReportRequestedByType;
use App\Domains\Analytics\Events\ReportGenerated;
use App\Domains\Analytics\Events\ReportReadyBroadcast;
use App\Domains\Analytics\Models\KpiRecord;
use App\Domains\Analytics\Models\ReportDefinition;
use App\Domains\Tenancy\Enums\AggregationType;
use App\Domains\Tenancy\Enums\ResetPeriod;
use App\Domains\Tenancy\Models\UsageEvent;
use App\Domains\Tenancy\Models\UsageMeter;
use App\Models\Team;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class GenerateReportTest extends TestCase
{
    use RefreshDatabase;

    public function test_report_writes_file_to_rustfs_and_records_execution(): void
    {
        Storage::fake('rustfs');
        Event::fake([ReportGenerated::class, ReportReadyBroadcast::class]);

        $team = Team::factory()->create();
        $definition = ReportDefinition::factory()->create([
            'team_id' => $team->id,
            'metrics_json' => ['incidents_total'],
        ]);
        KpiRecord::factory()->create([
            'team_id' => $team->id,
            'kpi_code' => 'incidents_total',
            'value' => 7.0,
        ]);

        $this->seedGeneratedReportsMeter();

        $execution = app(GenerateReport::class)->execute(
            $definition,
            $team->id,
            ReportOutputFormat::Json,
            ReportRequestedByType::User,
        );

        $this->assertSame(ReportExecutionStatus::Completed, $execution->status);
        $this->assertNotNull($execution->file_path);
        Storage::disk('rustfs')->assertExists($execution->file_path);

        $this->assertSame(
            1,
            UsageEvent::withoutGlobalScopes()
                ->where('team_id', $team->id)
                ->where('event_key', "report_exec_{$execution->id}")
                ->count(),
        );

        Event::assertDispatched(ReportGenerated::class);
        Event::assertDispatched(ReportReadyBroadcast::class);
    }

    public function test_csv_output_serializes_metrics_as_csv(): void
    {
        Storage::fake('rustfs');

        $team = Team::factory()->create();
        $definition = ReportDefinition::factory()->create([
            'team_id' => $team->id,
            'metrics_json' => ['incidents_total'],
        ]);
        KpiRecord::factory()->create([
            'team_id' => $team->id,
            'kpi_code' => 'incidents_total',
            'value' => 7.0,
        ]);

        $this->seedGeneratedReportsMeter();

        $execution = app(GenerateReport::class)->execute(
            $definition,
            $team->id,
            ReportOutputFormat::Csv,
            ReportRequestedByType::User,
        );

        $contents = Storage::disk('rustfs')->get($execution->file_path);
        $this->assertStringContainsString('code,period_start,period_end,value,unit', $contents);
        $this->assertStringContainsString('incidents_total', $contents);
    }

    public function test_dashboard_format_writes_no_file(): void
    {
        Storage::fake('rustfs');

        $team = Team::factory()->create();
        $definition = ReportDefinition::factory()->create(['team_id' => $team->id]);
        $this->seedGeneratedReportsMeter();

        $execution = app(GenerateReport::class)->execute(
            $definition,
            $team->id,
            ReportOutputFormat::Dashboard,
            ReportRequestedByType::User,
        );

        $this->assertNull($execution->file_path);
    }

    private function seedGeneratedReportsMeter(): void
    {
        UsageMeter::query()->updateOrCreate(
            ['code' => 'generated_reports'],
            [
                'name' => 'Generated Reports',
                'description' => 'Number of generated report exports.',
                'unit' => 'count',
                'aggregation_type' => AggregationType::Sum,
                'is_billable' => true,
                'reset_period' => ResetPeriod::Monthly,
            ],
        );
    }
}
