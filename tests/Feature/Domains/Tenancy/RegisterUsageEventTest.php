<?php

namespace Tests\Feature\Domains\Tenancy;

use App\Domains\Tenancy\Actions\RecordUsageEvent;
use App\Domains\Tenancy\Actions\RegisterUsageEvent;
use App\Domains\Tenancy\Models\UsageMeter;
use App\Models\Team;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Mockery;
use Tests\TestCase;

class RegisterUsageEventTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_delegates_to_record_usage_event_with_all_arguments(): void
    {
        $team = Team::factory()->create();
        UsageMeter::factory()->create(['code' => 'api_requests']);

        $mock = Mockery::mock(RecordUsageEvent::class);
        $occurredAt = now();
        $metadata = ['source' => 'unit-test'];

        $mock->shouldReceive('execute')
            ->once()
            ->with(
                $team->id,
                'api_requests',
                7,
                'evt-register-1',
                $metadata,
                $occurredAt,
            );

        $this->app->instance(RecordUsageEvent::class, $mock);

        app(RegisterUsageEvent::class)->execute(
            teamId: $team->id,
            meterCode: 'api_requests',
            quantity: 7,
            eventKey: 'evt-register-1',
            metadata: $metadata,
            occurredAt: $occurredAt,
        );
    }

    public function test_it_passes_null_defaults_through(): void
    {
        $team = Team::factory()->create();
        UsageMeter::factory()->create(['code' => 'ai_calls']);

        $mock = Mockery::mock(RecordUsageEvent::class);

        $mock->shouldReceive('execute')
            ->once()
            ->with(
                $team->id,
                'ai_calls',
                3,
                'evt-register-defaults',
                null,
                null,
            );

        $this->app->instance(RecordUsageEvent::class, $mock);

        app(RegisterUsageEvent::class)->execute(
            teamId: $team->id,
            meterCode: 'ai_calls',
            quantity: 3,
            eventKey: 'evt-register-defaults',
        );
    }

    public function test_it_records_through_real_record_usage_event(): void
    {
        $team = Team::factory()->create();
        $meter = UsageMeter::factory()->create(['code' => 'register-real']);

        app(RegisterUsageEvent::class)->execute(
            teamId: $team->id,
            meterCode: 'register-real',
            quantity: 42,
            eventKey: 'real-evt-1',
        );

        $this->assertDatabaseHas('usage_events', [
            'team_id' => $team->id,
            'usage_meter_id' => $meter->id,
            'event_key' => 'real-evt-1',
            'quantity' => 42,
        ]);
    }
}
