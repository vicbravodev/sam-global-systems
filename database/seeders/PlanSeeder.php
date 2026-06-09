<?php

namespace Database\Seeders;

use App\Domains\Tenancy\Enums\BillingCycle;
use App\Domains\Tenancy\Enums\BillingModel;
use App\Domains\Tenancy\Models\BillingRate;
use App\Domains\Tenancy\Models\Plan;
use App\Domains\Tenancy\Models\UsageMeter;
use Illuminate\Database\Seeder;

class PlanSeeder extends Seeder
{
    /**
     * Default commercial plans. Each plan expresses its allowances through
     * BillingRate rows keyed by usage-meter code; `included_quantity` is the
     * cap the tenant gets (e.g. how many assets it may monitor/sync).
     *
     * Run AFTER every *MeterSeeder so the meter codes resolve.
     */
    public function run(): void
    {
        $plans = [
            [
                'code' => 'starter',
                'name' => 'Starter',
                'description' => 'Para flotas pequeñas que arrancan con SAM.',
                'base_price' => 0,
                'rates' => [
                    'monitored_assets' => 25,
                    'active_cameras' => 10,
                    'ai_calls' => 1_000,
                    'incident_workflows' => 250,
                    'outbound_notifications' => 1_000,
                ],
            ],
            [
                'code' => 'pro',
                'name' => 'Pro',
                'description' => 'Para operaciones en crecimiento con automatización.',
                'base_price' => 99,
                'rates' => [
                    'monitored_assets' => 200,
                    'active_cameras' => 100,
                    'ai_calls' => 20_000,
                    'incident_workflows' => 5_000,
                    'outbound_notifications' => 20_000,
                ],
            ],
            [
                'code' => 'enterprise',
                'name' => 'Enterprise',
                'description' => 'Para flotas grandes con necesidades a medida.',
                'base_price' => 499,
                'rates' => [
                    'monitored_assets' => 2_000,
                    'active_cameras' => 1_000,
                    'ai_calls' => 200_000,
                    'incident_workflows' => 50_000,
                    'outbound_notifications' => 200_000,
                ],
            ],
        ];

        $meters = UsageMeter::query()->pluck('id', 'code');

        foreach ($plans as $definition) {
            $plan = Plan::query()->updateOrCreate(
                ['code' => $definition['code']],
                [
                    'name' => $definition['name'],
                    'description' => $definition['description'],
                    'base_price' => $definition['base_price'],
                    'currency' => 'usd',
                    'billing_cycle' => BillingCycle::Monthly,
                    'is_active' => true,
                ],
            );

            foreach ($definition['rates'] as $meterCode => $included) {
                $meterId = $meters[$meterCode] ?? null;

                if ($meterId === null) {
                    continue;
                }

                BillingRate::query()->updateOrCreate(
                    ['plan_id' => $plan->id, 'usage_meter_id' => $meterId],
                    [
                        'included_quantity' => $included,
                        'overage_unit_price' => 0,
                        'billing_model' => BillingModel::IncludedOnly,
                    ],
                );
            }
        }
    }
}
