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
        ?\DateTimeInterface $receivedAt = null,
    ): bool {
        // Fail-closed: sin la Secret Key de Samsara no hay firma que aceptar.
        if (! $endpoint->hasSecret()) {
            return false;
        }

        return $this->providerAdapter->validateWebhookSignature(
            $payload,
            $signature,
            (string) $endpoint->secret,
            $timestamp,
            $receivedAt,
        );
    }
}
