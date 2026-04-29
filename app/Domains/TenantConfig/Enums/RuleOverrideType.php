<?php

namespace App\Domains\TenantConfig\Enums;

enum RuleOverrideType: string
{
    case DisableRule = 'disable_rule';
    case ChangeThreshold = 'change_threshold';
    case ChangePriority = 'change_priority';
    case ChangeOutcome = 'change_outcome';
    case ForceHumanReview = 'force_human_review';
    case ReplaceEscalationPolicy = 'replace_escalation_policy';

    /**
     * Specificity score used to deterministically order composable overrides
     * when multiple of them apply to the same base rule. Higher = applied first.
     */
    public function specificity(): int
    {
        return match ($this) {
            self::DisableRule => 100,
            self::ForceHumanReview => 80,
            self::ReplaceEscalationPolicy => 70,
            self::ChangeOutcome => 60,
            self::ChangePriority => 40,
            self::ChangeThreshold => 20,
        };
    }
}
