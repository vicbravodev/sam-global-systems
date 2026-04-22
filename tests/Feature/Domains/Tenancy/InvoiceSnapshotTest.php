<?php

namespace Tests\Feature\Domains\Tenancy;

use App\Domains\Tenancy\Enums\SubscriptionStatus;
use App\Domains\Tenancy\Jobs\GenerateInvoiceSnapshotJob;
use App\Domains\Tenancy\Models\BillingRate;
use App\Domains\Tenancy\Models\InvoiceSnapshot;
use App\Domains\Tenancy\Models\Plan;
use App\Domains\Tenancy\Models\Subscription;
use App\Domains\Tenancy\Models\TenantUsageCounter;
use App\Domains\Tenancy\Models\UsageMeter;
use App\Models\Team;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class InvoiceSnapshotTest extends TestCase
{
    use RefreshDatabase;

    public function test_invoice_snapshot_contains_per_meter_breakdown(): void
    {
        $team = Team::factory()->create();
        $plan = Plan::factory()->create(['base_price' => 99.00]);

        $meterA = UsageMeter::factory()->create(['code' => 'api_requests', 'name' => 'API Requests']);
        $meterB = UsageMeter::factory()->create(['code' => 'ai_calls', 'name' => 'AI Calls']);

        Subscription::factory()->create([
            'team_id' => $team->id,
            'plan_id' => $plan->id,
            'status' => SubscriptionStatus::Active,
        ]);

        BillingRate::factory()->create([
            'plan_id' => $plan->id,
            'usage_meter_id' => $meterA->id,
            'included_quantity' => 1000,
            'overage_unit_price' => 0.01,
        ]);

        BillingRate::factory()->create([
            'plan_id' => $plan->id,
            'usage_meter_id' => $meterB->id,
            'included_quantity' => 50,
            'overage_unit_price' => 0.50,
        ]);

        $periodStart = now()->startOfMonth()->toDateString();
        $periodEnd = now()->endOfMonth()->toDateString();

        TenantUsageCounter::withoutGlobalScopes()->create([
            'team_id' => $team->id,
            'usage_meter_id' => $meterA->id,
            'period_start' => $periodStart,
            'period_end' => $periodEnd,
            'consumed_value' => 1200,
            'included_value' => 1000,
            'overage_value' => 200,
        ]);

        TenantUsageCounter::withoutGlobalScopes()->create([
            'team_id' => $team->id,
            'usage_meter_id' => $meterB->id,
            'period_start' => $periodStart,
            'period_end' => $periodEnd,
            'consumed_value' => 75,
            'included_value' => 50,
            'overage_value' => 25,
        ]);

        $job = new GenerateInvoiceSnapshotJob($team->id, $periodStart, $periodEnd);
        $job->handle();

        $snapshot = InvoiceSnapshot::withoutGlobalScopes()
            ->where('team_id', $team->id)
            ->whereDate('period_start', $periodStart)
            ->first();

        $this->assertNotNull($snapshot, 'Invoice snapshot should be created for the billing period');

        $breakdown = $snapshot->breakdown_json;

        $this->assertIsArray($breakdown, 'Breakdown should be a JSON array');
        $this->assertCount(2, $breakdown, 'Breakdown should contain an entry for each metered dimension');

        $apiEntry = collect($breakdown)->firstWhere('meter_code', 'api_requests');
        $this->assertNotNull($apiEntry, 'Breakdown should include api_requests meter entry');
        $this->assertEquals(1200, $apiEntry['consumed'], 'API requests consumed should be 1200');
        $this->assertEquals(1000, $apiEntry['included'], 'API requests included should be 1000');
        $this->assertEquals(200, $apiEntry['overage'], 'API requests overage should be 200');

        $aiEntry = collect($breakdown)->firstWhere('meter_code', 'ai_calls');
        $this->assertNotNull($aiEntry, 'Breakdown should include ai_calls meter entry');
        $this->assertEquals(75, $aiEntry['consumed'], 'AI calls consumed should be 75');

        $expectedOverageTotal = (200 * 0.01) + (25 * 0.50);
        $this->assertEquals(
            $expectedOverageTotal,
            (float) $snapshot->overage_total,
            'Invoice overage total should sum all meter overage costs',
        );

        $this->assertEquals(
            99.00 + $expectedOverageTotal,
            (float) $snapshot->total,
            'Invoice total should be base price plus overage total',
        );
    }

    public function test_duplicate_snapshot_for_same_period_is_not_created(): void
    {
        $team = Team::factory()->create();
        $plan = Plan::factory()->create();

        $subscription = Subscription::factory()->create([
            'team_id' => $team->id,
            'plan_id' => $plan->id,
            'status' => SubscriptionStatus::Active,
        ]);

        $this->assertNotNull($subscription->id, 'Subscription should be created');

        $periodStart = now()->startOfMonth()->toDateString();
        $periodEnd = now()->endOfMonth()->toDateString();

        (new GenerateInvoiceSnapshotJob($team->id, $periodStart, $periodEnd))->handle();

        $firstCount = InvoiceSnapshot::withoutGlobalScopes()
            ->where('team_id', $team->id)
            ->count();

        $this->assertEquals(1, $firstCount, 'First invocation should create one snapshot');

        (new GenerateInvoiceSnapshotJob($team->id, $periodStart, $periodEnd))->handle();

        $secondCount = InvoiceSnapshot::withoutGlobalScopes()
            ->where('team_id', $team->id)
            ->count();

        $this->assertEquals(1, $secondCount, 'Second invocation should not create a duplicate snapshot');
    }
}
