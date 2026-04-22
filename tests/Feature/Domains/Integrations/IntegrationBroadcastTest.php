<?php

namespace Tests\Feature\Domains\Integrations;

use App\Domains\Integrations\Events\IntegrationStatusChanged;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use Tests\TestCase;

class IntegrationBroadcastTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_broadcasts_integration_status_changed_on_activation(): void
    {
        Event::fake([IntegrationStatusChanged::class]);

        IntegrationStatusChanged::dispatch(1, 10, 'samsara', 'active');

        Event::assertDispatched(IntegrationStatusChanged::class, function ($event) {
            $this->assertEquals(
                1,
                $event->teamId,
                'Broadcast event should carry the correct teamId',
            );

            $this->assertEquals(
                10,
                $event->integrationId,
                'Broadcast event should carry the correct integrationId',
            );

            $this->assertEquals(
                'samsara',
                $event->providerCode,
                'Broadcast event should carry the correct providerCode',
            );

            $this->assertEquals(
                'active',
                $event->status,
                'Broadcast event should carry the active status',
            );

            $channels = $event->broadcastOn();
            $this->assertCount(
                1,
                $channels,
                'IntegrationStatusChanged should broadcast on exactly one channel',
            );

            $this->assertInstanceOf(
                PrivateChannel::class,
                $channels[0],
                'IntegrationStatusChanged should broadcast on a PrivateChannel',
            );

            $this->assertEquals(
                'integration.status_changed',
                $event->broadcastAs(),
                'Broadcast event alias should be integration.status_changed',
            );

            $broadcastData = $event->broadcastWith();
            $this->assertEquals(10, $broadcastData['integration_id']);
            $this->assertEquals('samsara', $broadcastData['provider_code']);
            $this->assertEquals('active', $broadcastData['status']);

            return true;
        });
    }

    public function test_it_broadcasts_integration_status_changed_on_error(): void
    {
        Event::fake([IntegrationStatusChanged::class]);

        IntegrationStatusChanged::dispatch(5, 20, 'motive', 'error');

        Event::assertDispatched(IntegrationStatusChanged::class, function ($event) {
            $this->assertEquals(
                'error',
                $event->status,
                'Broadcast event should carry the error status when integration encounters an error',
            );

            $this->assertEquals(
                5,
                $event->teamId,
                'Broadcast event teamId should match the team that owns the integration',
            );

            $this->assertEquals(
                'motive',
                $event->providerCode,
                'Broadcast event should carry the correct provider code for error status change',
            );

            return true;
        });
    }
}
