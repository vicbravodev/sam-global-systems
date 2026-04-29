<?php

namespace App\Contracts\TenantConfig;

use App\Domains\TenantConfig\Data\ResolvedRule;

interface TenantRuleOverrideApplier
{
    /**
     * Resolve a base decision rule for a tenant, layering all active
     * `tenant_rule_overrides` on top of the supplied baseline parameters.
     *
     * @param  array<string, mixed>  $baseParameters
     */
    public function apply(int $teamId, string $baseRuleCode, array $baseParameters = []): ResolvedRule;
}
