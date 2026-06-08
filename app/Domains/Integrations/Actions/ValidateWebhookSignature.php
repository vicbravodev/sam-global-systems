<?php

namespace App\Domains\Integrations\Actions;

use App\Domains\Integrations\Contracts\ProviderAdapter;
use App\Domains\Integrations\Models\WebhookEndpoint;

class ValidateWebhookSignature
{
    public function __construct(
        private ProviderAdapter $providerAdapter,
    ) {}

    public function execute(
        WebhookEndpoint $endpoint,
        string $payload,
        string $signature,
        ?string $timestamp = null,
    ): bool {
        return $this->providerAdapter->validateWebhookSignature(
            $payload,
            $signature,
            $endpoint->secret,
            $timestamp,
        );
    }
}
