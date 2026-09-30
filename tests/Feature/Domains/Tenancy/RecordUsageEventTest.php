<?php

namespace Tests\Feature\Domains\Tenancy;

use App\Domains\Tenancy\Actions\RecordUsageEvent;
use App\Domains\Tenancy\Events\UsageRecorded;
use App\Domains\Tenancy\Models\UsageEvent;
use App\Domains\Tenancy\Models\UsageMeter;
use App\Models\Team;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use RuntimeException;
use Tests\Concerns\AssertsSystemLog;
use Tests\TestCase;

class RecordUsageEventTest extends TestCase
{
    use AssertsSystemLog, RefreshDatabase;

    public function test_it_records_usage_event_idempotently(): void
    {
        Event::fake([UsageRecorded::class]);

        $team = Team::factory()->create();
        $meter = UsageMeter::factory()->create(['code' => 'api_requests']);

        $action = app(RecordUsageEvent::class);
        $action->execute(
            teamId: $team->id,
            meterCode: 'api_requests',
            quantity: 5,
            eventKey: 'req-001',
        );

        $this->assertDatabaseHas('usage_events', [
            'team_id' => $team->id,
            'usage_meter_id' => $meter->id,
            'event_key' => 'req-001',
            'quantity' => 5,
        ]);

        Event::assertDispatched(UsageRecorded::class, function (UsageRecorded $event) use ($team) {
            return $event->teamId === $team->id
                && $event->meterCode === 'api_requests'
                && $event->quantity === 5
                && $event->eventKey === 'req-001';
        });

        $action->execute(
            teamId: $team->id,
            meterCode: 'api_requests',
            quantity: 5,
            eventKey: 'req-001',
        );

        $this->assertCount(1, $this->systemLogEntries('billing.usage.recorded'));
        $this->assertSystemLogged('billing.usage.recorded', fn (array $c) => $c['input']['team_id'] === $team->id
            && $c['input']['meter_code'] === 'api_requests'
            && $c['input']['event_key'] === 'req-001'
            && $c['calc']['quantity'] === 5
            && $c['calc']['billing_period_key'] === now()->format('Y-m')
            && $c['result']['recorded'] === true);
        $this->assertSystemLogged('billing.usage.duplicate_ignored', fn (array $c) => $c['reason'] === 'event_key_exists'
            && $c['input']['event_key'] === 'req-001'
            && $c['calc']['quantity'] === 5
            && $c['calc']['billing_period_key'] === now()->format('Y-m'));
        $this->assertNoSensitiveDataLogged();
    }

    public function test_duplicate_usage_event_key_is_ignored(): void
    {
        Event::fake([UsageRecorded::class]);

        $team = Team::factory()->create();
        UsageMeter::factory()->create(['code' => 'api_requests']);

        $action = app(RecordUsageEvent::class);

        $action->execute(
            teamId: $team->id,
            meterCode: 'api_requests',
            quantity: 5,
            eventKey: 'req-dup',
        );

        $action->execute(
            teamId: $team->id,
            meterCode: 'api_requests',
            quantity: 10,
            eventKey: 'req-dup',
        );

        $eventCount = UsageEvent::withoutGlobalScopes()->where('team_id', $team->id)->count();
        $this->assertEquals(1, $eventCount, 'Duplicate event_key should not create a second row');

        $this->assertDatabaseHas('usage_events', [
            'team_id' => $team->id,
            'event_key' => 'req-dup',
            'quantity' => 5,
        ]);

        Event::assertDispatchedTimes(UsageRecorded::class, 1);
    }

    public function test_it_records_usage_with_metadata(): void
    {
        Event::fake([UsageRecorded::class]);

        $team = Team::factory()->create();
        UsageMeter::factory()->create(['code' => 'ai_tokens_in']);

        $action = app(RecordUsageEvent::class);
        $action->execute(
            teamId: $team->id,
            meterCode: 'ai_tokens_in',
            quantity: 1500,
            eventKey: 'ai-session-001',
            metadata: ['model' => 'gpt-4', 'session_id' => 'abc123'],
        );

        $this->assertDatabaseHas('usage_events', [
            'team_id' => $team->id,
            'event_key' => 'ai-session-001',
            'quantity' => 1500,
        ]);
    }

