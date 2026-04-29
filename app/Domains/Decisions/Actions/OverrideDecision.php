<?php

namespace App\Domains\Decisions\Actions;

use App\Domains\Decisions\Enums\DecisionSourceType;
use App\Domains\Decisions\Events\DecisionOverridden;
use App\Domains\Decisions\Models\Decision;
use App\Domains\Decisions\Models\DecisionOutcome;
use App\Domains\Decisions\Models\DecisionOverride;
use App\Domains\Decisions\Models\DecisionTrace;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

class OverrideDecision
{
    public function execute(Decision $decision, User $user, string $newOutcomeCode, string $reason): DecisionOverride
    {
        $newOutcome = DecisionOutcome::where('code', $newOutcomeCode)->first();

        if ($newOutcome === null) {
            throw new InvalidArgumentException("Unknown decision outcome code: {$newOutcomeCode}");
        }

        return DB::transaction(function () use ($decision, $user, $newOutcome, $reason) {
            $previousCode = $decision->decision_code;

            $override = DecisionOverride::create([
                'decision_id' => $decision->id,
                'overridden_by_user_id' => $user->id,
                'previous_outcome' => $previousCode,
                'new_outcome' => $newOutcome->code,
                'reason' => $reason,
            ]);

            $decision->decision_code = $newOutcome->code;
            $decision->outcome_id = $newOutcome->id;
            $decision->is_automated = false;
            $decision->save();

            $nextOrder = ((int) DecisionTrace::where('decision_id', $decision->id)->max('step_order')) + 1;

            DecisionTrace::create([
                'decision_id' => $decision->id,
                'rule_code' => null,
                'source_type' => DecisionSourceType::ManualOverride,
                'source_reference_id' => $override->id,
                'step_order' => $nextOrder,
                'input_fragment_json' => ['previous_outcome' => $previousCode],
                'output_fragment_json' => ['new_outcome' => $newOutcome->code],
                'explanation' => 'Manual override by user '.$user->id.': '.$reason,
            ]);

            DecisionOverridden::dispatch($override, $decision);

            return $override;
        });
    }
}
