<?php

namespace App\Domains\Notifications\Data;

use App\Domains\Notifications\Enums\ChannelType;

class RenderedNotification
{
    /**
     * @param  array<string, mixed>  $variables
     */
    public function __construct(
        public readonly ChannelType $channelType,
        public readonly string $address,
        public readonly ?string $subject,
        public readonly string $body,
        public readonly array $variables = [],
        public readonly ?string $recipientName = null,
    ) {}
}
