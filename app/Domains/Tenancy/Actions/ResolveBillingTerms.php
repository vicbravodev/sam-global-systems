<?php

namespace App\Domains\Tenancy\Actions;

use App\Domains\Tenancy\Data\BillingTermsData;
use App\Domains\Tenancy\Models\TenantBillingTerms;
use App\Support\TenantContext;

/**
 * Términos efectivos de un tenant: cada campo explícito en
 * `tenant_billing_terms` gana; lo que esté en null cae al default de
 * plataforma (`config/billing.php`). Sin fila, aplica todo el default.
 */
class ResolveBillingTerms
{
    public function execute(int $teamId): BillingTermsData
    {
        $row = TenantContext::for($teamId, fn () => TenantBillingTerms::query()
            ->where('team_id', $teamId)
            ->first());

        $tiers = $row?->volume_tiers_json ?? config('billing.volume_tiers', []);

        return new BillingTermsData(
            unitPrice: $row?->unit_price !== null ? (float) $row->unit_price : (float) config('billing.unit_price'),
            currency: strtolower((string) ($row?->currency ?? config('billing.currency', 'mxn'))),
            includedAssets: $row?->included_assets,
            minBillableAssets: $row?->min_billable_assets ?? (int) config('billing.min_billable_assets', 0),
            aiFairUsePerAsset: $row?->ai_fair_use_per_asset ?? (int) config('billing.ai_fair_use_per_asset', 0),
            aiOverageUnitPrice: $row?->ai_overage_unit_price !== null ? (float) $row->ai_overage_unit_price : (float) config('billing.ai_overage_unit_price', 0),
            messagingMarkupPercent: $row?->messaging_markup_percent !== null ? (float) $row->messaging_markup_percent : null,
            fxUsdRate: $row?->fx_usd_rate !== null ? (float) $row->fx_usd_rate : (float) config('billing.fx_usd_rate', 1),
            volumeTiers: is_array($tiers) ? array_values($tiers) : [],
            explicit: $row !== null,
        );
    }
}
