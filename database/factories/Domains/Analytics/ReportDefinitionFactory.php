<?php

namespace Database\Factories\Domains\Analytics;

use App\Domains\Analytics\Enums\ReportType;
use App\Domains\Analytics\Models\ReportDefinition;
use App\Models\Team;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<ReportDefinition>
 */
class ReportDefinitionFactory extends Factory
{
    protected $model = ReportDefinition::class;

    public function definition(): array
    {
        $code = 'report_'.fake()->unique()->numerify('######');

        return [
            'team_id' => null,
            'code' => $code,
            'name' => 'Report '.$code,
            'description' => fake()->sentence(),
            'report_type' => ReportType::Operational,
            'data_sources_json' => ['kpi_records'],
            'filters_schema_json' => ['period' => 'daily'],
            'metrics_json' => ['incidents_total'],
            'visualization_config_json' => ['layout' => 'table'],
            'schedule_config_json' => null,
            'is_active' => true,
        ];
    }

    public function forTeam(Team $team): static
    {
        return $this->state(fn () => ['team_id' => $team->id]);
    }

    public function weeklyMonday(): static
    {
        return $this->state(fn () => [
            'schedule_config_json' => [
                'frequency' => 'weekly',
                'day_of_week' => 'monday',
                'time' => '08:00',
                'timezone' => 'UTC',
            ],
        ]);
    }

    public function inactive(): static
    {
        return $this->state(fn () => ['is_active' => false]);
    }
}
