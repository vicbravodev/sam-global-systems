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
            // SPEC-13-CHANNEL-DEFERRED: SMS/Push/Whatsapp/Slack/Webhook drivers are
            // backed by NullNotificationDriver until provider integrations land in PR #2.
            default => $this->container->make(NullNotificationDriver::class),
        };
    }
}
