<?php

namespace Database\Factories\Domains\Decisions;

use App\Domains\AI\Models\AIEventEvaluation;
use App\Domains\Decisions\Enums\DecisionOutcomeCode;
use App\Domains\Decisions\Enums\DecisionPriority;
use App\Domains\Decisions\Models\Decision;
use App\Domains\Decisions\Models\DecisionOutcome;
use App\Domains\Normalization\Models\NormalizedEvent;
use App\Models\Team;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Decision>
 */
class DecisionFactory extends Factory
{
    protected $model = Decision::class;

    public function definition(): array
    {
        return [
            'normalized_event_id' => NormalizedEvent::factory(),
            'team_id' => Team::factory(),
            'ai_evaluation_id' => AIEventEvaluation::factory(),
            'ruleset_id' => null,
            'decision_code' => DecisionOutcomeCode::LogOnly->value,
            'decision_reason' => 'Default factory decision',
            'priority_level' => DecisionPriority::Normal,
            'requires_human_review' => false,
            'is_automated' => true,
            'escalation_policy_id' => null,
            'outcome_id' => fn () => DecisionOutcome::firstOrCreate(
                ['code' => DecisionOutcomeCode::LogOnly->value],
                ['name' => 'Log Only', 'is_terminal' => true],
            )->id,
            'context_snapshot_id' => null,
            'decided_at' => now(),
        ];
    }
}
