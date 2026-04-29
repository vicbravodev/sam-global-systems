<?php

namespace App\Domains\Analytics\Actions;

use App\Domains\Analytics\Enums\ReportExecutionStatus;
use App\Domains\Analytics\Enums\ReportOutputFormat;
use App\Domains\Analytics\Enums\ReportRequestedByType;
use App\Domains\Analytics\Events\ReportGenerated;
use App\Domains\Analytics\Events\ReportReadyBroadcast;
use App\Domains\Analytics\Models\KpiRecord;
use App\Domains\Analytics\Models\ReportDefinition;
use App\Domains\Analytics\Models\ReportExecution;
use App\Domains\Tenancy\Actions\RecordUsageEvent;
use Illuminate\Support\Facades\Storage;

class GenerateReport
{
    public function __construct(
        private RecordUsageEvent $recordUsageEvent,
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
            $filePath = $this->writeOutput($execution, $format, $resultSnapshot);

            $execution->forceFill([
                'status' => ReportExecutionStatus::Completed->value,
                'finished_at' => now(),
                'result_snapshot_json' => $resultSnapshot,
                'file_path' => $filePath,
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
     * SPEC-15-PDF-DEFERRED: real PDF/XLSX rendering ships in a follow-up.
     * For now we serialize the snapshot as JSON regardless of `output_format`,
     * which still exercises the RustFS write path and download endpoint.
     *
     * @param  array<string, mixed>  $snapshot
     */
    private function writeOutput(
        ReportExecution $execution,
        ReportOutputFormat $format,
        array $snapshot,
    ): ?string {
        if ($format === ReportOutputFormat::Dashboard) {
            return null;
        }

        $extension = $format === ReportOutputFormat::Json ? 'json' : $format->value;
        $path = "reports/{$execution->team_id}/{$execution->id}.{$extension}";

        $contents = match ($format) {
            ReportOutputFormat::Csv => $this->snapshotAsCsv($snapshot),
            default => json_encode($snapshot, JSON_PRETTY_PRINT) ?: '{}',
        };

        Storage::disk('rustfs')->put($path, $contents);

        return $path;
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