    public function test_it_sets_billing_period_key_based_on_reset_period(): void
    {
        Event::fake([UsageRecorded::class]);

        $team = Team::factory()->create();
        UsageMeter::factory()->create([
            'code' => 'monthly_meter',
            'reset_period' => 'monthly',
        ]);

        $action = app(RecordUsageEvent::class);
        $occurredAt = now();

        $action->execute(
            teamId: $team->id,
            meterCode: 'monthly_meter',
            quantity: 1,
            eventKey: 'period-test-001',
            occurredAt: $occurredAt,
        );

        $this->assertDatabaseHas('usage_events', [
            'event_key' => 'period-test-001',
            'billing_period_key' => $occurredAt->format('Y-m'),
        ]);

        $this->assertSystemLogged('billing.usage.recorded', fn (array $c) => $c['input']['event_key'] === 'period-test-001'
            && $c['calc']['reset_period'] === 'monthly'
            && $c['calc']['billing_period_key'] === $occurredAt->format('Y-m'));

        UsageMeter::factory()->create([
            'code' => 'daily_meter',
            'reset_period' => 'daily',
        ]);

        $dailyAt = now()->subDays(3);

        $action->execute(
            teamId: $team->id,
            meterCode: 'daily_meter',
            quantity: 1,
            eventKey: 'period-test-002',
            occurredAt: $dailyAt,
        );

        $this->assertDatabaseHas('usage_events', [
            'event_key' => 'period-test-002',
            'billing_period_key' => $dailyAt->format('Y-m-d'),
        ]);

        $this->assertSystemLogged('billing.usage.recorded', fn (array $c) => $c['input']['event_key'] === 'period-test-002'
            && $c['calc']['reset_period'] === 'daily'
            && $c['calc']['occurred_at'] === $dailyAt->toIso8601String()
            && $c['calc']['billing_period_key'] === $dailyAt->format('Y-m-d'));
        $this->assertNoSensitiveDataLogged();
    }

    public function test_a_missing_meter_is_logged_and_still_throws(): void
    {
        $team = Team::factory()->create();

        try {
            app(RecordUsageEvent::class)->execute(
                teamId: $team->id,
                meterCode: 'ghost_meter',
                quantity: 1,
                eventKey: 'ghost-001',
            );
            $this->fail('Se esperaba ModelNotFoundException');
        } catch (ModelNotFoundException) {
            $this->addToAssertionCount(1);
        }

        $this->assertSystemLogged('billing.meter.missing', fn (array $c) => $c['outcome'] === 'degraded'
            && $c['reason'] === 'meter_missing'
            && $c['input']['team_id'] === $team->id
            && $c['input']['meter_code'] === 'ghost_meter'
            && $c['input']['event_key'] === 'ghost-001'
            && $c['input']['stage'] === 'record_usage');
        $this->assertSystemNotLogged('billing.usage.recorded');
        $this->assertSame(0, UsageEvent::withoutGlobalScopes()->count());
        $this->assertNoSensitiveDataLogged();
    }

    public function test_a_missing_meter_keeps_throwing_the_same_exception(): void
    {
        $team = Team::factory()->create();

        $this->expectException(ModelNotFoundException::class);

        app(RecordUsageEvent::class)->execute(
            teamId: $team->id,
            meterCode: 'ghost_meter',
            quantity: 1,
            eventKey: 'ghost-002',
        );
    }

    public function test_a_rolled_back_usage_is_never_logged_as_recorded(): void
    {
        Event::fake([UsageRecorded::class]);

        $team = Team::factory()->create();
        UsageMeter::factory()->create(['code' => 'api_requests']);

        try {
            DB::transaction(function () use ($team) {
                app(RecordUsageEvent::class)->execute(
                    teamId: $team->id,
                    meterCode: 'api_requests',
                    quantity: 3,
                    eventKey: 'req-rollback',
                );

                throw new RuntimeException('boom');
            });
        } catch (RuntimeException) {
            // la transacción revirtió
        }

        $this->assertSame(0, UsageEvent::withoutGlobalScopes()->count());
        $this->assertSystemNotLogged('billing.usage.recorded');
    }

    public function test_record_returns_whether_the_row_was_inserted(): void
    {
        Event::fake([UsageRecorded::class]);

        $team = Team::factory()->create();
        UsageMeter::factory()->create(['code' => 'api_requests']);

        $action = app(RecordUsageEvent::class);

        $this->assertTrue($action->record($team->id, 'api_requests', 2, 'req-record'));
        $this->assertFalse($action->record($team->id, 'api_requests', 2, 'req-record'));

        $this->assertSame(1, UsageEvent::withoutGlobalScopes()->where('event_key', 'req-record')->count());
        $this->assertCount(1, $this->systemLogEntries('billing.usage.recorded'));
        $this->assertCount(1, $this->systemLogEntries('billing.usage.duplicate_ignored'));
    }
}
