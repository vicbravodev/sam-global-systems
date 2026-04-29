<?php

namespace App\Domains\Notifications\Data;

class DeliveryResult
{
    /**
     * @param  array<string, mixed>|null  $response
     */
    public function __construct(
        public readonly bool $success,
        public readonly ?string $providerMessageId = null,
        public readonly ?array $response = null,
        public readonly ?string $errorMessage = null,
    ) {}

    public static function success(?string $providerMessageId = null, ?array $response = null): self
    {
        return new self(true, $providerMessageId, $response);
    }

    public static function failure(string $errorMessage, ?array $response = null): self
    {
        return new self(false, null, $response, $errorMessage);
    }
}
