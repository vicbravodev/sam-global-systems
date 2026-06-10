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
    /**
     * Fallback SLA budget when neither the incident priority nor the event
     * severity define one. Mirrors IncidentInboxPresenter::DEFAULT_SLA_SECONDS.
     */
    private const DEFAULT_SLA_SECONDS = 1800;

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

    public function openCounts(int $teamId): array
    {
        $base = Incident::withoutGlobalScopes()
            ->where('team_id', $teamId)
            ->open();

        $open = (int) (clone $base)->count();

        $criticalOpen = (int) (clone $base)
            ->whereHas('priority', fn ($query) => $query->where('code', 'critical'))
            ->count();

        return [
            'open' => $open,
            'critical_open' => $criticalOpen,
        ];
    }

    public function openedPerDay(int $teamId, CarbonInterface $from, CarbonInterface $to): array
    {
        $date = $this->dateExpression();

        $rows = Incident::withoutGlobalScopes()
            ->where('team_id', $teamId)
            ->whereBetween('opened_at', [$from, $to])
            ->selectRaw("{$date} AS bucket")
            ->selectRaw('COUNT(*) AS total')
            ->groupBy('bucket')
            ->pluck('total', 'bucket')
            ->all();

        $criticalRows = Incident::withoutGlobalScopes()
            ->where('team_id', $teamId)
            ->whereBetween('opened_at', [$from, $to])
            ->whereHas('priority', fn ($query) => $query->where('code', 'critical'))
            ->selectRaw("{$date} AS bucket")
            ->selectRaw('COUNT(*) AS total')
            ->groupBy('bucket')
            ->pluck('total', 'bucket')
            ->all();

        $buckets = [];
        $cursor = $from->copy()->startOfDay();
        $end = $to->copy()->startOfDay();

        while ($cursor->lessThanOrEqualTo($end)) {
            $key = $cursor->toDateString();

            $buckets[] = [
                'date' => $key,
                'total' => (int) ($rows[$key] ?? 0),
                'critical' => (int) ($criticalRows[$key] ?? 0),
            ];

            $cursor = $cursor->addDay();
        }

        return $buckets;
    }

    public function slaCompliance(int $teamId, CarbonInterface $from, CarbonInterface $to): ?float
    {
        $resolved = Incident::withoutGlobalScopes()
            ->where('team_id', $teamId)
            ->whereNotNull('resolved_at')
            ->whereBetween('resolved_at', [$from, $to])
            ->with(['priority', 'relatedEvent.eventSeverity'])
            ->get(['id', 'team_id', 'incident_priority_id', 'related_event_id', 'opened_at', 'resolved_at']);

        if ($resolved->isEmpty()) {
            return null;
        }

        $withinSla = $resolved->filter(function (Incident $incident): bool {
            if ($incident->opened_at === null || $incident->resolved_at === null) {
                return false;
            }

            // Same SLA-budget resolution chain as IncidentInboxPresenter:
            // priority SLA, then event-severity response SLA, then default.
            $slaSeconds = (int) ($incident->priority?->sla_seconds
                ?? $incident->relatedEvent?->eventSeverity?->response_sla_seconds
                ?: self::DEFAULT_SLA_SECONDS);

            return $incident->opened_at->diffInSeconds($incident->resolved_at) <= $slaSeconds;
        });

        return round($withinSla->count() / $resolved->count() * 100, 1);
    }

    /**
     * SQL expression for the calendar day of `opened_at`, portable across
     * PostgreSQL (production) and SQLite (tests).
     */
    private function dateExpression(): string
    {
        return (new Incident)->getConnection()->getDriverName() === 'sqlite'
            ? "strftime('%Y-%m-%d', opened_at)"
            : 'TO_CHAR(opened_at, \'YYYY-MM-DD\')';
    }
}
