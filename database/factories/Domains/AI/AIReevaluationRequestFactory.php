<?php

namespace Database\Factories\Domains\AI;

use App\Domains\AI\Enums\ReevaluationStatus;
use App\Domains\AI\Enums\ReevaluationTrigger;
use App\Domains\AI\Models\AIReevaluationRequest;
use App\Domains\Normalization\Models\NormalizedEvent;
use App\Models\Team;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<AIReevaluationRequest>
 */
class AIReevaluationRequestFactory extends Factory
{
    protected $model = AIReevaluationRequest::class;

    public function definition(): array
    {
        return [
            'normalized_event_id' => NormalizedEvent::factory(),
            'team_id' => Team::factory(),
            'trigger_type' => ReevaluationTrigger::ContextUpdated,
            'trigger_reference_id' => null,
            'reason' => null,
            'status' => ReevaluationStatus::Pending,
            'requested_at' => now(),
            'processed_at' => null,
        ];
    }
}
