<?php

namespace App\Domains\Integrations\Jobs;

use App\Domains\Integrations\Adapters\SamsaraAdapter;
use App\Domains\Integrations\Exceptions\ProviderRequestFailedException;
use App\Domains\Integrations\Models\TenantIntegration;
use App\Support\JobFailureReporter;
use App\Support\SystemLog;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeEncrypted;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Throwable;

/**
 * Al desconectar una integración aprovisionada, borra en Samsara la alerta de
 * pánico y el webhook que SAM creó. Corre cuando la integración (y su token)
 * ya no existen, así que el job lleva el token: por eso va cifrado en la cola.
 * Un 404 cuenta como hecho. Si falla tras sus reintentos sólo se registra: lo
 * que queda en Samsara es un webhook que apunta a una URL que ya no existe.
 */
class DeprovisionSamsaraWebhookJob implements ShouldBeEncrypted, ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable;

    public int $tries = 3;

    /** @var array<int, int> */
    public array $backoff = [60, 300, 900];

    public function __construct(
        public readonly int $teamId,
        public readonly int $integrationId,
        #[\SensitiveParameter] public readonly string $apiToken,
        public readonly ?string $webhookId,
        public readonly ?string $alertConfigurationId,
    ) {
        $this->onQueue('sync');
    }

    public function handle(SamsaraAdapter $samsara): void
    {
        $input = ['team_id' => $this->teamId, 'integration_id' => $this->integrationId];

        // La fila ya no existe: una integración en memoria sólo para firmar las
        // llamadas con el token del cliente.
        $integration = new TenantIntegration(['credentials_encrypted' => $this->apiToken]);

        try {
            if ($this->alertConfigurationId !== null) {
                $samsara->deleteAlertConfiguration($integration, $this->alertConfigurationId);
            }

            if ($this->webhookId !== null) {
                $samsara->deleteWebhook($integration, $this->webhookId);
            }
        } catch (ProviderRequestFailedException $e) {
            if ($e->isUnauthorized() || $this->attempts() >= $this->tries) {
                SystemLog::degraded('integrations.webhook.deprovisioned', reason: $e->isUnauthorized() ? 'missing_permissions' : 'provider_error', input: $input, calc: [
                    'endpoint' => $e->endpoint,
                    'status' => $e->status,
                    'attempt' => $this->attempts(),
                ], error: $e);

                return;
            }

            throw $e;
        }

        SystemLog::ok('integrations.webhook.deprovisioned', input: $input, result: [
            'alert_configuration_deleted' => $this->alertConfigurationId !== null,
            'webhook_deleted' => $this->webhookId !== null,
        ]);
    }

    public function failed(Throwable $exception): void
    {
        JobFailureReporter::report(static::class, $exception, [
            'team_id' => $this->teamId,
            'integration_id' => $this->integrationId,
        ]);
    }
}
