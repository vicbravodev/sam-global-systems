<?php

namespace App\Domains\Integrations\Actions;

use App\Domains\Audit\Actions\RecordAuditEntry;
use App\Domains\Audit\Enums\AuditActorType;
use App\Domains\Audit\Enums\AuditCategory;
use App\Domains\Integrations\Contracts\ProviderAdapter;
use App\Domains\Integrations\Exceptions\ProviderRequestFailedException;
use App\Domains\Integrations\Models\TenantIntegration;
use App\Domains\Integrations\Models\WebhookEndpoint;
use App\Models\User;
use App\Support\SystemLog;
use App\Support\TenantContext;
use Illuminate\Support\Str;

/**
 * Rota la Secret Key de un webhook que SAM aprovisionó. Samsara no regenera la
 * llave de un webhook existente, así que se crea otro, la alerta de pánico de
 * SAM pasa a entregar a él y el viejo se borra. La llave anterior sigue
 * valiendo {@see WebhookEndpoint::ROTATION_GRACE_MINUTES} minutos para las
 * entregas que ya iban en camino.
 */
class RotateSamsaraWebhookSecret
{
    public const string ROTATED = 'rotated';

    public const string SKIPPED = 'skipped';

    public const string FAILED = 'failed';

    public function __construct(
        private readonly ProviderAdapter $providerAdapter,
        private readonly RecordAuditEntry $audit,
    ) {}

    /**
     * @return string `rotated`, `skipped` o `failed`
     */
    public function execute(TenantIntegration $integration, User $actor, ?string $ipAddress = null, ?string $userAgent = null): string
    {
        return TenantContext::for($integration->team_id, function () use ($integration, $actor, $ipAddress, $userAgent): string {
            $input = ['team_id' => $integration->team_id, 'integration_id' => $integration->id];

            $endpoint = WebhookEndpoint::query()
                ->where('tenant_integration_id', $integration->id)
                ->orderBy('id')
                ->first();

            if ($endpoint === null || ! $endpoint->isProvisioned() || $endpoint->provider_alert_configuration_id === null) {
                SystemLog::skipped('integrations.webhook.rotated', reason: 'not_automatic', input: $input);

                return self::SKIPPED;
            }

            $input['webhook_endpoint_id'] = $endpoint->id;
            $oldWebhookId = (string) $endpoint->provider_webhook_id;

            try {
                $webhook = $this->providerAdapter->createWebhook($integration, 'SAM – '.$this->teamName($integration), ProvisionSamsaraWebhook::publicUrl($endpoint));
            } catch (ProviderRequestFailedException $e) {
                return $this->failed($e, $input, step: 'create_webhook');
            }

            try {
                $this->providerAdapter->pointAlertConfigurationToWebhook($integration, $endpoint->provider_alert_configuration_id, $webhook['id']);
            } catch (ProviderRequestFailedException $e) {
                $this->deleteQuietly($integration, $webhook['id'], $input, 'compensation');

                return $this->failed($e, $input, step: 'point_alert');
            }

            $endpoint->forceFill([
                'previous_secret' => $endpoint->secret,
                'previous_secret_expires_at' => now()->addMinutes(WebhookEndpoint::ROTATION_GRACE_MINUTES),
                'secret' => $webhook['secret'],
                'secret_configured_at' => now(),
                'provider_webhook_id' => $webhook['id'],
            ])->save();

            $oldDeleted = $this->deleteQuietly($integration, $oldWebhookId, $input, 'old_webhook');

            $this->audit->execute(
                actorType: AuditActorType::User,
                actorId: $actor->id,
                action: 'integration.webhook_secret.rotated',
                category: AuditCategory::Integration,
                entityType: 'WebhookEndpoint',
                entityId: $endpoint->id,
                summary: "Secret Key del webhook rotada para la integración {$integration->id}",
                teamId: $integration->team_id,
                metadata: ['tenant_integration_id' => $integration->id, 'webhook_endpoint_id' => $endpoint->id],
                sourceType: 'tenant_integration',
                sourceReferenceId: (string) $integration->id,
                signature: 'audit:webhook_rotated:'.$endpoint->id.':'.Str::uuid()->toString(),
                ipAddress: $ipAddress,
                userAgent: $userAgent,
            );

            SystemLog::ok('integrations.webhook.rotated', input: $input, calc: [
                'grace_minutes' => WebhookEndpoint::ROTATION_GRACE_MINUTES,
            ], result: ['old_webhook_deleted' => $oldDeleted]);

            return self::ROTATED;
        });
    }

    private function teamName(TenantIntegration $integration): string
    {
        $name = $integration->team()->value('name');

        return is_string($name) && $name !== '' ? $name : 'Cuenta '.$integration->team_id;
    }

    /**
     * @param  array<string, int>  $input
     */
    private function deleteQuietly(TenantIntegration $integration, string $webhookId, array $input, string $which): bool
    {
        try {
            $this->providerAdapter->deleteWebhook($integration, $webhookId);

            return true;
        } catch (ProviderRequestFailedException $e) {
            SystemLog::degraded('integrations.webhook.rotation_cleanup_failed', reason: 'provider_error', input: $input, calc: ['which' => $which], error: $e);

            return false;
        }
    }

    /**
     * @param  array<string, int>  $input
     */
    private function failed(ProviderRequestFailedException $e, array $input, string $step): string
    {
        SystemLog::failed('integrations.webhook.rotated', reason: $e->isUnauthorized() ? 'missing_permissions' : 'provider_error', input: $input, calc: [
            'step' => $step,
            'status' => $e->status,
        ], error: $e);

        return self::FAILED;
    }
}
