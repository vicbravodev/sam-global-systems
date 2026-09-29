<?php

namespace App\Domains\Tenancy\Actions;

use App\Domains\Tenancy\Models\BillingRate;
use App\Domains\Tenancy\Models\Subscription;
use App\Domains\Tenancy\Models\TenantBillingTerms;
use App\Domains\Tenancy\Models\TenantFeature;
use App\Domains\Tenancy\Models\UsageMeter;
use App\Support\TenantContext;

/**
 * Resolves the effective cap on monitored/synced assets for a tenant.
 *
 * Precedence: the tenant's explicit billing terms (`included_assets`) win;
 * then a per-tenant TenantFeature limit (manual override or plan-seeded);
 * otherwise the tenant's current plan billing rate for the asset meter.
 * Returns null when no cap applies (unlimited).
 *
 * The cap is SOFT (2026-09-28): callers never block on it, they flag the
 * excess so it is billed as extra asset-days.
 */
class ResolveAssetLimit
{
    public const METER_CODE = 'monitored_assets';

    public function execute(int $teamId): ?int
    {
        return $this->explain($teamId)['cap'];
    }

    /**
     * La misma cascada, con la fuente del tope y los valores que la
     * decidieron (`none_reason` cuando no hay tope).
     *
     * @return array{cap: ?int, source: 'billing_terms'|'tenant_feature'|'plan_rate'|'none', calc: array<string, mixed>}
     */
    public function explain(int $teamId): array
    {
        return TenantContext::for($teamId, function () use ($teamId) {
            $contracted = TenantBillingTerms::query()
                ->where('team_id', $teamId)
                ->value('included_assets');

            $calc = ['contracted_value' => $contracted !== null ? (int) $contracted : null];

            if ($contracted !== null) {
                $unlimited = (int) $contracted <= 0;

                return [
                    'cap' => $unlimited ? null : (int) $contracted,
                    'source' => 'billing_terms',
                    'calc' => [...$calc, 'unlimited_by_terms' => $unlimited],
                ];
            }

            $feature = TenantFeature::query()
                ->where('team_id', $teamId)
                ->where('feature_key', self::METER_CODE)
                ->first();

            $featureLimit = $feature?->limits_json['included_quantity'] ?? null;

            if (is_numeric($featureLimit)) {
                return [
                    'cap' => (int) $featureLimit,
                    'source' => 'tenant_feature',
                    'calc' => [...$calc, 'feature_limit' => $featureLimit],
                ];
            }

            $meterId = UsageMeter::query()->where('code', self::METER_CODE)->value('id');

            if ($meterId === null) {
                return ['cap' => null, 'source' => 'none', 'calc' => [...$calc, 'none_reason' => 'meter_missing']];
            }

            $subscription = Subscription::query()
                ->where('team_id', $teamId)
                ->orderByDesc('starts_at')
                ->first();

            if ($subscription?->plan_id === null) {
                return ['cap' => null, 'source' => 'none', 'calc' => [...$calc, 'none_reason' => 'no_plan']];
            }

            $included = BillingRate::query()
                ->where('plan_id', $subscription->plan_id)
                ->where('usage_meter_id', $meterId)
                ->value('included_quantity');

            $calc = [...$calc, 'subscription_id' => $subscription->id, 'plan_id' => $subscription->plan_id, 'included_quantity' => $included !== null ? (int) $included : null];

            if ($included !== null && (int) $included > 0) {
                return ['cap' => (int) $included, 'source' => 'plan_rate', 'calc' => $calc];
            }

            return ['cap' => null, 'source' => 'none', 'calc' => [...$calc, 'none_reason' => 'no_included_quantity']];
        });
    }
}
