<?php

namespace Tests\Feature\Domains\Notifications\Drivers;

use App\Contracts\Notifications\ChannelDriverRegistry as ChannelDriverRegistryContract;
use App\Domains\Notifications\Channels\MailNotificationDriver;
use App\Domains\Notifications\Channels\PushNotificationDriver;
use App\Domains\Notifications\Channels\SlackNotificationDriver;
use App\Domains\Notifications\Channels\SmsNotificationDriver;
use App\Domains\Notifications\Channels\WebhookNotificationDriver;
use App\Domains\Notifications\Channels\WebNotificationDriver;
use App\Domains\Notifications\Channels\WhatsappNotificationDriver;
use App\Domains\Notifications\Enums\ChannelType;
use Tests\TestCase;

class ChannelDriverRegistryTest extends TestCase
{
    public function test_resolves_real_drivers_for_every_channel_type(): void
    {
        $registry = app(ChannelDriverRegistryContract::class);

        $this->assertInstanceOf(MailNotificationDriver::class, $registry->driverFor(ChannelType::Email));
        $this->assertInstanceOf(WebNotificationDriver::class, $registry->driverFor(ChannelType::Web));
        $this->assertInstanceOf(WebhookNotificationDriver::class, $registry->driverFor(ChannelType::Webhook));
        $this->assertInstanceOf(SlackNotificationDriver::class, $registry->driverFor(ChannelType::Slack));
        $this->assertInstanceOf(WhatsappNotificationDriver::class, $registry->driverFor(ChannelType::Whatsapp));
        $this->assertInstanceOf(SmsNotificationDriver::class, $registry->driverFor(ChannelType::Sms));
        $this->assertInstanceOf(PushNotificationDriver::class, $registry->driverFor(ChannelType::Push));
    }
}
