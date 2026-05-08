<?php

namespace Tests\Feature\Domains\Analytics;

use App\Domains\Analytics\Actions\GenerateReport;
use App\Domains\Analytics\Enums\ReportExecutionStatus;
use App\Domains\Analytics\Enums\ReportOutputFormat;
use App\Domains\Analytics\Enums\ReportRequestedByType;
use App\Domains\Analytics\Models\KpiRecord;
use App\Domains\Analytics\Models\ReportDefinition;
use App\Domains\Analytics\Models\ReportExecution;
use App\Domains\Tenancy\Enums\AggregationType;
use App\Domains\Tenancy\Enums\ResetPeriod;
use App\Domains\Tenancy\Models\FileObject;
use App\Domains\Tenancy\Models\UsageMeter;
use App\Models\Team;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use PhpOffice\PhpSpreadsheet\IOFactory;
use Tests\TestCase;

class GenerateReportXlsxTest extends TestCase
{
    use RefreshDatabase;

    public function test_xlsx_report_renders_uploads_to_rustfs_and_links_file_object(): void
    {
        Storage::fake('rustfs');

        $team = Team::factory()->create();
        $definition = ReportDefinition::factory()->create([
            'team_id' => $team->id,
            'name' => 'Operational Weekly',
            'metrics_json' => ['incidents_total', 'mttr_minutes'],
        ]);
        KpiRecord::factory()->create([
            'team_id' => $team->id,
            'kpi_code' => 'incidents_total',
            'value' => 7.0,
            'unit' => 'incidents',
        ]);
        KpiRecord::factory()->create([
            'team_id' => $team->id,
            'kpi_code' => 'mttr_minutes',
            'value' => 45.25,
            'unit' => 'minutes',
        ]);

        $this->seedGeneratedReportsMeter();

        $execution = app(GenerateReport::class)->execute(
            $definition,
            $team->id,
            ReportOutputFormat::Xlsx,
            ReportRequestedByType::User,
        );

        $this->assertSame(ReportExecutionStatus::Completed, $execution->status);
        $this->assertSame("reports/{$team->id}/{$execution->id}.xlsx", $execution->file_path);
        Storage::disk('rustfs')->assertExists($execution->file_path);

        $contents = (string) Storage::disk('rustfs')->get($execution->file_path);
        $this->assertNotEmpty($contents);

        // XLSX is a ZIP container — the magic header is "PK\x03\x04".
        $this->assertSame("PK\x03\x04", substr($contents, 0, 4));

        // Round-trip the file through phpspreadsheet to confirm structure.
        $tmp = tempnam(sys_get_temp_dir(), 'xlsx-test-').'.xlsx';
        file_put_contents($tmp, $contents);
        $spreadsheet = IOFactory::load($tmp);
        $sheet = $spreadsheet->getActiveSheet();

        $this->assertSame('Operational Weekly', $sheet->getCell('A1')->getValue());
        $this->assertSame('Metric', $sheet->getCell('A4')->getValue());
        $this->assertSame('Period start', $sheet->getCell('B4')->getValue());
        $this->assertSame('Period end', $sheet->getCell('C4')->getValue());
        $this->assertSame('Value', $sheet->getCell('D4')->getValue());
        $this->assertSame('Unit', $sheet->getCell('E4')->getValue());

        $codeColumn = [
            $sheet->getCell('A5')->getValue(),
            $sheet->getCell('A6')->getValue(),
        ];
        $this->assertEqualsCanonicalizing(
            ['incidents_total', 'mttr_minutes'],
            $codeColumn,
        );

        $spreadsheet->disconnectWorksheets();
        @unlink($tmp);

        $this->assertNotNull($execution->output_file_object_id);
        $fileObject = FileObject::withoutGlobalScopes()->find($execution->output_file_object_id);
        $this->assertInstanceOf(FileObject::class, $fileObject);
        $this->assertSame($team->id, $fileObject->team_id);
        $this->assertSame(
            'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
            $fileObject->content_type,
        );
        $this->assertSame('export', $fileObject->category);
        $this->assertSame(ReportExecution::class, $fileObject->fileable_type);
        $this->assertSame($execution->id, (int) $fileObject->fileable_id);
        $this->assertSame(strlen($contents), $fileObject->size_bytes);
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
