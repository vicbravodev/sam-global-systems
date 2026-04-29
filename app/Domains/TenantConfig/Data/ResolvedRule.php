<?php

namespace App\Domains\TenantConfig\Data;

use App\Domains\TenantConfig\Enums\RuleOverrideType;

/**
 * The state of a base decision rule after applying any active tenant overrides.
 */
final readonly class ResolvedRule
{
    /**
     * @param  array<string, mixed>  $parameters
     * @param  array<int, array{type: RuleOverrideType, config: array<string, mixed>}>  $appliedOverrides
     */
    public function __construct(
        public string $baseRuleCode,
        public bool $disabled,
        public bool $forceHumanReview,
        public ?string $priority,
        public ?string $outcome,
        public ?string $escalationPolicyCode,
        public array $parameters,
        public array $appliedOverrides,
    ) {}
}
