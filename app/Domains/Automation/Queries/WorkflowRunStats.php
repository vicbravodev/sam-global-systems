<?php

namespace App\Domains\Automation\Queries;

use App\Domains\Automation\Enums\ActionExecutionStatus;
use App\Domains\Automation\Models\ActionExecution;
use App\Support\TenantContext;
use Illuminate\Support\Carbon;

/**
 * Última ejecución y conteos recientes de cada automatización del tenant,
 * calculados sobre sus ActionExecution. Alimenta la tarjeta de cada
 * automatización ("última vez hace 3 h · 12 acciones en 30 días · 1 fallida").
 */
class WorkflowRunStats
{
    public const WINDOW_DAYS = 30;

    /**
     * @return array<int, array{lastRunAt: string|null, lastStatus: string|null, runs30d: int, failed30d: int}>
     */
    public function forTeam(int $teamId): array
    {
        return TenantContext::for($teamId, function () use ($teamId): array {
            $since = Carbon::now()->subDays(self::WINDOW_DAYS);

            $counts = ActionExecution::query()
                ->where('team_id', $teamId)
                ->whereNotNull('automation_workflow_id')
                ->where('created_at', '>=', $since)
                ->selectRaw('automation_workflow_id, count(*) as runs, sum(case when status = ? then 1 else 0 end) as failed', [ActionExecutionStatus::Failed->value])
                ->groupBy('automation_workflow_id')
                ->get()
                ->keyBy('automation_workflow_id');

            $latestIds = ActionExecution::query()
                ->where('team_id', $teamId)
                ->whereNotNull('automation_workflow_id')
                ->selectRaw('max(id) as id')
                ->groupBy('automation_workflow_id')
                ->pluck('id');

            $latest = ActionExecution::query()
                ->where('team_id', $teamId)
                ->whereIn('id', $latestIds)
                ->get(['id', 'automation_workflow_id', 'status', 'executed_at', 'created_at'])
                ->keyBy('automation_workflow_id');

            $stats = [];

            foreach ($latest->keys()->merge($counts->keys())->unique() as $workflowId) {
                $last = $latest->get($workflowId);
                $count = $counts->get($workflowId);

                $stats[(int) $workflowId] = [
                    'lastRunAt' => ($last?->executed_at ?? $last?->created_at)?->toIso8601String(),
                    'lastStatus' => $last?->status?->value,
                    'runs30d' => (int) ($count->runs ?? 0),
                    'failed30d' => (int) ($count->failed ?? 0),
                ];
            }

            return $stats;
        });
    }
}
