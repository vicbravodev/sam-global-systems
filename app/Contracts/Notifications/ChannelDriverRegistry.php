<?php

namespace App\Contracts\Notifications;

use App\Domains\Notifications\Enums\ChannelType;

interface ChannelDriverRegistry
{
    public function driverFor(ChannelType $channelType): NotificationDriver;
}
