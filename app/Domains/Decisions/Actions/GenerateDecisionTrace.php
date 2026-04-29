<?php

namespace App\Domains\Decisions\Actions;

use App\Domains\Decisions\Enums\DecisionSourceType;
use App\Domains\Decisions\Models\Decision;
use App\Domains\Decisions\Models\DecisionTrace;

class GenerateDecisionTrace
{
    /**
     * @param  array<int, array{source_type: DecisionSourceType, rule_code?: ?string, source_reference_id?: ?int, input?: array<string, mixed>, output?: array<string, mixed>, explanation?: ?string}>  $steps
     */
    public function execute(Decision $decision, array $steps): void
    {
        $order = 1;

        foreach ($steps as $step) {
            DecisionTrace::create([
                'decision_id' => $decision->id,
                'rule_code' => $step['rule_code'] ?? null,
                'source_type' => $step['source_type'],
                'source_reference_id' => $step['source_reference_id'] ?? null,
                'step_order' => $order++,
                'input_fragment_json' => $step['input'] ?? [],
                'output_fragment_json' => $step['output'] ?? [],
                'explanation' => $step['explanation'] ?? null,
            ]);
        }
    }
}
