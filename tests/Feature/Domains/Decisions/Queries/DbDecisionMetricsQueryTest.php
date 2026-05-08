<?php

namespace Tests\Feature\Domains\Decisions\Queries;

use App\Contracts\Decisions\DecisionMetricsQuery;
use App\Domains\AI\Models\AIEventEvaluation;
use App\Domains\Decisions\Models\Decision;
use App\Domains\Decisions\Models\DecisionOverride;
use App\Domains\Decisions\Queries\DbDecisionMetricsQuery;
use App\Domains\Normalization\Models\NormalizedEvent;
use App\Models\Team;
use App\Models\User;
use Carbon\CarbonInterface;
use Database\Seeders\DecisionOutcomeSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class DbDecisionMetricsQueryTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(DecisionOutcomeSeeder::class);
    }

    public function test_binding_resolves_to_db_implementation(): void
    {
        $this->assertInstanceOf(DbDecisionMetricsQuery::class, app(DecisionMetricsQuery::class));
    }

    public function test_totals_aggregate_decisions_in_window(): void
    {
        $team = Team::factory()->create();
        $start = now()->subDay()->startOfDay();
        $end = now()->subDay()->endOfDay();

        $this->makeDecision($team->id, decidedAt: $start->copy()->addHour(), automated: true, requiresHumanReview: false);
        $this->makeDecision($team->id, decidedAt: $start->copy()->addHours(3), automated: true, requiresHumanReview: false);

        $reviewed = $this->makeDecision($team->id, decidedAt: $start->copy()->addHours(5), automated: false, requiresHumanReview: true);
        DecisionOverride::factory()->create([
            'decision_id' => $reviewed->id,
        ]);

        // Decision outside the window — must not be counted.
        $this->makeDecision($team->id, decidedAt: $start->copy()->subDays(3));

        $totals = app(DbDecisionMetricsQuery::class)->totalsForTenant($team->id, $start, $end);

        $this->assertSame([
            'total' => 3,
            'human_reviewed' => 1,
            'human_overrides' => 1,
            'auto_resolved' => 2,
        ], $totals);
    }

    public function test_returns_zeroes_when_no_decisions_in_window(): void
    {
        $team = Team::factory()->create();
        $start = now()->subDay()->startOfDay();
        $end = now()->subDay()->endOfDay();

        $totals = app(DbDecisionMetricsQuery::class)->totalsForTenant($team->id, $start, $end);

        $this->assertSame([
            'total' => 0,
            'human_reviewed' => 0,
            'human_overrides' => 0,
            'auto_resolved' => 0,
        ], $totals);
    }

    public function test_results_are_isolated_by_tenant(): void
    {
        $teamA = Team::factory()->create();
        $teamB = Team::factory()->create();
        $start = now()->subDay()->startOfDay();
        $end = now()->subDay()->endOfDay();

        $this->makeDecision($teamA->id, decidedAt: $start->copy()->addHour());
        $this->makeDecision($teamA->id, decidedAt: $start->copy()->addHours(2));

        for ($i = 0; $i < 3; $i++) {
            $other = $this->makeDecision($teamB->id, decidedAt: $start->copy()->addHours(2 + $i));
            DecisionOverride::factory()->create(['decision_id' => $other->id]);
        }

        $query = app(DbDecisionMetricsQuery::class);

        $aTotals = $query->totalsForTenant($teamA->id, $start, $end);
        $bTotals = $query->totalsForTenant($teamB->id, $start, $end);

        $this->assertSame(2, $aTotals['total']);
        $this->assertSame(0, $aTotals['human_overrides']);
        $this->assertSame(3, $bTotals['total']);
        $this->assertSame(3, $bTotals['human_overrides']);
    }

    public function test_query_works_outside_authenticated_context(): void
    {
        $team = Team::factory()->create();
        $start = now()->subDay()->startOfDay();
        $end = now()->subDay()->endOfDay();

        $this->makeDecision($team->id, decidedAt: $start->copy()->addHour());

        // No actingAs(): the query must use withoutGlobalScopes() so that scheduled
        // jobs without a session can still aggregate KPIs per tenant.
        $totals = app(DbDecisionMetricsQuery::class)->totalsForTenant($team->id, $start, $end);

        $this->assertSame(1, $totals['total']);
    }

    public function test_query_does_not_leak_to_currentteam_scope(): void
    {
        $userA = User::factory()->create();
        $userB = User::factory()->create();
        $start = now()->subDay()->startOfDay();
        $end = now()->subDay()->endOfDay();

        $this->makeDecision($userA->currentTeam->id, decidedAt: $start->copy()->addHour());
        $this->makeDecision($userB->currentTeam->id, decidedAt: $start->copy()->addHours(2));
        $this->makeDecision($userB->currentTeam->id, decidedAt: $start->copy()->addHours(3));

        $this->actingAs($userA);

        $totalsForB = app(DbDecisionMetricsQuery::class)->totalsForTenant(
            $userB->currentTeam->id,
            $start,
            $end,
        );

        $this->assertSame(2, $totalsForB['total']);
    }

    private function makeDecision(
        int $teamId,
        ?CarbonInterface $decidedAt = null,
        bool $automated = true,
        bool $requiresHumanReview = false,
    ): Decision {
        $event = NormalizedEvent::factory()->create(['team_id' => $teamId]);
        $evaluation = AIEventEvaluation::factory()->create([
            'team_id' => $teamId,
            'normalized_event_id' => $event->id,
        ]);

        return Decision::factory()->create([
            'team_id' => $teamId,
            'normalized_event_id' => $event->id,
            'ai_evaluation_id' => $evaluation->id,
            'is_automated' => $automated,
            'requires_human_review' => $requiresHumanReview,
            'decided_at' => $decidedAt ?? now(),
        ]);
    }
}
