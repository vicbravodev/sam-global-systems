<?php

namespace App\Domains\Notifications\Channels;

use App\Contracts\Notifications\ChannelDriverRegistry as ChannelDriverRegistryContract;
use App\Contracts\Notifications\NotificationDriver;
use App\Domains\Notifications\Enums\ChannelType;
use Illuminate\Contracts\Container\Container;

class ChannelDriverRegistry implements ChannelDriverRegistryContract
{
    public function __construct(
        private readonly Container $container,
    ) {}

    public function driverFor(ChannelType $channelType): NotificationDriver
    {
        return match ($channelType) {
            ChannelType::Email => $this->container->make(MailNotificationDriver::class),
            ChannelType::Web => $this->container->make(WebNotificationDriver::class),
            ChannelType::Webhook => $this->container->make(WebhookNotificationDriver::class),
            ChannelType::Slack => $this->container->make(SlackNotificationDriver::class),
            ChannelType::Whatsapp => $this->container->make(WhatsappNotificationDriver::class),
            ChannelType::Sms => $this->container->make(SmsNotificationDriver::class),
            ChannelType::Push => $this->container->make(PushNotificationDriver::class),
        };
    }
}
