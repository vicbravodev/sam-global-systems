<?php

namespace App\Domains\Notifications\Data;

final readonly class WebPushTarget
{
    public function __construct(
        public int $subscriptionId,
        public string $endpoint,
        public string $publicKey,
        public string $authToken,
        public string $contentEncoding,
    ) {}
}
