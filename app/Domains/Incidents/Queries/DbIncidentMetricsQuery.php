<?php

namespace App\Domains\Incidents\Queries;

use App\Contracts\Incidents\IncidentMetricsQuery;
use App\Domains\Incidents\Enums\TimelineEntryType;
use App\Domains\Incidents\Models\Incident;
use App\Domains\Incidents\Models\IncidentPriority;
use App\Domains\Incidents\Models\IncidentStatus;
use App\Domains\Incidents\Models\IncidentTimeline;
use App\Support\TenantContext;
use Carbon\CarbonInterface;
use Illuminate\Support\Carbon;

class DbIncidentMetricsQuery implements IncidentMetricsQuery
{
    /**
     * Fallback SLA budget when neither the incident priority nor the event
     * severity define one. Mirrors IncidentInboxPresenter::DEFAULT_SLA_SECONDS.
     */
    private const DEFAULT_SLA_SECONDS = 1800;

    public function totalsForTenant(int $teamId, CarbonInterface $from, CarbonInterface $to): array
    {
        return TenantContext::for($teamId, function () use ($from, $to) {
            $base = Incident::query()
                ->whereBetween('opened_at', [$from, $to]);

            $total = (clone $base)->count();

            $resolved = (clone $base)
                ->whereNotNull('resolved_at')
                ->count();

            $terminalStatusIds = IncidentStatus::query()
                ->where('is_terminal', true)
                ->pluck('id');

            $open = $terminalStatusIds->isEmpty()
                ? $total
                : (clone $base)
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

            $escalations = IncidentTimeline::query()
                ->where('entry_type', TimelineEntryType::Escalated->value)
                ->whereBetween('occurred_at', [$from, $to])
                ->whereIn(
                    'incident_id',
                    Incident::query()
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
        });
    }

    public function openCounts(int $teamId): array
    {
        return TenantContext::for($teamId, function () {
            $base = Incident::query()
                ->open();

            $open = (clone $base)->count();

            $criticalOpen = (clone $base)
                ->whereHas('priority', fn ($query) => $query->where('code', 'critical'))
                ->count();

            return [
                'open' => $open,
                'critical_open' => $criticalOpen,
            ];
        });
    }

    public function openedPerDay(int $teamId, CarbonInterface $from, CarbonInterface $to): array
    {
        return TenantContext::for($teamId, function () use ($from, $to) {
            $date = $this->dateExpression();

            $rows = Incident::query()
                ->whereBetween('opened_at', [$from, $to])
                ->selectRaw("{$date} AS bucket")
                ->selectRaw('COUNT(*) AS total')
                ->groupBy('bucket')
                ->pluck('total', 'bucket')
                ->all();

            $criticalRows = Incident::query()
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
        });
    }

    public function openBacklogPerDay(int $teamId, CarbonInterface $from, CarbonInterface $to): array
    {
        return TenantContext::for($teamId, function () use ($from, $to) {
            $instants = [];
            $cursor = $from->copy()->startOfDay();
            $lastDay = $to->copy()->startOfDay();

            while ($cursor->lessThanOrEqualTo($lastDay)) {
                $instants[$cursor->toDateString()] = $cursor->equalTo($lastDay) ? $to : $cursor->copy()->endOfDay();
                $cursor = $cursor->addDay();
            }

            if ($instants === []) {
                return [];
            }

            // One pass over the incidents open at some point of the window,
            // bucketed in PHP (2 counts per day used to scan the tenant's whole
            // incident history, unindexable through the OR + COALESCE).
            // Open at `$at` = opened by then and either still open (every
            // terminal transition stamps a closing timestamp; reopen clears
            // them) or closed after it.
            $firstInstant = reset($instants);
            $criticalPriorityIds = IncidentPriority::query()->where('code', 'critical')->pluck('id')->all();

            $stillOpen = Incident::query()
                ->where('opened_at', '<=', $to)
                ->whereNull('resolved_at')
                ->whereNull('closed_at')
                ->whereNull('cancelled_at')
                ->whereNull('false_positive_at')
                ->open()
                ->toBase()
                ->get(['opened_at', 'incident_priority_id'])
                ->map(fn (object $row) => [$row->opened_at, null, $row->incident_priority_id]);

            $closedLater = Incident::query()
                ->where('opened_at', '<=', $to)
                ->whereRaw(
                    'COALESCE(resolved_at, closed_at, cancelled_at, false_positive_at) > ?',
                    [$firstInstant->toDateTimeString()],
                )
                ->toBase()
                ->selectRaw('opened_at, incident_priority_id, COALESCE(resolved_at, closed_at, cancelled_at, false_positive_at) as ended_at')
                ->get()
                ->map(fn (object $row) => [$row->opened_at, $row->ended_at, $row->incident_priority_id]);

            $candidates = $stillOpen->concat($closedLater)->map(fn (array $row) => [
                'opened_at' => Carbon::parse($row[0]),
                'ended_at' => $row[1] !== null ? Carbon::parse($row[1]) : null,
                // Fila cruda (toBase): el id llega como int (o string numérico
                // según el driver); se normaliza para comparar estricto.
                'critical' => is_numeric($row[2]) && in_array((int) $row[2], $criticalPriorityIds, true),
            ]);

            $buckets = [];

            foreach ($instants as $date => $at) {
                $open = $candidates->filter(fn (array $row) => $row['opened_at']->lessThanOrEqualTo($at)
                    && ($row['ended_at'] === null || $row['ended_at']->greaterThan($at)));

                $buckets[] = [
                    'date' => $date,
                    'total' => $open->count(),
                    'critical' => $open->where('critical', true)->count(),
                ];
            }

            return $buckets;
        });
    }

    public function slaCompliance(int $teamId, CarbonInterface $from, CarbonInterface $to): ?float
    {
        return TenantContext::for($teamId, function () use ($from, $to) {
            $resolved = Incident::query()
                ->whereNotNull('resolved_at')
                ->whereBetween('resolved_at', [$from, $to])
                ->with(['priority', 'relatedEvent.eventSeverity'])
                ->get(['id', 'team_id', 'incident_priority_id', 'related_event_id', 'opened_at', 'resolved_at', 'sla_due_at']);

            if ($resolved->isEmpty()) {
                return null;
            }

            $withinSla = $resolved->filter(function (Incident $incident): bool {
                if ($incident->resolved_at === null) {
                    return false;
                }

                // `sla_due_at` is the vigilance actually scheduled at creation
                // time (possibly a tenant override) — comparing against it
                // directly keeps compliance in sync with the real watchdog.
                if ($incident->sla_due_at !== null) {
                    return $incident->resolved_at->lessThanOrEqualTo($incident->sla_due_at);
                }

                // Incidents predating the `sla_due_at` column (or with no SLA
                // resolved at creation): same catalog chain as before —
                // priority SLA, then event-severity response SLA, then default.
                // Un SLA de 0 (o ausente) cae al default, como el `?:` original.
                $catalogSla = $incident->priority?->sla_seconds
                    ?? $incident->relatedEvent?->eventSeverity?->response_sla_seconds;
                $slaSeconds = $catalogSla !== null && $catalogSla !== 0 ? $catalogSla : self::DEFAULT_SLA_SECONDS;

                return $incident->opened_at->diffInSeconds($incident->resolved_at) <= $slaSeconds;
            });

            return round($withinSla->count() / $resolved->count() * 100, 1);
        });
    }

    /**
     * SQL expression for the calendar day of `opened_at`, portable across
     * PostgreSQL (production) and SQLite (tests).
     */
    /**
     * @return literal-string
     */
    private function dateExpression(): string
    {
        return (new Incident)->getConnection()->getDriverName() === 'sqlite'
            ? "strftime('%Y-%m-%d', opened_at)"
            : 'TO_CHAR(opened_at, \'YYYY-MM-DD\')';
    }
}
