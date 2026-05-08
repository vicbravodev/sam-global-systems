<?php

namespace App\Domains\Incidents\Queries;

use App\Contracts\Incidents\IncidentMetricsQuery;
use App\Domains\Incidents\Enums\TimelineEntryType;
use App\Domains\Incidents\Models\Incident;
use App\Domains\Incidents\Models\IncidentStatus;
use App\Domains\Incidents\Models\IncidentTimeline;
use Carbon\CarbonInterface;

class DbIncidentMetricsQuery implements IncidentMetricsQuery
{
    public function totalsForTenant(int $teamId, CarbonInterface $from, CarbonInterface $to): array
    {
        $base = Incident::withoutGlobalScopes()
            ->where('team_id', $teamId)
            ->whereBetween('opened_at', [$from, $to]);

        $total = (int) (clone $base)->count();

        $resolved = (int) (clone $base)
            ->whereNotNull('resolved_at')
            ->count();

        $terminalStatusIds = IncidentStatus::query()
            ->where('is_terminal', true)
            ->pluck('id');

        $open = $terminalStatusIds->isEmpty()
            ? $total
            : (int) (clone $base)
                ->whereNotIn('incident_status_id', $terminalStatusIds)
                ->count();

        $resolvedPairs = (clone $base)
            ->whereNotNull('resolved_at')
            ->select(['opened_at', 'resolved_at'])
            ->get();

        $meanResolutionMinutes = $resolvedPairs->isEmpty()
            ? 0.0
            : (float) $resolvedPairs->avg(
                fn ($row) => $row->opened_at->diffInSeconds($row->resolved_at) / 60.0,
            );

        $escalations = (int) IncidentTimeline::query()
            ->where('entry_type', TimelineEntryType::Escalated->value)
            ->whereBetween('occurred_at', [$from, $to])
            ->whereIn(
                'incident_id',
                Incident::withoutGlobalScopes()
                    ->where('team_id', $teamId)
                    ->select('id'),
            )
            ->count();

        return [
            'total' => $total,
            'resolved' => $resolved,
            'open' => $open,
            'mean_resolution_time_minutes' => round($meanResolutionMinutes, 2),
            'escalations' => $escalations,
        ];
    }
}
