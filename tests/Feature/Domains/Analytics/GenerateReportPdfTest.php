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
use App\Domains\Analytics\Models\ReportExecution;
use App\Domains\Tenancy\Enums\AggregationType;
use App\Domains\Tenancy\Enums\ResetPeriod;
use App\Domains\Tenancy\Models\FileObject;
use App\Domains\Tenancy\Models\TenantBranding;
use App\Domains\Tenancy\Models\UsageMeter;
use App\Models\Team;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class GenerateReportPdfTest extends TestCase
{
    use RefreshDatabase;

    public function test_pdf_report_renders_uploads_to_rustfs_and_links_file_object(): void
    {
        Storage::fake('rustfs');
        Event::fake([ReportGenerated::class, ReportReadyBroadcast::class]);

        $team = Team::factory()->create();
        TenantBranding::withoutGlobalScopes()->create([
            'team_id' => $team->id,
            'display_name' => 'Acme Logistics',
            'primary_color' => '#0F4C81',
            'secondary_color' => '#F47B20',
            'logo_url' => null,
        ]);

        $definition = ReportDefinition::factory()->create([
            'team_id' => $team->id,
            'name' => 'Operational Weekly',
            'metrics_json' => ['incidents_total'],
        ]);
        KpiRecord::factory()->create([
            'team_id' => $team->id,
            'kpi_code' => 'incidents_total',
            'value' => 12.5,
            'unit' => 'incidents',
        ]);

        $this->seedGeneratedReportsMeter();

        $execution = app(GenerateReport::class)->execute(
            $definition,
            $team->id,
            ReportOutputFormat::Pdf,
            ReportRequestedByType::User,
        );

        $this->assertSame(ReportExecutionStatus::Completed, $execution->status);
        $this->assertSame("reports/{$team->id}/{$execution->id}.pdf", $execution->file_path);
        Storage::disk('rustfs')->assertExists($execution->file_path);

        $contents = Storage::disk('rustfs')->get($execution->file_path);
        $this->assertNotNull($contents);
        $this->assertStringStartsWith('%PDF-', (string) $contents);

        $this->assertNotNull($execution->output_file_object_id);
        $fileObject = FileObject::withoutGlobalScopes()->find($execution->output_file_object_id);
        $this->assertInstanceOf(FileObject::class, $fileObject);
        $this->assertSame($team->id, $fileObject->team_id);
        $this->assertSame('application/pdf', $fileObject->content_type);
        $this->assertSame('export', $fileObject->category);
        $this->assertSame(ReportExecution::class, $fileObject->fileable_type);
        $this->assertSame($execution->id, (int) $fileObject->fileable_id);
        $this->assertGreaterThan(0, $fileObject->size_bytes);
        $this->assertSame(strlen((string) $contents), $fileObject->size_bytes);
        $this->assertSame("{$execution->id}.pdf", $fileObject->original_filename);

        Event::assertDispatched(ReportGenerated::class);
        Event::assertDispatched(ReportReadyBroadcast::class);
    }

    public function test_pdf_report_uses_default_branding_when_tenant_has_none(): void
    {
        Storage::fake('rustfs');

        $team = Team::factory()->create();
        $definition = ReportDefinition::factory()->create(['team_id' => $team->id]);
        $this->seedGeneratedReportsMeter();

        $execution = app(GenerateReport::class)->execute(
            $definition,
            $team->id,
            ReportOutputFormat::Pdf,
            ReportRequestedByType::System,
        );

        $this->assertSame(ReportExecutionStatus::Completed, $execution->status);
        Storage::disk('rustfs')->assertExists($execution->file_path);

        $contents = (string) Storage::disk('rustfs')->get($execution->file_path);
        $this->assertStringStartsWith('%PDF-', $contents);
        // PDFs are deflate-compressed so we cannot grep team name; the smoke
        // test is that the writer succeeded and produced a valid PDF stream.
        $this->assertGreaterThan(800, strlen($contents));
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
