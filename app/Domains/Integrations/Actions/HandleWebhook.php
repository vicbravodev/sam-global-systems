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
     * @param  array<string, mixed>  $payload
     */
    public function execute(WebhookEndpoint $endpoint, string $eventType, array $payload): WebhookEvent
    {
        $integration = $endpoint->tenantIntegration;

        $webhookEvent = WebhookEvent::withoutGlobalScopes()->create([
            'team_id' => $integration->team_id,
            'provider_id' => $integration->provider_id,
            'event_type' => $eventType,
            'payload_json' => $payload,
            'received_at' => now(),
            'status' => WebhookEventStatus::Received,
        ]);

        $endpoint->update(['last_received_at' => now()]);

        WebhookReceived::dispatch($integration->team_id, $webhookEvent->id, $eventType);

        ProcessWebhookEventJob::dispatch($webhookEvent, $endpoint);

        return $webhookEvent;
    }
}
