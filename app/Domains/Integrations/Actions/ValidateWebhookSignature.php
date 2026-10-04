<?php

namespace App\Domains\Integrations\Actions;

use App\Domains\Integrations\Contracts\ProviderAdapter;
use App\Domains\Integrations\Models\WebhookEndpoint;
use App\Support\SystemLog;

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

        if ($this->providerAdapter->validateWebhookSignature($payload, $signature, (string) $endpoint->secret, $timestamp, $receivedAt)) {
            return true;
        }

        // Tras rotar la llave, Samsara pudo firmar con la anterior entregas que
        // ya iban en camino: valen mientras dura la gracia.
        $previous = $endpoint->previousSecretInGrace();

        if ($previous === null || ! $this->providerAdapter->validateWebhookSignature($payload, $signature, $previous, $timestamp, $receivedAt)) {
            return false;
        }

        SystemLog::ok('webhook.signature.previous_secret_used', input: ['webhook_endpoint_id' => $endpoint->id], calc: [
            'grace_expires_at' => $endpoint->previous_secret_expires_at?->toIso8601String(),
        ]);

        return true;
    }
}
