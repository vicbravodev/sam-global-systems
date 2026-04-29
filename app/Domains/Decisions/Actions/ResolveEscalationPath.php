<?php

namespace App\Domains\Decisions\Actions;

use App\Domains\Decisions\Enums\DecisionOutcomeCode;
use App\Domains\Decisions\Events\EscalationTriggered;
use App\Domains\Decisions\Models\Decision;
use App\Domains\Decisions\Models\DecisionRule;
use App\Domains\Decisions\Models\EscalationPolicy;

class ResolveEscalationPath
{
    public function execute(Decision $decision, ?DecisionRule $sourceRule = null): ?EscalationPolicy
    {
        $policy = null;

        if ($sourceRule?->escalation_policy_id) {
            $policy = EscalationPolicy::withoutGlobalScopes()
                ->where('id', $sourceRule->escalation_policy_id)
                ->where('team_id', $decision->team_id)
                ->where('is_active', true)
                ->first();
        }

        if ($policy === null && $decision->decision_code === DecisionOutcomeCode::Escalate->value) {
            $policy = EscalationPolicy::withoutGlobalScopes()
                ->where('team_id', $decision->team_id)
                ->where('is_active', true)
                ->orderBy('id')
                ->first();
        }

        if ($policy === null) {
            return null;
        }

        $decision->escalation_policy_id = $policy->id;
        $decision->save();

        EscalationTriggered::dispatch($decision, $policy);

        return $policy;
    }
}
