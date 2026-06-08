<?php

namespace App\Domains\Integrations\Jobs;

use App\Contracts\RawEventIngestion;
use App\Domains\Integrations\Actions\ValidateWebhookSignature;
use App\Domains\Integrations\Models\WebhookEndpoint;
use App\Domains\Integrations\Models\WebhookEvent;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

class ProcessWebhookEventJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;

    /** @var array<int, int> */
    public array $backoff = [30, 120, 300];

    public function __construct(
        public readonly WebhookEvent $webhookEvent,
        public readonly WebhookEndpoint $endpoint,
    ) {
        $this->onQueue('ingestion');
    }

    public function handle(
        ValidateWebhookSignature $validateSignature,
        RawEventIngestion $rawEventIngestion,
    ): void {
        $this->webhookEvent->markAsProcessing();

        $payload = $this->webhookEvent->payload_json;

        // Preferred path: validate against the exact raw body bytes and the
        // signature/timestamp headers captured at receipt (real Samsara scheme).
        $signature = $this->webhookEvent->signature;
        $timestamp = $this->webhookEvent->signature_timestamp;
        $rawPayload = $this->webhookEvent->raw_payload;

        // Legacy fallback for events persisted without the raw body (e.g. crafted
        // programmatically): the signature travelled inside the body and the HMAC
        // was computed over the re-encoded payload minus that signature field.
        if ($rawPayload === null) {
            $signature = (string) ($payload['signature'] ?? '');
            $rawPayload = (string) json_encode(collect($payload)->except('signature')->all());
        }

        $isValid = $validateSignature->execute(
            $this->endpoint,
            $rawPayload,
            (string) $signature,
            $timestamp,
        );

        if (! $isValid) {
            $this->webhookEvent->markAsInvalidSignature();

            return;
        }

        try {
            $integration = $this->endpoint->tenantIntegration;

            $rawEventIngestion->ingest(
                $integration->team_id,
                $integration->provider->code ?? 'unknown',
                $this->webhookEvent->event_type,
                $payload,
            );

            $this->webhookEvent->markAsProcessed();
        } catch (\Throwable $e) {
            $this->webhookEvent->markAsFailed($e->getMessage());

            throw $e;
        }
    }

    public function failed(\Throwable $exception): void
    {
        $this->webhookEvent->markAsFailed($exception->getMessage());
    }
}
