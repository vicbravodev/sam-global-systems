<?php

namespace App\Domains\Analytics\Actions;

use App\Domains\Analytics\Enums\ReportExecutionStatus;
use App\Domains\Analytics\Enums\ReportOutputFormat;
use App\Domains\Analytics\Enums\ReportRequestedByType;
use App\Domains\Analytics\Events\ReportGenerated;
use App\Domains\Analytics\Events\ReportReadyBroadcast;
use App\Domains\Analytics\Exports\ReportSnapshotXlsxExport;
use App\Domains\Analytics\Models\KpiRecord;
use App\Domains\Analytics\Models\ReportDefinition;
use App\Domains\Analytics\Models\ReportExecution;
use App\Domains\Tenancy\Actions\RecordUsageEvent;
use App\Domains\Tenancy\Models\FileObject;
use App\Domains\Tenancy\Models\TenantBranding;
use App\Models\Team;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Support\Facades\Storage;

class GenerateReport
{
    private const FILE_OBJECT_BUCKET_DEFAULT = 'rustfs';

    private const FILE_OBJECT_CATEGORY = 'export';

    private const DEFAULT_PRIMARY_COLOR = '#1F2937';

    private const DEFAULT_SECONDARY_COLOR = '#6B7280';

    public function __construct(
        private RecordUsageEvent $recordUsageEvent,
        private ReportSnapshotXlsxExport $xlsxExport,
    ) {}

    public function execute(
        ReportDefinition $definition,
        int $teamId,
        ReportOutputFormat $format,
        ReportRequestedByType $requestedBy,
        ?int $requestedById = null,
        ?array $filters = null,
    ): ReportExecution {
        $execution = ReportExecution::withoutGlobalScopes()->create([
            'report_definition_id' => $definition->id,
            'team_id' => $teamId,
            'requested_by_type' => $requestedBy->value,
            'requested_by_id' => $requestedById,
            'filters_json' => $filters,
            'status' => ReportExecutionStatus::Running->value,
            'output_format' => $format->value,
            'started_at' => now(),
        ]);

        try {
            $resultSnapshot = $this->buildResultSnapshot($definition, $teamId);
            [$filePath, $fileObjectId] = $this->writeOutput($execution, $definition, $format, $resultSnapshot);

            $execution->forceFill([
                'status' => ReportExecutionStatus::Completed->value,
                'finished_at' => now(),
                'result_snapshot_json' => $resultSnapshot,
                'file_path' => $filePath,
                'output_file_object_id' => $fileObjectId,
            ])->save();
        } catch (\Throwable $e) {
            $execution->forceFill([
                'status' => ReportExecutionStatus::Failed->value,
                'finished_at' => now(),
                'error_message' => $e->getMessage(),
            ])->save();

            throw $e;
        }

        $this->recordUsageEvent->execute(
            teamId: $teamId,
            meterCode: 'generated_reports',
            quantity: 1,
            eventKey: "report_exec_{$execution->id}",
        );

        ReportGenerated::dispatch(
            $teamId,
            $execution->id,
            $definition->report_type->value,
            $format->value,
        );

        broadcast(new ReportReadyBroadcast(
            teamId: $teamId,
            reportExecutionId: $execution->id,
            reportName: $definition->name,
            outputFormat: $format->value,
        ));

        return $execution->refresh();
    }

    /**
     * @return array<string, mixed>
     */
    private function buildResultSnapshot(ReportDefinition $definition, int $teamId): array
    {
        $metricCodes = (array) ($definition->metrics_json ?? []);

        $kpiQuery = KpiRecord::withoutGlobalScopes()
            ->where('team_id', $teamId)
            ->orderByDesc('period_start');

        if ($metricCodes !== []) {
            $kpiQuery->whereIn('kpi_code', $metricCodes);
        }

        return [
            'report_code' => $definition->code,
            'report_type' => $definition->report_type->value,
            'generated_at' => now()->toIso8601String(),
            'metrics' => $kpiQuery->limit(100)->get()->map(fn ($k) => [
                'code' => $k->kpi_code,
                'period_start' => $k->period_start?->toIso8601String(),
                'period_end' => $k->period_end?->toIso8601String(),
                'value' => $k->value,
                'unit' => $k->unit,
            ])->all(),
        ];
    }

