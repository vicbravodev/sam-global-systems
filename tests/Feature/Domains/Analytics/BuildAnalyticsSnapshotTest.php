<?php

namespace Tests\Feature\Domains\Analytics;

use App\Contracts\Incidents\IncidentMetricsQuery;
use App\Domains\AI\Enums\EventClassification;
use App\Domains\AI\Models\AIEventEvaluation;
use App\Domains\Analytics\Actions\BuildAnalyticsSnapshot;
use App\Domains\Analytics\Enums\SnapshotType;
use App\Domains\Analytics\Models\AnalyticsSnapshot;
use App\Domains\Normalization\Models\NormalizedEvent;
use App\Models\Team;
use Carbon\CarbonInterface;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class BuildAnalyticsSnapshotTest extends TestCase
{
    use RefreshDatabase;

    public function test_tenant_overview_snapshot_aggregates_incidents_and_ai_metrics(): void
    {
        $team = Team::factory()->create();
        $start = now()->subDay()->startOfDay();
        $end = now()->subDay()->endOfDay();

        $this->app->bind(IncidentMetricsQuery::class, function () {
            return new class implements IncidentMetricsQuery
            {
                public function totalsForTenant(int $teamId, CarbonInterface $from, CarbonInterface $to): array
                {
                    return [
                        'total' => 142,
                        'resolved' => 128,
                        'open' => 14,
                        'mean_resolution_time_minutes' => 45.3,
                        'escalations' => 2,
                    ];
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

        for ($i = 0; $i < 9; $i++) {
            $event = NormalizedEvent::factory()->create(['team_id' => $team->id]);
            AIEventEvaluation::factory()->create([
                'team_id' => $team->id,
                'normalized_event_id' => $event->id,
                'classification' => EventClassification::RealEvent,
                'evaluated_at' => $start->copy()->addHour(),
            ]);
        }
        $fpEvent = NormalizedEvent::factory()->create(['team_id' => $team->id]);
        AIEventEvaluation::factory()->create([
            'team_id' => $team->id,
            'normalized_event_id' => $fpEvent->id,
            'classification' => EventClassification::FalsePositive,
            'evaluated_at' => $start->copy()->addHour(),
        ]);

        $snapshot = app(BuildAnalyticsSnapshot::class)->execute(
            $team->id,
            SnapshotType::TenantOverview,
            $start,
            $end,
        );

        $this->assertSame(SnapshotType::TenantOverview, $snapshot->snapshot_type);
        $this->assertSame(142, $snapshot->snapshot_json['total_incidents']);
        $this->assertSame(128, $snapshot->snapshot_json['resolved_incidents']);
        $this->assertSame(45.3, $snapshot->snapshot_json['mean_resolution_time_minutes']);
        $this->assertSame(0.9, $snapshot->snapshot_json['ai_accuracy_rate']);
    }

    public function test_snapshot_upserts_on_re_run(): void
    {
        $team = Team::factory()->create();
        $start = now()->subDay()->startOfDay();
        $end = now()->subDay()->endOfDay();

        $action = app(BuildAnalyticsSnapshot::class);
        $action->execute($team->id, SnapshotType::TenantOverview, $start, $end);
        $action->execute($team->id, SnapshotType::TenantOverview, $start, $end);

        $this->assertSame(
            1,
            AnalyticsSnapshot::withoutGlobalScopes()
                ->where('team_id', $team->id)
                ->where('snapshot_type', SnapshotType::TenantOverview->value)
                ->count(),
        );
    }
}
