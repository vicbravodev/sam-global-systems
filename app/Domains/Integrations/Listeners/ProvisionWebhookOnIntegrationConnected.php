<?php

namespace App\Domains\Integrations\Listeners;

use App\Domains\Integrations\Events\IntegrationConnected;
use App\Domains\Integrations\Jobs\ProvisionSamsaraWebhookJob;
use App\Support\SystemLog;

/**
 * Al conectar Samsara, SAM intenta dar de alta su webhook y su alerta de
 * pánico. El job valida que la integración sea del tenant del evento.
 */
class ProvisionWebhookOnIntegrationConnected
{
    public function handle(IntegrationConnected $event): void
    {
        $input = ['team_id' => $event->teamId, 'integration_id' => $event->integrationId];

        if ($event->providerCode !== 'samsara') {
            SystemLog::skipped('integrations.webhook.provision_requested', reason: 'not_samsara', input: $input, debug: true);

            return;
        }

        ProvisionSamsaraWebhookJob::dispatch($event->teamId, $event->integrationId)->afterCommit();

        SystemLog::ok('integrations.webhook.provision_requested', input: $input, calc: ['trigger' => 'integration_connected']);
    }
}