    /**
     * @param  array<string, mixed>  $snapshot
     * @return array{0: string|null, 1: int|null}
     */
    private function writeOutput(
        ReportExecution $execution,
        ReportDefinition $definition,
        ReportOutputFormat $format,
        array $snapshot,
    ): array {
        if ($format === ReportOutputFormat::Dashboard) {
            return [null, null];
        }

        $extension = $this->extensionFor($format);
        $path = "reports/{$execution->team_id}/{$execution->id}.{$extension}";

        $contents = match ($format) {
            ReportOutputFormat::Pdf => $this->renderPdf($definition, $execution, $snapshot),
            ReportOutputFormat::Xlsx => $this->renderXlsx($execution, $definition, $snapshot),
            ReportOutputFormat::Csv => $this->snapshotAsCsv($snapshot),
            default => json_encode($snapshot, JSON_PRETTY_PRINT) ?: '{}',
        };

        Storage::disk('rustfs')->put($path, $contents);

        $fileObjectId = $this->recordFileObject(
            execution: $execution,
            path: $path,
            contents: $contents,
            format: $format,
        );

        return [$path, $fileObjectId];
    }

    private function extensionFor(ReportOutputFormat $format): string
    {
        return match ($format) {
            ReportOutputFormat::Json => 'json',
            ReportOutputFormat::Csv => 'csv',
            ReportOutputFormat::Pdf => 'pdf',
            ReportOutputFormat::Xlsx => 'xlsx',
            ReportOutputFormat::Dashboard => 'json',
        };
    }

    /**
     * @param  array<string, mixed>  $snapshot
     */
    private function renderPdf(ReportDefinition $definition, ReportExecution $execution, array $snapshot): string
    {
        $team = Team::query()->find($execution->team_id);
        $branding = $this->resolveBranding($execution->team_id, $team?->name);
        $teamName = $team?->name ?? 'Tenant';

        return Pdf::loadView('reports.pdf', [
            'reportName' => $definition->name,
            'reportType' => (string) ($snapshot['report_type'] ?? ''),
            'generatedAt' => (string) ($snapshot['generated_at'] ?? ''),
            'metrics' => (array) ($snapshot['metrics'] ?? []),
            'branding' => $branding,
            'teamName' => $teamName,
        ])->setPaper('a4')->output();
    }

    /**
     * @param  array<string, mixed>  $snapshot
     */
    private function renderXlsx(ReportExecution $execution, ReportDefinition $definition, array $snapshot): string
    {
        $team = Team::query()->find($execution->team_id);

        return $this->xlsxExport->build(
            snapshot: $snapshot,
            reportName: $definition->name,
            teamName: $team?->name ?? 'Tenant',
        );
    }

    /**
     * @return array{display_name: string, primary_color: string, secondary_color: string, logo_url: ?string}
     */
    private function resolveBranding(int $teamId, ?string $fallbackName): array
    {
        $branding = TenantBranding::withoutGlobalScopes()
            ->where('team_id', $teamId)
            ->first();

        return [
            'display_name' => $branding?->display_name ?? ($fallbackName ?? 'Tenant'),
            'primary_color' => $branding?->primary_color ?? self::DEFAULT_PRIMARY_COLOR,
            'secondary_color' => $branding?->secondary_color ?? self::DEFAULT_SECONDARY_COLOR,
            'logo_url' => $branding?->logo_url,
        ];
    }

    private function recordFileObject(
        ReportExecution $execution,
        string $path,
        string $contents,
        ReportOutputFormat $format,
    ): int {
        $fileObject = FileObject::withoutGlobalScopes()->create([
            'team_id' => $execution->team_id,
            'bucket' => config('filesystems.disks.rustfs.bucket', self::FILE_OBJECT_BUCKET_DEFAULT),
            'object_key' => $path,
            'original_filename' => basename($path),
            'size_bytes' => strlen($contents),
            'content_type' => $this->mimeFor($format),
            'visibility' => 'private',
            'category' => self::FILE_OBJECT_CATEGORY,
            'fileable_type' => ReportExecution::class,
            'fileable_id' => $execution->id,
        ]);

        return $fileObject->id;
    }

    private function mimeFor(ReportOutputFormat $format): string
    {
        return match ($format) {
            ReportOutputFormat::Pdf => 'application/pdf',
            ReportOutputFormat::Xlsx => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
            ReportOutputFormat::Csv => 'text/csv',
            ReportOutputFormat::Json => 'application/json',
            ReportOutputFormat::Dashboard => 'application/json',
        };
    }

    /**
     * @param  array<string, mixed>  $snapshot
     */
    private function snapshotAsCsv(array $snapshot): string
    {
        $lines = ['code,period_start,period_end,value,unit'];

        foreach ((array) ($snapshot['metrics'] ?? []) as $row) {
            $lines[] = sprintf(
                '%s,%s,%s,%s,%s',
                $row['code'] ?? '',
                $row['period_start'] ?? '',
                $row['period_end'] ?? '',
                $row['value'] ?? '',
                $row['unit'] ?? '',
            );
        }

        return implode("\n", $lines);
    }
}
