<?php

namespace App\Domains\Integrations\Jobs;

use App\Domains\Integrations\Actions\ProvisionSamsaraWebhook;
use App\Domains\Integrations\Models\TenantIntegration;
use App\Domains\Integrations\Models\WebhookEndpoint;
use App\Support\JobFailureReporter;
use App\Support\SystemLog;
use App\Support\TenantContext;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Throwable;

/**
 * Alta automática del webhook de Samsara en segundo plano (al conectar la
 * integración, al cambiar el token o desde el botón). Un fallo del proveedor
 * se reintenta; un token sin permisos no (sólo lo arregla otro token).
 */
class ProvisionSamsaraWebhookJob implements ShouldBeUnique, ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;

    /** @var array<int, int> */
    public array $backoff = [60, 300, 900];

    public function __construct(
        public readonly int $teamId,
        public readonly int $integrationId,
        public readonly bool $force = false,
    ) {
        $this->onQueue('sync');
    }

    public function uniqueId(): string
    {
        return "provision-samsara-webhook-{$this->integrationId}";
    }

    public function handle(ProvisionSamsaraWebhook $provision): void
    {
        $input = ['team_id' => $this->teamId, 'integration_id' => $this->integrationId];

        // Lookup de entrada sin scope: de la integración sale el tenant, y debe
        // ser el que trae el job. Ver §2.1.
        $integration = TenantIntegration::withoutGlobalScopes()->find($this->integrationId);

        if ($integration === null) {
            SystemLog::skipped('integrations.webhook.provision_skipped', reason: 'integration_missing', input: $input);

            return;
        }

        if ($integration->team_id !== $this->teamId) {
            SystemLog::skipped('integrations.webhook.provision_skipped', reason: 'team_mismatch', input: $input);

            return;
        }

        TenantContext::set($integration->team_id);

        $result = $provision->execute($integration, $this->force);

        if ($result === WebhookEndpoint::SETUP_STATUS_FAILED && $this->attempts() < $this->tries) {
            $this->release($this->backoff[$this->attempts() - 1] ?? 900);
        }
    }

    public function failed(Throwable $exception): void
    {
        JobFailureReporter::report(static::class, $exception, [
            'team_id' => $this->teamId,
            'integration_id' => $this->integrationId,
        ]);
    }
}
