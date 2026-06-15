<?php

namespace App\Http\Controllers\Dashboard;

use App\Contracts\Decisions\DecisionMetricsQuery;
use App\Contracts\Incidents\IncidentMetricsQuery;
use App\Contracts\Normalization\NormalizedEventStatsQuery;
use App\Domains\Decisions\Enums\DecisionOutcomeCode;
use App\Domains\Decisions\Models\Decision;
use App\Domains\Incidents\Enums\AssigneeType;
use App\Domains\Incidents\Models\Incident;
use App\Domains\Incidents\Support\IncidentInboxPresenter;
use App\Domains\Integrations\Models\TenantIntegration;
use App\Domains\Normalization\Models\NormalizedEvent;
use App\Domains\Tenancy\Models\TenantUsageCounter;
use App\Http\Controllers\Controller;
use App\Models\Team;
use App\Models\User;
use Carbon\CarbonInterface;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Operational dashboard: live tenant-wide aggregates over incidents,
 * normalized events, integrations health and metered usage.
 *
 * Intentionally not gated by a policy: it is the tenant landing page and only
 * exposes aggregates plus a top-5 preview, so `auth + verified +
 * EnsureTeamMembership` is the whole authorization story.
 */
class DashboardController extends Controller
{
    public function __construct(
        private readonly IncidentMetricsQuery $incidentMetrics,
        private readonly DecisionMetricsQuery $decisionMetrics,
        private readonly NormalizedEventStatsQuery $eventStats,
        private readonly IncidentInboxPresenter $presenter,
    ) {}

