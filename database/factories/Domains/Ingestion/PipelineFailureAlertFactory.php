<?php

namespace Database\Factories\Domains\Ingestion;

use App\Domains\Ingestion\Models\PipelineFailureAlert;
use App\Models\Team;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<PipelineFailureAlert>
 */
class PipelineFailureAlertFactory extends Factory
{
    protected $model = PipelineFailureAlert::class;

    public function definition(): array
    {
        return [
            'team_id' => Team::factory(),
            'dedup_key' => 'test:'.Str::uuid()->toString(),
            'kind' => PipelineFailureAlert::KIND_JOB_FAILED,
            'stage' => 'normalize_event',
            'raw_event_id' => null,
            'normalized_event_id' => null,
            'event_type_code' => null,
            'asset_id' => null,
            'is_emergency' => false,
            'error_json' => null,
            'platform_recipients' => 0,
            'tenant_recipients' => 0,
            'notified_at' => now(),
        ];
    }
}
