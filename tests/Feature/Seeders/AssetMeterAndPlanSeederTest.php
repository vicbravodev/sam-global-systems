<?php

namespace Tests\Feature\Seeders;

use App\Domains\Tenancy\Models\BillingRate;
use App\Domains\Tenancy\Models\Plan;
use App\Domains\Tenancy\Models\UsageMeter;
use Database\Seeders\AIMeterSeeder;
use Database\Seeders\AssetMeterSeeder;
use Database\Seeders\IncidentMeterSeeder;
use Database\Seeders\NotificationMeterSeeder;
use Database\Seeders\PlanSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AssetMeterAndPlanSeederTest extends TestCase
{
    use RefreshDatabase;

    public function test_asset_meter_seeder_creates_the_asset_meters(): void
    {
        $this->seed(AssetMeterSeeder::class);

        $this->assertDatabaseHas('usage_meters', ['code' => 'monitored_assets']);
        $this->assertDatabaseHas('usage_meters', ['code' => 'active_cameras']);
    }

    public function test_plan_seeder_creates_plans_with_asset_caps(): void
    {
        $this->seedMeters();
        $this->seed(PlanSeeder::class);

        $this->assertEqualsCanonicalizing(
            ['starter', 'pro', 'enterprise'],
            Plan::whereIn('code', ['starter', 'pro', 'enterprise'])->pluck('code')->all(),
        );

        $assetMeterId = UsageMeter::where('code', 'monitored_assets')->value('id');

        $expectedCaps = ['starter' => 25, 'pro' => 200, 'enterprise' => 2000];

        foreach ($expectedCaps as $planCode => $cap) {
            $planId = Plan::where('code', $planCode)->value('id');

            $this->assertSame(
                $cap,
                (int) BillingRate::where('plan_id', $planId)
                    ->where('usage_meter_id', $assetMeterId)
                    ->value('included_quantity'),
                "Plan {$planCode} should cap monitored_assets at {$cap}.",
            );
        }
    }

    public function test_seeders_are_idempotent(): void
    {
        $this->seedMeters();
        $this->seed(PlanSeeder::class);
        $this->seedMeters();
        $this->seed(PlanSeeder::class);

        $this->assertSame(1, UsageMeter::where('code', 'monitored_assets')->count());
        $this->assertSame(1, Plan::where('code', 'starter')->count());

        $assetMeterId = UsageMeter::where('code', 'monitored_assets')->value('id');
        $starterId = Plan::where('code', 'starter')->value('id');

        $this->assertSame(
            1,
            BillingRate::where('plan_id', $starterId)
                ->where('usage_meter_id', $assetMeterId)
                ->count(),
        );
    }

    private function seedMeters(): void
    {
        $this->seed(AIMeterSeeder::class);
        $this->seed(IncidentMeterSeeder::class);
        $this->seed(NotificationMeterSeeder::class);
        $this->seed(AssetMeterSeeder::class);
    }
}
