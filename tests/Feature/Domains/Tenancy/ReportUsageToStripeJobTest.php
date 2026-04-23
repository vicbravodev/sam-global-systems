<?php

namespace Tests\Feature\Domains\Tenancy;

use App\Domains\Tenancy\Jobs\ReportUsageToStripeJob;
use App\Domains\Tenancy\Models\TenantUsageCounter;
use App\Domains\Tenancy\Models\UsageMeter;
use App\Models\Team;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Log;
use Laravel\Cashier\Exceptions\InvalidCustomer;
use Tests\TestCase;

class ReportUsageToStripeJobTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_targets_the_billing_queue(): void
    {
        $job = new ReportUsageToStripeJob(teamId: 1);

        $this->assertEquals('billing', $job->queue);
    }

    public function test_it_is_noop_when_no_billable_meters_exist(): void
    {
        $team = Team::factory()->create();

        UsageMeter::factory()->create([
            'code' => 'non-stripe-meter',
            'provider_meter_event_name' => null,
        ]);

        (new ReportUsageToStripeJob($team->id))->handle();

        $this->assertTrue(true, 'Handle should return without error when nothing is billable');
    }

    public function test_it_skips_meter_when_counter_is_missing(): void
    {
        $team = Team::factory()->create();

        UsageMeter::factory()
            ->withStripeProvider('requests_total', 'mtr_abc')
            ->create(['code' => 'requests']);

        (new ReportUsageToStripeJob($team->id))->handle();

        $this->assertTrue(true, 'Missing counters should be silently skipped');
    }

    public function test_it_skips_meter_when_consumed_value_is_zero(): void
    {
        $team = Team::factory()->create();

        $meter = UsageMeter::factory()
            ->withStripeProvider('zero_event', 'mtr_zero')
            ->create(['code' => 'zero_meter']);

        TenantUsageCounter::withoutGlobalScopes()->create([
            'team_id' => $team->id,
            'usage_meter_id' => $meter->id,
            'period_start' => now()->startOfMonth()->toDateString(),
            'period_end' => now()->endOfMonth()->toDateString(),
            'consumed_value' => 0,
            'included_value' => 0,
            'overage_value' => 0,
        ]);

        (new ReportUsageToStripeJob($team->id))->handle();

        $this->assertTrue(true, 'Zero-consumed counters should be silently skipped');
    }

    public function test_it_logs_and_rethrows_when_stripe_reporting_fails(): void
    {
        Log::spy();

        $team = Team::factory()->create(); // no stripe_id → InvalidCustomer will be thrown

        $meter = UsageMeter::factory()
            ->withStripeProvider('requests_total', 'mtr_abc')
            ->create(['code' => 'requests_billable']);

        TenantUsageCounter::withoutGlobalScopes()->create([
            'team_id' => $team->id,
            'usage_meter_id' => $meter->id,
            'period_start' => now()->startOfMonth()->toDateString(),
            'period_end' => now()->endOfMonth()->toDateString(),
            'consumed_value' => 42,
            'included_value' => 0,
            'overage_value' => 42,
        ]);

        $caught = null;
        try {
            (new ReportUsageToStripeJob($team->id))->handle();
        } catch (\Throwable $e) {
            $caught = $e;
        }

        $this->assertInstanceOf(
            InvalidCustomer::class,
            $caught,
            'Stripe error from reportMeterEvent should propagate out of the job',
        );

        Log::shouldHaveReceived('error')
            ->once()
            ->with('Failed to report usage to Stripe', \Mockery::on(function (array $context) use ($team) {
                return $context['team_id'] === $team->id
                    && $context['meter_code'] === 'requests_billable'
                    && is_string($context['error']);
            }));
    }

    public function test_it_throws_when_team_does_not_exist(): void
    {
        $this->expectException(ModelNotFoundException::class);

        (new ReportUsageToStripeJob(teamId: 999_999))->handle();
    }
}
