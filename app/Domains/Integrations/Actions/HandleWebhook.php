<?php

namespace App\Domains\Integrations\Actions;

use App\Domains\Integrations\Enums\WebhookEventStatus;
use App\Domains\Integrations\Events\WebhookReceived;
use App\Domains\Integrations\Jobs\ProcessWebhookEventJob;
use App\Domains\Integrations\Models\WebhookEndpoint;
use App\Domains\Integrations\Models\WebhookEvent;

class HandleWebhook
{
    /**
     * Persist the webhook event immediately and dispatch async processing.
     *
     * @param  array<string, mixed>  $payload  Parsed body, stored for inspection.
     * @param  string|null  $rawPayload  Exact raw body bytes, required to verify the HMAC byte-for-byte.
     * @param  string|null  $signature  Provider signature header (e.g. "X-Samsara-Signature").
     * @param  string|null  $signatureTimestamp  Provider timestamp header (e.g. "X-Samsara-Timestamp").
     */
    public function execute(
        WebhookEndpoint $endpoint,
        string $eventType,
        array $payload,
        ?string $rawPayload = null,
        ?string $signature = null,
        ?string $signatureTimestamp = null,
    ): WebhookEvent {
        $integration = $endpoint->tenantIntegration;

        $webhookEvent = WebhookEvent::withoutGlobalScopes()->create([
            'team_id' => $integration->team_id,
            'provider_id' => $integration->provider_id,
            'event_type' => $eventType,
            'payload_json' => $payload,
            'signature' => $signature,
            'signature_timestamp' => $signatureTimestamp,
            'raw_payload' => $rawPayload,
            'received_at' => now(),
            'status' => WebhookEventStatus::Received,
        ]);

        $endpoint->update(['last_received_at' => now()]);

        WebhookReceived::dispatch($integration->team_id, $webhookEvent->id, $eventType);

        ProcessWebhookEventJob::dispatch($webhookEvent, $endpoint);

        return $webhookEvent;
    }
}
