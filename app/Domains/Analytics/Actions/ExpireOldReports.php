<?php

namespace App\Domains\Analytics\Actions;

use App\Contracts\TenantConfig\TenantAnalyticsConfig;
use App\Domains\Analytics\Enums\ReportExecutionStatus;
use App\Domains\Analytics\Models\ReportExecution;
use App\Support\SystemLog;
use App\Support\TenantContext;
use Illuminate\Support\Facades\Storage;

class ExpireOldReports
{
    public function __construct(
        private TenantAnalyticsConfig $tenantConfig,
    ) {}

    public function execute(int $teamId): int
    {
        return TenantContext::for($teamId, function () use ($teamId) {
            $retentionDays = $this->tenantConfig->reportRetentionDays($teamId);
            $threshold = now()->subDays($retentionDays);

            $candidates = ReportExecution::query()
                ->where('team_id', $teamId)
                ->where('status', ReportExecutionStatus::Completed->value)
                ->where('finished_at', '<', $threshold)
                ->get();

            $filesDeleted = 0;

            foreach ($candidates as $execution) {
                // GenerateReport sólo escribe rutas "reports/{team}/{id}.ext":
                // un '0' no puede darse, basta con descartar null y vacío.
                if ($execution->file_path !== null && $execution->file_path !== '') {
                    Storage::disk('rustfs')->delete($execution->file_path);
                    $filesDeleted++;
                }

                $execution->forceFill([
                    'status' => ReportExecutionStatus::Expired->value,
                    'file_path' => null,
                ])->save();
            }

            SystemLog::ok('analytics.reports.expired', input: ['team_id' => $teamId], calc: [
                'retention_days' => $retentionDays,
                'threshold' => $threshold->toIso8601String(),
            ], result: [
                'expired_count' => $candidates->count(),
                'files_deleted' => $filesDeleted,
            ], debug: $candidates->isEmpty());

            return $candidates->count();
        });
    }
}
