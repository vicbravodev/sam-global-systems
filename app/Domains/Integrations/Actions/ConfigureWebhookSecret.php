<?php

namespace App\Domains\Integrations\Actions;

use App\Domains\Audit\Actions\RecordAuditEntry;
use App\Domains\Audit\Enums\AuditActorType;
use App\Domains\Audit\Enums\AuditCategory;
use App\Domains\Integrations\Models\TenantIntegration;
use App\Domains\Integrations\Models\WebhookEndpoint;
use App\Models\User;
use App\Support\SystemLog;
use App\Support\TenantContext;
use Illuminate\Support\Str;

/**
 * Guarda la Secret Key que Samsara generó para el webhook del tenant. Samsara
 * no permite fijarla desde fuera, así que el tenant la copia de su panel a
 * SAM. Se guarda cifrada (cast `encrypted`) y nunca se registra ni devuelve:
 * la auditoría y el log sólo dicen que se configuró y si reemplazó otra.
 */
class ConfigureWebhookSecret
{
    public function __construct(
        private readonly RecordAuditEntry $audit,
    ) {}

    public function execute(
        TenantIntegration $integration,
        string $secret,
        User $actor,
        ?string $ipAddress = null,
        ?string $userAgent = null,
    ): WebhookEndpoint {
        $teamId = $integration->team_id;

        return TenantContext::for($teamId, function () use ($integration, $secret, $actor, $ipAddress, $userAgent, $teamId): WebhookEndpoint {
            // webhook_endpoints no lleva team_id: el tenant lo garantiza la
            // integración (ya autorizada contra el team actual por la Policy).
            $endpoint = WebhookEndpoint::query()
                ->where('tenant_integration_id', $integration->id)
                ->orderBy('id')
                ->first();

            $created = $endpoint === null;
            $endpoint ??= new WebhookEndpoint(['tenant_integration_id' => $integration->id]);
            $replacedExisting = $endpoint->hasSecret();

            $endpoint->forceFill([
                'secret' => $secret,
                'secret_configured_at' => now(),
            ])->save();

            $this->audit->execute(
                actorType: AuditActorType::User,
                actorId: $actor->id,
                action: 'integration.webhook_secret.updated',
                category: AuditCategory::Integration,
                entityType: 'WebhookEndpoint',
                entityId: $endpoint->id,
                summary: "Secret Key del webhook configurada para la integración {$integration->id}",
                teamId: $teamId,
                metadata: [
                    'tenant_integration_id' => $integration->id,
                    'webhook_endpoint_id' => $endpoint->id,
                    'replaced_existing' => $replacedExisting,
                    'endpoint_created' => $created,
                ],
                sourceType: 'tenant_integration',
                sourceReferenceId: (string) $integration->id,
                // Cada rotación es un hecho distinto: sin esto la firma por
                // defecto (acción + entidad) deduplicaría la segunda.
                signature: 'audit:webhook_secret:'.$endpoint->id.':'.Str::uuid()->toString(),
                ipAddress: $ipAddress,
                userAgent: $userAgent,
            );

            SystemLog::ok('integrations.webhook_secret.updated', input: [
                'team_id' => $teamId,
                'integration_id' => $integration->id,
                'webhook_endpoint_id' => $endpoint->id,
            ], result: [
                'replaced_existing' => $replacedExisting,
                'endpoint_created' => $created,
            ]);

            return $endpoint;
        });
    }
}
