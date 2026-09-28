<?php

namespace App\Domains\Copilot\Queries;

use App\Domains\Copilot\Actions\RecordCopilotUsage;
use App\Domains\Copilot\Enums\CopilotMessageRole;
use App\Domains\Copilot\Models\CopilotMessage;
use App\Domains\Tenancy\Models\TenantFeature;

/**
 * Monthly Copilot allowance of a tenant (from its plan's `copilot_queries`
 * rate) against what it already used. Soft limit: going over is billed as
 * overage, never blocks an operator in the middle of an emergency.
 */
class CopilotQuotaQuery
{
    /**
     * @return array{used: int, included: int|null, percent: float|null, periodStart: string, overage: int}
     */
    public function forTeam(int $teamId): array
    {
        $periodStart = now()->startOfMonth();

        $used = CopilotMessage::query()
            ->where('team_id', $teamId)
            ->where('role', CopilotMessageRole::Assistant)
            ->where('created_at', '>=', $periodStart)
            ->count();

        $included = TenantFeature::query()
            ->where('team_id', $teamId)
            ->where('feature_key', RecordCopilotUsage::QUERIES_METER)
            ->value('limits_json');

        $includedQuantity = is_array($included) && isset($included['included_quantity'])
            ? (int) $included['included_quantity']
            : null;

        return [
            'used' => $used,
            'included' => $includedQuantity,
            'percent' => $includedQuantity ? round($used / $includedQuantity * 100, 1) : null,
            'periodStart' => $periodStart->toIso8601String(),
            'overage' => $includedQuantity !== null ? max(0, $used - $includedQuantity) : 0,
        ];
    }
}
