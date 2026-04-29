<?php

namespace App\Domains\Automation\Actions;

use App\Domains\Automation\Models\ActionTemplate;

class ResolveActionTemplate
{
    /**
     * Resolve an action template by code, preferring tenant-specific records
     * over system-wide ones (team_id = null).
     */
    public function execute(int $teamId, string $code): ?ActionTemplate
    {
        $tenantTemplate = ActionTemplate::query()
            ->where('team_id', $teamId)
            ->where('code', $code)
            ->where('is_active', true)
            ->first();

        if ($tenantTemplate) {
            return $tenantTemplate;
        }

        return ActionTemplate::query()
            ->whereNull('team_id')
            ->where('code', $code)
            ->where('is_active', true)
            ->first();
    }
}
