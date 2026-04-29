<?php

namespace App\Domains\TenantConfig\Actions;

use App\Contracts\TenantConfig\TenantRuleOverrideApplier;
use App\Domains\TenantConfig\Data\ResolvedRule;
use App\Domains\TenantConfig\Enums\RuleOverrideType;
use App\Domains\TenantConfig\Models\TenantRuleOverride;

class ApplyTenantRuleOverrides implements TenantRuleOverrideApplier
{
    /**
     * @param  array<string, mixed>  $baseParameters
     */
    public function apply(int $teamId, string $baseRuleCode, array $baseParameters = []): ResolvedRule
    {
        $overrides = TenantRuleOverride::withoutGlobalScopes()
            ->where('team_id', $teamId)
            ->where('base_rule_code', $baseRuleCode)
            ->where('is_active', true)
            ->get()
            ->sortByDesc(fn (TenantRuleOverride $override): int => $override->override_type->specificity());

        $disabled = false;
        $forceHumanReview = false;
        $priority = null;
        $outcome = null;
        $escalationPolicyCode = null;
        $parameters = $baseParameters;
        $applied = [];

        foreach ($overrides as $override) {
            $config = $override->override_config_json ?? [];
            $applied[] = ['type' => $override->override_type, 'config' => $config];

            switch ($override->override_type) {
                case RuleOverrideType::DisableRule:
                    $disabled = true;
                    break;
                case RuleOverrideType::ForceHumanReview:
                    $forceHumanReview = true;
                    break;
                case RuleOverrideType::ChangeThreshold:
                    $parameters = array_merge($parameters, $config);
                    break;
                case RuleOverrideType::ChangePriority:
                    $priority = $config['priority'] ?? $priority;
                    break;
                case RuleOverrideType::ChangeOutcome:
                    $outcome = $config['outcome'] ?? $outcome;
                    break;
                case RuleOverrideType::ReplaceEscalationPolicy:
                    $escalationPolicyCode = $config['escalation_policy_code'] ?? $escalationPolicyCode;
                    break;
            }
        }

        return new ResolvedRule(
            baseRuleCode: $baseRuleCode,
            disabled: $disabled,
            forceHumanReview: $forceHumanReview,
            priority: $priority,
            outcome: $outcome,
            escalationPolicyCode: $escalationPolicyCode,
            parameters: $parameters,
            appliedOverrides: $applied,
        );
    }
}