    public function index(Team $current_team): Response
    {
        return Inertia::render('dashboard', [
            'kpis' => fn () => $this->kpis($current_team),
            'incidents' => fn () => $this->openIncidents($current_team),
            'stream' => fn () => $this->stream($current_team),
            'integrations' => fn () => $this->integrations($current_team),
            'usage' => fn () => $this->usage($current_team),
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    private function kpis(Team $team): array
    {
        $now = Carbon::now();
        $weekAgo = $now->copy()->subDays(6)->startOfDay();
        $previousWeekStart = $now->copy()->subDays(13)->startOfDay();
        $previousWeekEnd = $weekAgo->copy()->subSecond();

        $openCounts = $this->incidentMetrics->openCounts($team->id);
        $perDay = $this->incidentMetrics->openedPerDay($team->id, $weekAgo, $now);

        $slaCurrent = $this->incidentMetrics->slaCompliance($team->id, $weekAgo, $now);
        $slaPrevious = $this->incidentMetrics->slaCompliance($team->id, $previousWeekStart, $previousWeekEnd);

        $decisionsCurrent = $this->decisionMetrics->totalsForTenant($team->id, $weekAgo, $now);
        $decisionsPrevious = $this->decisionMetrics->totalsForTenant($team->id, $previousWeekStart, $previousWeekEnd);

        $today = end($perDay) ?: ['total' => 0, 'critical' => 0];
        $yesterday = count($perDay) > 1 ? $perDay[count($perDay) - 2] : ['total' => 0, 'critical' => 0];

        return [
            'openIncidents' => [
                'value' => $openCounts['open'],
                'deltaPct' => $yesterday['total'] > 0
                    ? round(($today['total'] - $yesterday['total']) / $yesterday['total'] * 100, 1)
                    : null,
                'series' => array_map(fn (array $bucket) => $bucket['total'], $perDay),
            ],
            'criticalOpen' => [
                'value' => $openCounts['critical_open'],
                'avgSlaRemainingSeconds' => $this->avgCriticalSlaRemaining($team),
                'series' => array_map(fn (array $bucket) => $bucket['critical'], $perDay),
            ],
            'slaCompliance' => [
                'value' => $slaCurrent,
                'deltaPp' => $slaCurrent !== null && $slaPrevious !== null
                    ? round($slaCurrent - $slaPrevious, 1)
                    : null,
            ],
            'aiPrecision' => [
                'value' => $this->aiPrecision($decisionsCurrent),
                'deltaPp' => $this->precisionDelta($decisionsCurrent, $decisionsPrevious),
            ],
        ];
    }

    /**
     * Same precision semantics as Analytics' EvaluateAIEffectiveness:
     * decisions not overridden by a human / total decisions.
     *
     * @param  array{total: int, human_overrides: int}  $totals
     */
    private function aiPrecision(array $totals): ?float
    {
        if ($totals['total'] === 0) {
            return null;
        }

        return round(($totals['total'] - $totals['human_overrides']) / $totals['total'] * 100, 1);
    }

    /**
     * @param  array{total: int, human_overrides: int}  $current
     * @param  array{total: int, human_overrides: int}  $previous
     */
    private function precisionDelta(array $current, array $previous): ?float
    {
        $currentValue = $this->aiPrecision($current);
        $previousValue = $this->aiPrecision($previous);

        if ($currentValue === null || $previousValue === null) {
            return null;
        }

        return round($currentValue - $previousValue, 1);
    }

    /**
     * Mean remaining SLA budget across the currently open critical incidents,
     * using the same SLA chain as the inbox presenter.
     */
    private function avgCriticalSlaRemaining(Team $team): ?int
    {
        $now = Carbon::now();

        $critical = Incident::query()
            ->where('team_id', $team->id)
            ->open()
            ->whereHas('priority', fn ($query) => $query->where('code', 'critical'))
            ->with(['priority', 'relatedEvent.eventSeverity', 'status'])
            ->get();

        if ($critical->isEmpty()) {
            return null;
        }

        $remaining = $critical->map(function (Incident $incident) use ($now): int {
            $budget = (int) (($incident->priority?->sla_seconds
                ?? $incident->relatedEvent?->eventSeverity?->response_sla_seconds)
                ?: 1800);

            $elapsed = $incident->opened_at !== null
                ? (int) $incident->opened_at->diffInSeconds($now)
                : 0;

            return $budget - $elapsed;
        });

        return (int) round($remaining->avg());
    }

    /**
     * Top open incidents in inbox-row shape (IncidentInboxPresenter::toRow).
     *
     * @return list<array<string, mixed>>
     */
    private function openIncidents(Team $team): array
    {
        $now = Carbon::now();

        $relations = [
            'status',
            'priority',
            'type',
            'asset',
            'driver',
            'relatedEvent.provider',
            'relatedEvent.eventType',
            'relatedEvent.eventSeverity',
            'aiEvaluation',
            'currentAssignment',
        ];

        // Critical incidents always make the panel: fill the top-5 with
        // criticals first, then the most recent of the rest.
        $critical = Incident::query()
            ->where('team_id', $team->id)
            ->open()
            ->whereHas('priority', fn ($query) => $query->where('code', 'critical'))
            ->with($relations)
            ->orderByDesc('opened_at')
            ->limit(5)
            ->get();

        $incidents = $critical;

        if ($critical->count() < 5) {
            $rest = Incident::query()
                ->where('team_id', $team->id)
                ->open()
                ->whereNotIn('id', $critical->pluck('id'))
                ->with($relations)
                ->orderByDesc('opened_at')
                ->limit(5 - $critical->count())
                ->get();

            $incidents = $critical->concat($rest);
        }

        $users = $this->assigneeUsers($incidents);

        return $incidents
            ->map(fn (Incident $incident) => $this->presenter->toRow($incident, $users, $now))
            ->all();
    }

    /**
     * @param  Collection<int, Incident>  $incidents
     * @return Collection<int, User>
     */
    private function assigneeUsers(Collection $incidents): Collection
    {
        $userIds = $incidents
            ->map(fn (Incident $incident) => $incident->currentAssignment)
            ->filter(fn ($assignment) => $assignment !== null
                && $assignment->assigned_to_type === AssigneeType::User)
            ->map(fn ($assignment) => (int) $assignment->assigned_to_id)
            ->unique()
            ->values();

        if ($userIds->isEmpty()) {
            return collect();
        }

        return User::query()->whereIn('id', $userIds)->get()->keyBy('id');
    }

    /**
     * Latest normalized events with the decision the engine took on each.
     *
     * @return list<array<string, mixed>>
     */
    private function stream(Team $team): array
    {
        $events = NormalizedEvent::query()
            ->where('team_id', $team->id)
            ->with(['provider', 'eventType', 'eventSeverity', 'asset'])
            ->orderByDesc('occurred_at')
            ->limit(8)
            ->get();

        $decisions = Decision::query()
            ->where('team_id', $team->id)
            ->whereIn('normalized_event_id', $events->pluck('id'))
            ->orderByDesc('decided_at')
            ->get()
            ->unique('normalized_event_id')
            ->keyBy('normalized_event_id');

        return $events
            ->map(fn (NormalizedEvent $event) => $this->presentStreamEvent(
                $event,
                $decisions->get($event->id),
            ))
            ->all();
    }

    /**
     * @return array<string, mixed>
     */
    private function presentStreamEvent(NormalizedEvent $event, ?Decision $decision): array
    {
        return [
            'id' => (int) $event->id,
            'ts' => $event->occurred_at?->format('H:i:s') ?? '—',
            'provider' => (string) ($event->provider?->name ?? '—'),
            'type' => (string) ($event->eventType?->code ?? '—'),
            'asset' => (string) ($event->asset?->code ?? $event->asset?->name ?? '—'),
            'decision' => $this->decisionChip($decision),
            'severity' => $this->severityKey($event),
        ];
    }

    /**
     * Collapses the decision engine outcome into the 4-value chip vocabulary
     * the dashboard stream renders.
     */
    private function decisionChip(?Decision $decision): string
    {
        $code = $decision?->decision_code !== null
            ? DecisionOutcomeCode::tryFrom($decision->decision_code)
            : null;

        return match ($code) {
            DecisionOutcomeCode::Incident => 'incident',
            DecisionOutcomeCode::Escalate,
            DecisionOutcomeCode::RequireHumanReview => 'escalate',
            DecisionOutcomeCode::Ignore => 'discard',
            DecisionOutcomeCode::Alert,
            DecisionOutcomeCode::LogOnly => 'info',
            null => 'info',
        };
    }

    private function severityKey(NormalizedEvent $event): ?string
    {
        return match ($event->eventSeverity?->code) {
            'critical' => 'critical',
            'high' => 'high',
            'medium' => 'medium',
            'low' => 'low',
            'info' => 'info',
            default => null,
        };
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function integrations(Team $team): array
    {
        $now = Carbon::now();

        $integrations = TenantIntegration::query()
            ->with('provider')
            ->where('team_id', $team->id)
            ->orderByDesc('id')
            ->get();

        $counts = $this->eventStats->countByProviderSince($team->id, $now->copy()->subDay());

        return $integrations
            ->map(fn (TenantIntegration $integration) => [
                'id' => (int) $integration->id,
                'key' => (string) ($integration->provider?->code ?? $integration->id),
                'name' => (string) ($integration->provider?->name ?? $integration->name),
                'health' => $integration->status->healthKey(),
                'events24h' => (int) ($counts[$integration->provider_id] ?? 0),
                'lastSync' => $integration->last_sync_at !== null
                    ? $this->relativeTime($integration->last_sync_at, $now)
                    : null,
            ])
            ->all();
    }

    /**
     * Usage counters for the billing period containing today.
     *
     * @return list<array<string, mixed>>
     */
    private function usage(Team $team): array
    {
        $today = Carbon::today();

        return TenantUsageCounter::query()
            ->where('team_id', $team->id)
            ->whereDate('period_start', '<=', $today)
            ->whereDate('period_end', '>=', $today)
            ->with('usageMeter')
            ->get()
            ->sortBy(fn (TenantUsageCounter $counter) => (string) $counter->usageMeter?->name)
            ->values()
            ->map(fn (TenantUsageCounter $counter) => [
                'meterCode' => (string) ($counter->usageMeter?->code ?? ''),
                'meterName' => (string) ($counter->usageMeter?->name ?? '—'),
                'unit' => (string) ($counter->usageMeter?->unit ?? ''),
                'consumed' => (int) $counter->consumed_value,
                'included' => (int) $counter->included_value,
                'overage' => (int) $counter->overage_value,
                'percentUsed' => $counter->included_value > 0
                    ? round($counter->consumed_value / $counter->included_value * 100, 1)
                    : null,
                'periodEnd' => $counter->period_end?->toDateString(),
            ])
            ->all();
    }

    private function relativeTime(CarbonInterface $time, CarbonInterface $now): string
    {
        $seconds = (int) $time->diffInSeconds($now);

        if ($seconds < 60) {
            return "hace {$seconds} s";
        }

        $minutes = intdiv($seconds, 60);

        if ($minutes < 60) {
            return "hace {$minutes} min";
        }

        $hours = intdiv($minutes, 60);

        if ($hours < 24) {
            return "hace {$hours} h";
        }

        $days = intdiv($hours, 24);

        return "hace {$days} d";
    }
}
