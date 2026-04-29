<?php

namespace App\Domains\Notifications\Data;

use App\Domains\Notifications\Enums\RecipientType;

class RecipientDescriptor
{
    /**
     * @param  array<string, mixed>|null  $metadata
     */
    public function __construct(
        public readonly RecipientType $recipientType,
        public readonly string $address,
        public readonly ?string $name = null,
        public readonly ?string $referenceId = null,
        public readonly ?string $channelPreference = null,
        public readonly ?string $role = null,
        public readonly ?array $metadata = null,
    ) {}
}
