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
use Illuminate\Database\UniqueConstraintViolationException;
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
        // Tracto-día + IA (uso justo) + Twilio (meter sembrado por migración) +
        // plan (api_requests) + términos. ai_calls se factura por uso justo del
        // tenant, no por la tarifa del plan.
        $this->assertEqualsCanonicalizing(
            ['monitored_asset_days', 'ai_calls', 'messaging_cost_micros', 'api_requests', '_terms'],
            collect($breakdown)->pluck('meter_code')->all(),
        );

        $apiEntry = collect($breakdown)->firstWhere('meter_code', 'api_requests');
        $this->assertNotNull($apiEntry, 'Breakdown should include api_requests meter entry');
        $this->assertEquals(1200, $apiEntry['consumed'], 'API requests consumed should be 1200');
        $this->assertEquals(1000, $apiEntry['included'], 'API requests included should be 1000');
        $this->assertEquals(200, $apiEntry['overage'], 'API requests overage should be 200');

        $aiEntry = collect($breakdown)->firstWhere('meter_code', 'ai_calls');
        $this->assertNotNull($aiEntry, 'Breakdown should include ai_calls meter entry');
        $this->assertEquals(75, $aiEntry['consumed'], 'AI calls consumed should be 75');
        $this->assertSame('fair_use', $aiEntry['billing_model']);
        // Sin tracto-días vigilados el uso justo incluido es 0: las 75
        // evaluaciones son excedente al precio de plataforma.
        $this->assertEquals(75, $aiEntry['overage']);

        $expectedOverageTotal = (200 * 0.01) + (75 * (float) config('billing.ai_overage_unit_price'));
        $this->assertEqualsWithDelta(
            $expectedOverageTotal,
            (float) $snapshot->overage_total,
            0.01,
            'Invoice overage total should sum all meter overage costs',
        );

        // El plan ya no aporta precio base: sin tracto-días el subtotal es 0.
        $this->assertEquals(0.0, (float) $snapshot->subtotal);
        $this->assertEqualsWithDelta(
            $expectedOverageTotal,
            (float) $snapshot->total,
            0.01,
            'Invoice total is the asset-day line plus overage total',
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

    public function test_the_database_rejects_a_second_invoice_for_the_same_period(): void
    {
        $team = Team::factory()->create();

        InvoiceSnapshot::factory()->create([
            'team_id' => $team->id,
            'period_start' => '2026-09-01',
            'period_end' => '2026-09-30',
        ]);

        $this->expectException(UniqueConstraintViolationException::class);

        InvoiceSnapshot::factory()->create([
            'team_id' => $team->id,
            'period_start' => '2026-09-01',
            'period_end' => '2026-09-30',
        ]);
    }
}
