<?php

namespace Tests\Feature\Domains\Tenancy;

use App\Domains\Tenancy\Actions\RecordUsageEvent;
use App\Domains\Tenancy\Events\UsageRecorded;
use App\Domains\Tenancy\Models\UsageEvent;
use App\Domains\Tenancy\Models\UsageMeter;
use App\Models\Team;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use Tests\TestCase;

class RecordUsageEventTest extends TestCase
{
    use RefreshDatabase;

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
    }
}
