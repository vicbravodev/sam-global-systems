<?php

namespace Database\Factories\Domains\Analytics;

use App\Domains\Analytics\Enums\SnapshotType;
use App\Domains\Analytics\Models\AnalyticsSnapshot;
use App\Models\Team;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<AnalyticsSnapshot>
 */
class AnalyticsSnapshotFactory extends Factory
{
    protected $model = AnalyticsSnapshot::class;

    public function definition(): array
    {
        $start = now()->subDay()->startOfDay();

        return [
            'team_id' => Team::factory(),
            'snapshot_type' => SnapshotType::TenantOverview,
            'entity_type' => null,
            'entity_id' => null,
            'period_start' => $start->toDateString(),
            'period_end' => $start->toDateString(),
            'snapshot_json' => [
                'total_incidents' => 0,
                'resolved_incidents' => 0,
                'mean_resolution_time_minutes' => 0,
                'ai_accuracy_rate' => 0,
                'active_assets' => 0,
                'active_integrations' => 0,
                'usage_summary' => [],
            ],
        ];
    }
}
