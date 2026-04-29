<?php

namespace App\Domains\Notifications\Data;

use App\Domains\Notifications\Enums\ChannelType;

class TenantNotificationPolicy
{
    /**
     * @param  array<int, ChannelType>  $allowedChannels
     * @param  array<int, ChannelType>  $criticalChannels
     * @param  array<int, ChannelType>  $fallbackChannels
     * @param  array<string, mixed>|null  $quietHours
     */
    public function __construct(
        public readonly array $allowedChannels,
        public readonly array $criticalChannels,
        public readonly array $fallbackChannels,
        public readonly ?array $quietHours = null,
    ) {}

    public static function defaults(): self
    {
        return new self(
            allowedChannels: [ChannelType::Email, ChannelType::Web],
            criticalChannels: [ChannelType::Email, ChannelType::Web, ChannelType::Sms, ChannelType::Push],
            fallbackChannels: [ChannelType::Email],
            quietHours: null,
        );
    }
}
