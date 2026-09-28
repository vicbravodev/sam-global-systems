<?php

namespace App\Domains\Tenancy\Actions;

use App\Domains\Tenancy\Events\TenantSubscriptionChanged;
use App\Domains\Tenancy\Models\TenantBillingTerms;
use App\Models\Team;
use App\Support\TenantContext;

/**
 * Upsert de los términos comerciales de un tenant desde la consola
 * super-admin. Un campo en null vuelve al default de plataforma.
 */
class UpdateTenantBillingTerms
{
    /**
     * @param  array<string, mixed>  $attributes
     */
    public function execute(Team $team, array $attributes): TenantBillingTerms
    {
        return TenantContext::for($team->id, function () use ($team, $attributes) {
            $terms = TenantBillingTerms::query()->updateOrCreate(
                ['team_id' => $team->id],
                [
                    'unit_price' => $attributes['unit_price'] ?? null,
                    'currency' => isset($attributes['currency']) ? strtolower((string) $attributes['currency']) : null,
                    'included_assets' => $attributes['included_assets'] ?? null,
                    'min_billable_assets' => $attributes['min_billable_assets'] ?? null,
                    'ai_fair_use_per_asset' => $attributes['ai_fair_use_per_asset'] ?? null,
                    'ai_overage_unit_price' => $attributes['ai_overage_unit_price'] ?? null,
                    'messaging_markup_percent' => $attributes['messaging_markup_percent'] ?? null,
                    'fx_usd_rate' => $attributes['fx_usd_rate'] ?? null,
                    'volume_tiers_json' => $attributes['volume_tiers'] ?? null,
                    'notes' => $attributes['notes'] ?? null,
                ],
            );

            TenantSubscriptionChanged::dispatch($team->id, 'billing_terms_updated');

            return $terms;
        });
    }
}
