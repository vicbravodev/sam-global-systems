<?php

namespace App\Domains\Integrations\Jobs;

use App\Contracts\RawEventIngestion;
use App\Domains\Integrations\Actions\ValidateWebhookSignature;
use App\Domains\Integrations\Models\WebhookEndpoint;
use App\Domains\Integrations\Models\WebhookEvent;
use App\Models\Team;
use App\Support\LoggableCode;
use App\Support\SystemLog;
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
        // Eventos encolados antes de dar de baja al tenant: no se ingieren.
        if (! Team::query()->whereKey($this->webhookEvent->team_id)->exists()) {
            $this->webhookEvent->markAsFailed('Tenant dado de baja: evento descartado.');
            SystemLog::skipped('webhook.event.discarded', reason: 'tenant_deleted', input: ['webhook_event_id' => $this->webhookEvent->id]);

            return;
        }

        $this->webhookEvent->markAsProcessing();

        $payload = $this->webhookEvent->payload_json;

        // Preferred path: validate against the exact raw body bytes and the
        // signature/timestamp headers captured at receipt (real Samsara scheme).
        $signature = $this->webhookEvent->signature;
        $timestamp = $this->webhookEvent->signature_timestamp;
        $rawPayload = $this->webhookEvent->raw_payload;
        $signatureMode = $rawPayload === null ? 'legacy_body' : 'raw_header';

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
            $this->webhookEvent->received_at,
        );

        if (! $isValid) {
            $this->webhookEvent->markAsInvalidSignature();
            // event_type viene de la petición sin autenticar: sólo se registra si parece un código.
            $eventType = LoggableCode::guard($this->webhookEvent->event_type);

            SystemLog::skipped('webhook.event.rejected', reason: 'invalid_signature', input: [
                'webhook_event_id' => $this->webhookEvent->id,
                'signature_mode' => $signatureMode,
                'event_type' => $eventType,
                'event_type_valid' => $eventType !== null,
            ]);

            return;
        }

        try {
            $integration = $this->endpoint->tenantIntegration;

            $providerCode = $integration->provider->code ?? 'unknown';

            $rawEventIngestion->ingest(
                $integration->team_id,
                $providerCode,
                $this->webhookEvent->event_type,
                $payload,
            );

            $this->webhookEvent->markAsProcessed();

            // event_type puede venir de la query string, fuera del HMAC.
            $eventType = LoggableCode::guard($this->webhookEvent->event_type);

            SystemLog::ok('webhook.event.ingested', input: [
                'webhook_event_id' => $this->webhookEvent->id,
                'event_type' => $eventType,
                'event_type_valid' => $eventType !== null,
                'signature_mode' => $signatureMode,
                'provider_code' => LoggableCode::guard($providerCode),
            ], result: ['provider_code_fallback' => $integration->provider?->code === null]);
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
