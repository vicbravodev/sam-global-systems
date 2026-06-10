<?php

namespace Tests\Feature\Domains\Incidents\Queries;

use App\Contracts\Incidents\IncidentMetricsQuery;
use App\Domains\Incidents\Enums\TimelineActorType;
use App\Domains\Incidents\Enums\TimelineEntryType;
use App\Domains\Incidents\Models\Incident;
use App\Domains\Incidents\Models\IncidentPriority;
use App\Domains\Incidents\Models\IncidentTimeline;
use App\Domains\Incidents\Queries\DbIncidentMetricsQuery;
use App\Models\Team;
use Carbon\CarbonInterface;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class DbIncidentMetricsQueryTest extends TestCase
{
    use RefreshDatabase;

    public function test_binding_resolves_to_db_implementation(): void
    {
        $this->assertInstanceOf(DbIncidentMetricsQuery::class, app(IncidentMetricsQuery::class));
    }

    public function test_totals_aggregate_incidents_in_window(): void
    {
        $team = Team::factory()->create();
        $start = now()->subDay()->startOfDay();
        $end = now()->subDay()->endOfDay();

        Incident::factory()
            ->open()
            ->count(2)
            ->create([
                'team_id' => $team->id,
                'opened_at' => $start->copy()->addHour(),
            ]);

        $resolved = Incident::factory()
            ->resolved()
            ->create([
                'team_id' => $team->id,
                'opened_at' => $start->copy()->addHours(2),
                'resolved_at' => $start->copy()->addHours(2)->addMinutes(30),
            ]);

        Incident::factory()
            ->resolved()
            ->create([
                'team_id' => $team->id,
                'opened_at' => $start->copy()->addHours(4),
                'resolved_at' => $start->copy()->addHours(4)->addMinutes(60),
            ]);

        // Out-of-window incident: must not affect totals or MTTR.
        Incident::factory()->open()->create([
            'team_id' => $team->id,
            'opened_at' => $start->copy()->subDays(3),
        ]);

        IncidentTimeline::factory()->create([
            'incident_id' => $resolved->id,
            'entry_type' => TimelineEntryType::Escalated->value,
            'actor_type' => TimelineActorType::System->value,
            'occurred_at' => $start->copy()->addHours(2)->addMinutes(10),
        ]);

        $totals = app(DbIncidentMetricsQuery::class)->totalsForTenant($team->id, $start, $end);

        $this->assertSame(4, $totals['total']);
        $this->assertSame(2, $totals['resolved']);
        $this->assertSame(2, $totals['open']);
        $this->assertSame(45.0, $totals['mean_resolution_time_minutes']);
        $this->assertSame(1, $totals['escalations']);
    }

    public function test_returns_zero_metrics_when_window_is_empty(): void
    {
        $team = Team::factory()->create();
        $start = now()->subDay()->startOfDay();
        $end = now()->subDay()->endOfDay();

        $totals = app(DbIncidentMetricsQuery::class)->totalsForTenant($team->id, $start, $end);

        $this->assertSame([
            'total' => 0,
            'resolved' => 0,
            'open' => 0,
            'mean_resolution_time_minutes' => 0.0,
            'escalations' => 0,
        ], $totals);
    }

    public function test_results_are_isolated_by_tenant(): void
    {
        $teamA = Team::factory()->create();
        $teamB = Team::factory()->create();
        $start = now()->subDay()->startOfDay();
        $end = now()->subDay()->endOfDay();

        Incident::factory()->open()->count(2)->create([
            'team_id' => $teamA->id,
            'opened_at' => $start->copy()->addHour(),
        ]);

        $bIncidents = Incident::factory()->resolved()->count(3)->create([
            'team_id' => $teamB->id,
            'opened_at' => $start->copy()->addHour(),
            'resolved_at' => $start->copy()->addHour()->addMinutes(15),
        ]);

        IncidentTimeline::factory()->create([
            'incident_id' => $bIncidents->first()->id,
            'entry_type' => TimelineEntryType::Escalated->value,
            'actor_type' => TimelineActorType::System->value,
            'occurred_at' => $start->copy()->addHour()->addMinutes(5),
        ]);

        $query = app(DbIncidentMetricsQuery::class);

        $aTotals = $query->totalsForTenant($teamA->id, $start, $end);
        $bTotals = $query->totalsForTenant($teamB->id, $start, $end);

        $this->assertSame(2, $aTotals['total']);
        $this->assertSame(0, $aTotals['resolved']);
        $this->assertSame(0, $aTotals['escalations']);

        $this->assertSame(3, $bTotals['total']);
        $this->assertSame(3, $bTotals['resolved']);
        $this->assertSame(15.0, $bTotals['mean_resolution_time_minutes']);
        $this->assertSame(1, $bTotals['escalations']);
    }

    public function test_escalations_count_only_inside_window(): void
    {
        $team = Team::factory()->create();
        $start = now()->subDay()->startOfDay();
        $end = now()->subDay()->endOfDay();

        $incident = Incident::factory()->open()->create([
            'team_id' => $team->id,
            'opened_at' => $start->copy()->addHour(),
        ]);

        // In-window escalation.
        IncidentTimeline::factory()->create([
            'incident_id' => $incident->id,
            'entry_type' => TimelineEntryType::Escalated->value,
            'actor_type' => TimelineActorType::System->value,
            'occurred_at' => $start->copy()->addHours(2),
        ]);

        // Out-of-window escalation on the same incident.
        IncidentTimeline::factory()->create([
            'incident_id' => $incident->id,
            'entry_type' => TimelineEntryType::Escalated->value,
            'actor_type' => TimelineActorType::System->value,
            'occurred_at' => $start->copy()->subDays(2),
        ]);

        $totals = app(DbIncidentMetricsQuery::class)->totalsForTenant($team->id, $start, $end);

        $this->assertSame(1, $totals['escalations']);
    }

    public function test_open_counts_split_critical_from_total(): void
    {
        $team = Team::factory()->create();
        $critical = IncidentPriority::factory()->critical()->create();

        Incident::factory()->open()->count(2)->create([
            'team_id' => $team->id,
            'opened_at' => now()->subHour(),
        ]);
        Incident::factory()->open()->create([
            'team_id' => $team->id,
            'incident_priority_id' => $critical->id,
            'opened_at' => now()->subHour(),
        ]);
        Incident::factory()->resolved()->create([
            'team_id' => $team->id,
            'opened_at' => now()->subHours(2),
            'resolved_at' => now()->subHour(),
        ]);

        // Another tenant's open incident must not leak in.
        Incident::factory()->open()->create([
            'team_id' => Team::factory()->create()->id,
            'opened_at' => now()->subHour(),
        ]);

        $counts = app(DbIncidentMetricsQuery::class)->openCounts($team->id);

        $this->assertSame(['open' => 3, 'critical_open' => 1], $counts);
    }

    public function test_opened_per_day_fills_empty_buckets_with_zeroes(): void
    {
        $team = Team::factory()->create();
        $critical = IncidentPriority::factory()->critical()->create();
        $from = now()->subDays(2)->startOfDay();
        $to = now()->endOfDay();

        Incident::factory()->open()->count(2)->create([
            'team_id' => $team->id,
            'opened_at' => $from->copy()->addHours(3),
        ]);
        Incident::factory()->open()->create([
            'team_id' => $team->id,
            'incident_priority_id' => $critical->id,
            'opened_at' => now()->startOfDay()->addHour(),
        ]);

        $buckets = app(DbIncidentMetricsQuery::class)->openedPerDay($team->id, $from, $to);

        $this->assertCount(3, $buckets);
        $this->assertSame(
            ['date' => $from->toDateString(), 'total' => 2, 'critical' => 0],
            $buckets[0],
        );
        $this->assertSame(0, $buckets[1]['total']);
        $this->assertSame(
            ['date' => now()->toDateString(), 'total' => 1, 'critical' => 1],
            $buckets[2],
        );
    }

    public function test_sla_compliance_uses_priority_budget(): void
    {
        $team = Team::factory()->create();
        $from = now()->subDays(7);
        $to = now();

        // Factory default priority: sla_seconds = 3600.
        Incident::factory()->resolved()->create([
            'team_id' => $team->id,
            'opened_at' => now()->subHours(6),
            'resolved_at' => now()->subHours(6)->addMinutes(20),
        ]);
        Incident::factory()->resolved()->create([
            'team_id' => $team->id,
            'opened_at' => now()->subHours(9),
            'resolved_at' => now()->subHours(9)->addHours(2),
        ]);

        $compliance = app(DbIncidentMetricsQuery::class)->slaCompliance($team->id, $from, $to);

        $this->assertSame(50.0, $compliance);
    }

    public function test_sla_compliance_is_null_when_nothing_resolved_in_window(): void
    {
        $team = Team::factory()->create();

        Incident::factory()->open()->create([
            'team_id' => $team->id,
            'opened_at' => now()->subHour(),
        ]);

        $compliance = app(DbIncidentMetricsQuery::class)
            ->slaCompliance($team->id, now()->subDays(7), now());

        $this->assertNull($compliance);
    }

    public function test_window_filter_uses_opened_at(): void
    {
        $team = Team::factory()->create();
        $start = now()->subDay()->startOfDay();
        /** @var CarbonInterface $end */
        $end = now()->subDay()->endOfDay();

        Incident::factory()->resolved()->create([
            'team_id' => $team->id,
            'opened_at' => $start->copy()->subSecond(),
            'resolved_at' => $start->copy()->addMinutes(30),
        ]);

        $totals = app(DbIncidentMetricsQuery::class)->totalsForTenant($team->id, $start, $end);

        $this->assertSame(0, $totals['total']);
    }
}
