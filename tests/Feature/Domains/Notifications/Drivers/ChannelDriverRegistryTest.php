<?php

namespace Tests\Feature\Domains\Notifications\Drivers;

use App\Contracts\Notifications\ChannelDriverRegistry as ChannelDriverRegistryContract;
use App\Contracts\NullImplementations\NullNotificationDriver;
use App\Domains\Notifications\Channels\MailNotificationDriver;
use App\Domains\Notifications\Channels\WebhookNotificationDriver;
use App\Domains\Notifications\Channels\WebNotificationDriver;
use App\Domains\Notifications\Enums\ChannelType;
use Tests\TestCase;

class ChannelDriverRegistryTest extends TestCase
{
    public function test_resolves_real_drivers_for_implemented_channels(): void
    {
        $registry = app(ChannelDriverRegistryContract::class);

        $this->assertInstanceOf(MailNotificationDriver::class, $registry->driverFor(ChannelType::Email));
        $this->assertInstanceOf(WebNotificationDriver::class, $registry->driverFor(ChannelType::Web));
        $this->assertInstanceOf(WebhookNotificationDriver::class, $registry->driverFor(ChannelType::Webhook));
    }

    public function test_falls_back_to_null_driver_for_deferred_channels(): void
    {
        $registry = app(ChannelDriverRegistryContract::class);

        foreach ([ChannelType::Sms, ChannelType::Push, ChannelType::Whatsapp, ChannelType::Slack] as $type) {
            $this->assertInstanceOf(
                NullNotificationDriver::class,
                $registry->driverFor($type),
                "channel {$type->value} should still fall back to NullNotificationDriver",
            );
        }
    }
}
