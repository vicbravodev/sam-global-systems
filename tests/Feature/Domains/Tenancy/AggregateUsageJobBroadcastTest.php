<?php

namespace Tests\Feature\Domains\Tenancy;

use App\Domains\Tenancy\Enums\SubscriptionStatus;
use App\Domains\Tenancy\Events\UsageLimitExceeded;
use App\Domains\Tenancy\Events\UsageUpdatedBroadcast;
use App\Domains\Tenancy\Jobs\AggregateUsageJob;
use App\Domains\Tenancy\Models\BillingRate;
use App\Domains\Tenancy\Models\Plan;
use App\Domains\Tenancy\Models\Subscription;
use App\Domains\Tenancy\Models\TenantUsageCounter;
use App\Domains\Tenancy\Models\UsageEvent;
use App\Domains\Tenancy\Models\UsageMeter;
use App\Models\Team;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use Tests\Concerns\AssertsSystemLog;
use Tests\TestCase;

class AggregateUsageJobBroadcastTest extends TestCase
{
    use AssertsSystemLog, RefreshDatabase;

    public function test_it_processes_all_subscribed_teams_when_team_id_is_null(): void
    {
        Event::fake([UsageLimitExceeded::class, UsageUpdatedBroadcast::class]);

        $plan = Plan::factory()->create();
        $meter = UsageMeter::factory()->create(['code' => 'bulk_meter']);

        $teamA = Team::factory()->create();
        $teamB = Team::factory()->create();
        $unsubscribedTeam = Team::factory()->create();

        foreach ([$teamA, $teamB] as $team) {
            Subscription::factory()->create([
                'team_id' => $team->id,
                'plan_id' => $plan->id,
                'status' => SubscriptionStatus::Active,
            ]);

            UsageEvent::withoutGlobalScopes()->insert([
                'team_id' => $team->id,
                'usage_meter_id' => $meter->id,
                'event_key' => "bulk-{$team->id}",
                'quantity' => 1,
                'occurred_at' => now(),
                'billing_period_key' => now()->format('Y-m'),
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }

        (new AggregateUsageJob)->handle();

        // Scoped to this test's meter: the catalog also holds the messaging
        // meters every install gets from migrations.
        $this->assertEquals(
            2,
            TenantUsageCounter::withoutGlobalScopes()->where('usage_meter_id', $meter->id)->count(),
            'Job should aggregate counters for every team with an active/past_due subscription',
        );

        $this->assertDatabaseMissing('tenant_usage_counters', [
            'team_id' => $unsubscribedTeam->id,
        ]);

        // El recorrido de plataforma sólo registra el conteo; cada tenant
        // narra su propia agregación con su team_id.
        $fanned = $this->assertSystemLogged('billing.aggregate.fanned_out');
        $this->assertSame(2, $fanned['result']['jobs_dispatched_count']);
        $this->assertSame(['jobs_dispatched_count'], array_keys($fanned['result']));
        $this->assertArrayNotHasKey('input', $fanned);
        foreach ([$teamA, $teamB] as $team) {
            $this->assertSystemLogged('billing.aggregate.completed', fn (array $c) => $c['input']['team_id'] === $team->id && $c['outcome'] === 'ok');
        }
        $this->assertSame([], array_filter(
            $this->systemLogEntries('billing.aggregate.completed'),
            fn (array $e) => $e['context']['input']['team_id'] === $unsubscribedTeam->id,
        ));
        $this->assertNoSensitiveDataLogged();
    }

    public function test_it_broadcasts_when_consumed_value_changes_more_than_five_percent(): void
    {
        Event::fake([UsageLimitExceeded::class, UsageUpdatedBroadcast::class]);

        $team = Team::factory()->create();
        $plan = Plan::factory()->create();
        $meter = UsageMeter::factory()->create(['code' => 'broadcast_meter']);

        Subscription::factory()->create([
            'team_id' => $team->id,
            'plan_id' => $plan->id,
            'status' => SubscriptionStatus::Active,
        ]);

        BillingRate::factory()->create([
            'plan_id' => $plan->id,
            'usage_meter_id' => $meter->id,
            'included_quantity' => 100_000,
        ]);

        TenantUsageCounter::withoutGlobalScopes()->create([
            'team_id' => $team->id,
            'usage_meter_id' => $meter->id,
            'period_start' => now()->startOfMonth()->toDateString(),
            'period_end' => now()->endOfMonth()->toDateString(),
            'consumed_value' => 100,
            'included_value' => 100_000,
            'overage_value' => 0,
        ]);

        UsageEvent::withoutGlobalScopes()->insert([
            'team_id' => $team->id,
            'usage_meter_id' => $meter->id,
            'event_key' => 'broadcast-evt-1',
            'quantity' => 200, // 200 vs previous 100 → +100% change
            'occurred_at' => now(),
            'billing_period_key' => now()->format('Y-m'),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        (new AggregateUsageJob($team->id))->handle();

        Event::assertDispatched(UsageUpdatedBroadcast::class, function (UsageUpdatedBroadcast $event) use ($team) {
            return $event->teamId === $team->id
                && $event->meterCode === 'broadcast_meter'
                && $event->consumed === 200;
        });

        $computed = $this->assertSystemLogged('billing.overage.computed', fn (array $c) => $c['input']['meter_code'] === 'broadcast_meter');
        $this->assertTrue($computed['result']['broadcast_dispatched']);
        $this->assertTrue($computed['calc']['previous_counter_found']);
        $this->assertSame(200, $computed['calc']['consumed']);
        $this->assertFalse($computed['calc']['first_crossing']);
        $this->assertSame(1, $this->assertSystemLogged('billing.aggregate.completed')['result']['broadcasts_dispatched_count']);
        $this->assertNoSensitiveDataLogged();
    }

    public function test_it_does_not_broadcast_on_first_aggregation_when_no_previous_counter(): void
    {
        Event::fake([UsageLimitExceeded::class, UsageUpdatedBroadcast::class]);

        $team = Team::factory()->create();
        $plan = Plan::factory()->create();
        $meter = UsageMeter::factory()->create(['code' => 'first_run']);

        Subscription::factory()->create([
            'team_id' => $team->id,
            'plan_id' => $plan->id,
            'status' => SubscriptionStatus::PastDue,
        ]);

        UsageEvent::withoutGlobalScopes()->insert([
            'team_id' => $team->id,
            'usage_meter_id' => $meter->id,
            'event_key' => 'first-evt',
            'quantity' => 5,
            'occurred_at' => now(),
            'billing_period_key' => now()->format('Y-m'),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        (new AggregateUsageJob($team->id))->handle();

        Event::assertNotDispatched(UsageUpdatedBroadcast::class);

        $computed = $this->assertSystemLogged('billing.overage.computed', fn (array $c) => $c['input']['meter_code'] === 'first_run');
        $this->assertFalse($computed['result']['broadcast_dispatched']);
        $this->assertFalse($computed['calc']['previous_counter_found']);
    }

    public function test_it_treats_meter_without_subscription_or_rate_as_zero_included(): void
    {
        Event::fake([UsageLimitExceeded::class]);

        $team = Team::factory()->create();
        $meter = UsageMeter::factory()->create(['code' => 'no_rate_meter']);

        // Subscription exists but no BillingRate → included stays at 0
        Subscription::factory()->create([
            'team_id' => $team->id,
            'status' => SubscriptionStatus::PastDue,
        ]);

        UsageEvent::withoutGlobalScopes()->insert([
            'team_id' => $team->id,
            'usage_meter_id' => $meter->id,
            'event_key' => 'no-rate-evt',
            'quantity' => 10,
            'occurred_at' => now(),
            'billing_period_key' => now()->format('Y-m'),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        (new AggregateUsageJob($team->id))->handle();

        $this->assertDatabaseHas('tenant_usage_counters', [
            'team_id' => $team->id,
            'usage_meter_id' => $meter->id,
            'consumed_value' => 10,
            'included_value' => 0,
            'overage_value' => 10,
        ]);

        $entry = collect($this->systemLogEntries('billing.overage.computed'))
            ->first(fn (array $e) => $e['context']['input']['meter_code'] === 'no_rate_meter');
        $this->assertNotNull($entry);
        $this->assertContains($entry['context']['calc']['included_source'], ['no_subscription', 'no_plan_rate']);
        $this->assertSame(0, $entry['context']['calc']['included']);
        // 10 por encima de un incluido 0 es un primer cruce real: va en info.
        $this->assertTrue($entry['context']['calc']['first_crossing']);
        $this->assertSame('info', $entry['level']);

        // Los medidores sin tarifa y sin consumo (sin excedente ni cruce) van en debug.
        $quiet = collect($this->systemLogEntries('billing.overage.computed'))
            ->filter(fn (array $e) => $e['context']['input']['meter_code'] !== 'no_rate_meter');
        $this->assertNotEmpty($quiet);
        foreach ($quiet as $e) {
            $this->assertSame('no_plan_rate', $e['context']['calc']['included_source']);
            $this->assertSame(0, $e['context']['result']['overage']);
            $this->assertSame('debug', $e['level']);
        }
        $this->assertNoSensitiveDataLogged();
    }
}
