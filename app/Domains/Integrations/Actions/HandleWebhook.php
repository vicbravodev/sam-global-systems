<?php

namespace App\Domains\Integrations\Actions;

use App\Domains\Integrations\Enums\WebhookEventStatus;
use App\Domains\Integrations\Events\WebhookReceived;
use App\Domains\Integrations\Jobs\ProcessWebhookEventJob;
use App\Domains\Integrations\Models\WebhookEndpoint;
use App\Domains\Integrations\Models\WebhookEvent;
use App\Support\LoggableCode;
use App\Support\PipelineTrace;
use App\Support\SystemLog;

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

        // Punto de entrada: un webhook es UN evento, y su traza la reclama el
        // RawEvent que se guarde al procesarlo (ProcessWebhookEventJob).
        PipelineTrace::beginEvent($integration->team_id, $integration->provider?->code);

        $webhookEvent = WebhookEvent::query()->create([
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

        PipelineTrace::add(['webhook_event_id' => $webhookEvent->id]);

        // Only sizes and header presence: never the body, signature or timestamp.
        $loggableEventType = LoggableCode::guard($eventType);

        SystemLog::ok('webhook.event.received', input: [
            'webhook_event_id' => $webhookEvent->id,
            'event_type' => $loggableEventType,
            'event_type_valid' => $loggableEventType !== null,
        ], calc: [
            'body_bytes' => strlen($rawPayload ?? ''),
            'has_signature_header' => $signature !== null && $signature !== '',
            'has_timestamp_header' => $signatureTimestamp !== null && $signatureTimestamp !== '',
        ]);

        // La salud del endpoint NO se toca aquí: recibir no prueba nada hasta
        // validar la firma. ProcessWebhookEventJob deja la marca válida o la
        // de rechazo (WebhookEndpoint::recordValidDelivery/recordRejection).

        WebhookReceived::dispatch($integration->team_id, $webhookEvent->id, $eventType);

        ProcessWebhookEventJob::dispatch($webhookEvent, $endpoint);

        return $webhookEvent;
    }
}
