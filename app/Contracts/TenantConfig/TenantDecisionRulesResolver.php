<?php

namespace App\Contracts\TenantConfig;

use App\Domains\Decisions\Data\TenantDecisionPolicy;

interface TenantDecisionRulesResolver
{
    public function resolve(int $teamId): TenantDecisionPolicy;
}
