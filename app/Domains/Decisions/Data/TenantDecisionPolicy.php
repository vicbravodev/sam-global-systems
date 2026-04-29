<?php

namespace App\Domains\Decisions\Data;

class TenantDecisionPolicy
{
    public function __construct(
        public readonly float $humanReviewConfidenceThreshold = 0.5,
        public readonly string $automationLevel = 'semi',
        public readonly string $defaultRuleSetCode = 'default',
        public readonly bool $allowAutomatedIncidents = true,
    ) {}
}
