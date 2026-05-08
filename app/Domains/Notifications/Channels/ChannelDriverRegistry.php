<?php

namespace App\Domains\Notifications\Channels;

use App\Contracts\Notifications\ChannelDriverRegistry as ChannelDriverRegistryContract;
use App\Contracts\Notifications\NotificationDriver;
use App\Contracts\NullImplementations\NullNotificationDriver;
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
            // SPEC-13-CHANNEL-DEFERRED: SMS/Push/Whatsapp/Slack drivers ship in
            // PR #2b/#2c. Fall back to NullNotificationDriver until then.
            default => $this->container->make(NullNotificationDriver::class),
        };
    }
}
