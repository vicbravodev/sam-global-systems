<?php

namespace Database\Factories\Domains\Tenancy;

use App\Domains\Tenancy\Models\TenantBillingTerms;
use App\Models\Team;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<TenantBillingTerms>
 */
class TenantBillingTermsFactory extends Factory
{
    protected $model = TenantBillingTerms::class;

    public function definition(): array
    {
        return [
            'team_id' => Team::factory(),
            'unit_price' => 450,
            'currency' => 'mxn',
            'included_assets' => 100,
            'min_billable_assets' => 0,
            'ai_fair_use_per_asset' => 60,
            'ai_overage_unit_price' => 5,
            'messaging_markup_percent' => null,
            'fx_usd_rate' => 18.5,
            'volume_tiers_json' => null,
            'notes' => null,
        ];
    }
}
