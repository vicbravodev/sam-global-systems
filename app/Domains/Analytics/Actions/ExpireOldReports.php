<?php

namespace App\Domains\Analytics\Actions;

use App\Contracts\TenantConfig\TenantAnalyticsConfig;
use App\Domains\Analytics\Enums\ReportExecutionStatus;
use App\Domains\Analytics\Models\ReportExecution;
use Illuminate\Support\Facades\Storage;

class ExpireOldReports
{
    public function __construct(
        private TenantAnalyticsConfig $tenantConfig,
    ) {}

    public function execute(int $teamId): int
    {
        $retentionDays = $this->tenantConfig->reportRetentionDays($teamId);
        $threshold = now()->subDays($retentionDays);

        $candidates = ReportExecution::withoutGlobalScopes()
            ->where('team_id', $teamId)
            ->where('status', ReportExecutionStatus::Completed->value)
            ->where('finished_at', '<', $threshold)
            ->get();

        foreach ($candidates as $execution) {
            if ($execution->file_path) {
                Storage::disk('rustfs')->delete($execution->file_path);
            }

            $execution->forceFill([
                'status' => ReportExecutionStatus::Expired->value,
                'file_path' => null,
            ])->save();
        }

        return $candidates->count();
    }
}
