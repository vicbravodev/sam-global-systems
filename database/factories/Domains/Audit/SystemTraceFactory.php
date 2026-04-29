<?php

namespace Database\Factories\Domains\Audit;

use App\Domains\Audit\Enums\TraceStatus;
use App\Domains\Audit\Models\SystemTrace;
use App\Models\Team;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<SystemTrace>
 */
class SystemTraceFactory extends Factory
{
    protected $model = SystemTrace::class;

    public function definition(): array
    {
        $startedAt = now();

        return [
            'trace_id' => Str::uuid()->toString(),
            'span_id' => Str::uuid()->toString(),
            'parent_span_id' => null,
            'team_id' => Team::factory(),
            'module_name' => 'audit',
            'operation_name' => 'noop',
            'status' => TraceStatus::Started,
            'started_at' => $startedAt,
            'finished_at' => null,
            'duration_ms' => null,
            'input_reference_json' => [],
            'output_reference_json' => [],
            'error_message' => null,
            'metadata_json' => [],
        ];
    }

    public function completed(): static
    {
        return $this->state(function (array $attributes): array {
            $finishedAt = now()->addMilliseconds(50);

            return [
                'status' => TraceStatus::Completed,
                'finished_at' => $finishedAt,
                'duration_ms' => 50,
            ];
        });
    }
}
