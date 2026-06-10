<?php

namespace Tests\Feature\Domains\Analytics;

use App\Contracts\Decisions\DecisionMetricsQuery;
use App\Contracts\Incidents\IncidentMetricsQuery;
use App\Domains\AI\Enums\EventClassification;
use App\Domains\AI\Models\AIEventEvaluation;
use App\Domains\Analytics\Actions\CalculateKPIsForTenant;
use App\Domains\Analytics\Enums\PeriodType;
use App\Domains\Analytics\Events\KPIsCalculated;
use App\Domains\Analytics\Models\KpiRecord;
use App\Domains\Analytics\Models\MetricDefinition;
use App\Domains\Normalization\Models\NormalizedEvent;
use App\Models\Team;
use Carbon\CarbonInterface;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use Tests\TestCase;

class CalculateKPIsForTenantTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->bindFakeIncidentMetrics([
            'total' => 12,
            'resolved' => 10,
            'open' => 2,
            'mean_resolution_time_minutes' => 45.5,
            'escalations' => 1,
        ]);
        $this->bindFakeDecisionMetrics([
            'total' => 8,
            'human_reviewed' => 4,
            'human_overrides' => 1,
            'auto_resolved' => 4,
        ]);
    }

    public function test_action_persists_kpi_records_and_dispatches_event(): void
    {
        Event::fake([KPIsCalculated::class]);

        $team = Team::factory()->create();

        MetricDefinition::factory()->create([
            'code' => 'incidents_total',
            'unit' => 'count',
        ]);
        MetricDefinition::factory()->create([
            'code' => 'incidents_resolved',
            'unit' => 'count',
        ]);

        $start = now()->subDay()->startOfDay();
        $end = now()->subDay()->endOfDay();

        $count = app(CalculateKPIsForTenant::class)->execute($team->id, $start, $end);

        $this->assertGreaterThanOrEqual(2, $count);
        $this->assertSame(
            12.0,
            (float) KpiRecord::withoutGlobalScopes()
                ->where('team_id', $team->id)
                ->where('kpi_code', 'incidents_total')
                ->value('value'),
        );
        $this->assertSame(
            10.0,
            (float) KpiRecord::withoutGlobalScopes()
                ->where('team_id', $team->id)
                ->where('kpi_code', 'incidents_resolved')
                ->value('value'),
        );

        Event::assertDispatched(KPIsCalculated::class, fn (KPIsCalculated $e) => $e->teamId === $team->id);
    }

    public function test_kpi_calculation_is_reproducible(): void
    {
        $team = Team::factory()->create();

        MetricDefinition::factory()->create([
            'code' => 'incidents_total',
            'unit' => 'count',
        ]);

        $start = now()->subDay()->startOfDay();
        $end = now()->subDay()->endOfDay();

        $action = app(CalculateKPIsForTenant::class);
        $action->execute($team->id, $start, $end);
        $action->execute($team->id, $start, $end);

        $records = KpiRecord::withoutGlobalScopes()
            ->where('team_id', $team->id)
            ->where('kpi_code', 'incidents_total')
            ->where('period_type', PeriodType::Daily)
            ->get();

        $this->assertCount(1, $records, 'Re-running must upsert, not duplicate');
        $this->assertSame(12.0, (float) $records->first()->value);
    }

    public function test_ai_effectiveness_kpis_use_evaluation_classifications(): void
    {
        $team = Team::factory()->create();
        $start = now()->subDay()->startOfDay();
        $end = now()->subDay()->endOfDay();

        for ($i = 0; $i < 3; $i++) {
            $realEvent = NormalizedEvent::factory()->create(['team_id' => $team->id]);
            AIEventEvaluation::factory()->create([
                'team_id' => $team->id,
                'normalized_event_id' => $realEvent->id,
                'classification' => EventClassification::RealEvent,
                'confidence_score' => 0.9,
                'evaluated_at' => $start->copy()->addHours(2),
            ]);
        }

        $fpEvent = NormalizedEvent::factory()->create(['team_id' => $team->id]);
        AIEventEvaluation::factory()->create([
            'team_id' => $team->id,
            'normalized_event_id' => $fpEvent->id,
            'classification' => EventClassification::FalsePositive,
            'confidence_score' => 0.4,
            'evaluated_at' => $start->copy()->addHours(3),
        ]);

        app(CalculateKPIsForTenant::class)->execute($team->id, $start, $end);

        $fpRate = (float) KpiRecord::withoutGlobalScopes()
            ->where('team_id', $team->id)
            ->where('kpi_code', 'ai_false_positive_rate')
            ->value('value');

        $this->assertSame(0.25, $fpRate);
    }

    private function bindFakeIncidentMetrics(array $totals): void
    {
        $this->app->bind(IncidentMetricsQuery::class, function () use ($totals) {
            return new class($totals) implements IncidentMetricsQuery
            {
                public function __construct(private array $totals) {}

                public function totalsForTenant(int $teamId, CarbonInterface $from, CarbonInterface $to): array
                {
                    return $this->totals;
                }

                public function openCounts(int $teamId): array
                {
                    return ['open' => 0, 'critical_open' => 0];
                }

                public function openedPerDay(int $teamId, CarbonInterface $from, CarbonInterface $to): array
                {
                    return [];
                }

                public function slaCompliance(int $teamId, CarbonInterface $from, CarbonInterface $to): ?float
                {
                    return null;
                }
            };
        });
    }

    private function bindFakeDecisionMetrics(array $totals): void
    {
        $this->app->bind(DecisionMetricsQuery::class, function () use ($totals) {
            return new class($totals) implements DecisionMetricsQuery
            {
                public function __construct(private array $totals) {}

                public function totalsForTenant(int $teamId, CarbonInterface $from, CarbonInterface $to): array
                {
                    return $this->totals;
                }
            };
        });
    }
}
