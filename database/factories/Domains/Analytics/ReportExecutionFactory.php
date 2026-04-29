<?php

namespace Database\Factories\Domains\Analytics;

use App\Domains\Analytics\Enums\ReportExecutionStatus;
use App\Domains\Analytics\Enums\ReportOutputFormat;
use App\Domains\Analytics\Enums\ReportRequestedByType;
use App\Domains\Analytics\Models\ReportDefinition;
use App\Domains\Analytics\Models\ReportExecution;
use App\Models\Team;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<ReportExecution>
 */
class ReportExecutionFactory extends Factory
{
    protected $model = ReportExecution::class;

    public function definition(): array
    {
        return [
            'report_definition_id' => ReportDefinition::factory(),
            'team_id' => Team::factory(),
            'requested_by_type' => ReportRequestedByType::User,
            'requested_by_id' => null,
            'filters_json' => null,
            'status' => ReportExecutionStatus::Pending,
            'output_format' => ReportOutputFormat::Json,
            'file_path' => null,
            'result_snapshot_json' => null,
            'error_message' => null,
            'started_at' => null,
            'finished_at' => null,
        ];
    }

    public function completed(): static
    {
        return $this->state(fn () => [
            'status' => ReportExecutionStatus::Completed,
            'started_at' => now()->subMinute(),
            'finished_at' => now(),
        ]);
    }

    public function expired(): static
    {
        return $this->state(fn () => [
            'status' => ReportExecutionStatus::Expired,
            'finished_at' => now()->subDays(120),
        ]);
    }
}
