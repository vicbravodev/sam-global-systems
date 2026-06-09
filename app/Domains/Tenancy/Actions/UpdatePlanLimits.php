<?php

namespace App\Domains\Tenancy\Actions;

use App\Domains\Tenancy\Enums\BillingModel;
use App\Domains\Tenancy\Models\BillingRate;
use App\Domains\Tenancy\Models\Plan;
use App\Domains\Tenancy\Models\UsageMeter;
use Illuminate\Support\Facades\DB;

/**
 * Updates a plan's per-meter included quantities (e.g. the asset cap). Accepts a
 * map of meter code => included quantity and upserts the matching BillingRate
 * rows; existing rows keep their pricing/model, new rows default to
 * included-only. Meters not present in the map are left untouched.
 */
class UpdatePlanLimits
{
    /**
     * @param  array<string, int>  $meterLimits  meter code => included_quantity
     */
    public function execute(Plan $plan, array $meterLimits): Plan
    {
        return DB::transaction(function () use ($plan, $meterLimits) {
            $meterIds = UsageMeter::query()
                ->whereIn('code', array_keys($meterLimits))
                ->pluck('id', 'code');

            foreach ($meterLimits as $code => $included) {
                $meterId = $meterIds[$code] ?? null;

                if ($meterId === null) {
                    continue;
                }

                $quantity = max(0, (int) $included);

                $rate = BillingRate::query()
                    ->where('plan_id', $plan->id)
                    ->where('usage_meter_id', $meterId)
                    ->first();

                if ($rate) {
                    $rate->update(['included_quantity' => $quantity]);

                    continue;
                }

                BillingRate::query()->create([
                    'plan_id' => $plan->id,
                    'usage_meter_id' => $meterId,
                    'included_quantity' => $quantity,
                    'overage_unit_price' => 0,
                    'billing_model' => BillingModel::IncludedOnly,
                ]);
            }

            return $plan->fresh();
        });
    }
}
