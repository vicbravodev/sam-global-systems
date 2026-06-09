<?php

namespace App\Domains\Tenancy\Actions;

use App\Domains\Tenancy\Models\BillingRate;
use App\Domains\Tenancy\Models\Subscription;
use App\Domains\Tenancy\Models\TenantFeature;
use App\Domains\Tenancy\Models\UsageMeter;

/**
 * Resolves the effective cap on monitored/synced assets for a tenant.
 *
 * Precedence: a per-tenant TenantFeature limit (manual override or plan-seeded)
 * wins; otherwise the tenant's current plan billing rate for the asset meter.
 * Returns null when no cap applies (unlimited).
 */
class ResolveAssetLimit
{
    public const METER_CODE = 'monitored_assets';

    public function execute(int $teamId): ?int
    {
        $feature = TenantFeature::withoutGlobalScopes()
            ->where('team_id', $teamId)
            ->where('feature_key', self::METER_CODE)
            ->first();

        $featureLimit = $feature?->limits_json['included_quantity'] ?? null;

        if (is_numeric($featureLimit)) {
            return (int) $featureLimit;
        }

        $meterId = UsageMeter::query()->where('code', self::METER_CODE)->value('id');

        if ($meterId === null) {
            return null;
        }

        $subscription = Subscription::withoutGlobalScopes()
            ->where('team_id', $teamId)
            ->orderByDesc('starts_at')
            ->first();

        if ($subscription?->plan_id === null) {
            return null;
        }

        $included = BillingRate::query()
            ->where('plan_id', $subscription->plan_id)
            ->where('usage_meter_id', $meterId)
            ->value('included_quantity');

        return $included !== null && (int) $included > 0 ? (int) $included : null;
    }
}
