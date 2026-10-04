<?php

namespace App\Domains\Integrations\Actions;

use App\Domains\Audit\Actions\RecordAuditEntry;
use App\Domains\Audit\Enums\AuditActorType;
use App\Domains\Audit\Enums\AuditCategory;
use App\Domains\Integrations\Contracts\ProviderAdapter;
use App\Domains\Integrations\Exceptions\ProviderRequestFailedException;
use App\Domains\Integrations\Models\TenantIntegration;
use App\Domains\Integrations\Models\WebhookEndpoint;
use App\Support\SafeErrorMessage;
use App\Support\SystemLog;
use App\Support\TenantContext;
use Illuminate\Support\Str;

/**
 * Da de alta, con el token del cliente, el webhook de SAM en su cuenta de
 * Samsara y una alerta propia de botón de pánico que entrega a ese webhook. La
 * Secret Key que Samsara devuelve se guarda cifrada y nunca se registra: el
 * cliente ya no tiene que copiarla.
 *
 * - No toca alertas que el cliente ya tenga: la de SAM es suya y se borra al
 *   desconectar.
 * - Respeta un flujo manual que ya recibe webhooks válidos, salvo que lo pida
 *   el botón "Configurar automáticamente" (`$force`).
 * - Sin permisos de escritura (401/403) queda `missing_permissions` y el flujo
 *   manual sigue disponible tal cual.
 * - Si la alerta falla tras crear el webhook, se borra el webhook.
 *
 * @see https://developers.samsara.com/reference/postwebhooks
 */
class ProvisionSamsaraWebhook
{
    public const string ALERT_NAME = 'SAM – Botón de pánico';

    public const string SKIPPED = 'skipped';

    public function __construct(
        private readonly ProviderAdapter $providerAdapter,
        private readonly RecordAuditEntry $audit,
    ) {}

    /**
     * @return string `skipped`, `provisioned`, `missing_permissions` o `failed`
     */
    public function execute(TenantIntegration $integration, bool $force = false): string
    {
        return TenantContext::for($integration->team_id, function () use ($integration, $force): string {
            $input = ['team_id' => $integration->team_id, 'integration_id' => $integration->id];
            $integration->loadMissing('provider');

            $skip = match (true) {
                $integration->provider?->code !== 'samsara' => 'not_samsara',
                ! $integration->isActive() => 'inactive',
                default => null,
            };

            if ($skip !== null) {
                return $this->skipped($skip, $input);
            }

            $endpoint = WebhookEndpoint::query()
                ->where('tenant_integration_id', $integration->id)
                ->orderBy('id')
                ->first() ?? WebhookEndpoint::create(['tenant_integration_id' => $integration->id]);
            $input['webhook_endpoint_id'] = $endpoint->id;

            if ($endpoint->isProvisioned()) {
                return $this->skipped('already_provisioned', $input);
            }

            if (! $force && $endpoint->setup_mode === WebhookEndpoint::SETUP_MANUAL && $endpoint->hasSecret() && $endpoint->last_valid_received_at !== null) {
                return $this->skipped('already_receiving', $input);
            }

            $url = self::publicUrl($endpoint);

            if (! str_starts_with($url, 'https://')) {
                return $this->skipped('public_url_not_https', $input);
            }

            try {
                $webhook = $this->providerAdapter->createWebhook($integration, 'SAM – '.$this->teamName($integration), $url);
            } catch (ProviderRequestFailedException $e) {
                return $this->failed($endpoint, $e, $input, compensated: false);
            }

            try {
                $configurationId = $this->providerAdapter->createPanicAlertConfiguration($integration, self::ALERT_NAME, $webhook['id']);
            } catch (ProviderRequestFailedException $e) {
                return $this->failed($endpoint, $e, $input, compensated: $this->compensate($integration, $webhook['id'], $input));
            }

            $endpoint->forceFill([
                'secret' => $webhook['secret'],
                'secret_configured_at' => now(),
                'setup_mode' => WebhookEndpoint::SETUP_AUTOMATIC,
                'setup_status' => WebhookEndpoint::SETUP_STATUS_PROVISIONED,
                'setup_error' => null,
                'provider_webhook_id' => $webhook['id'],
                'provider_alert_configuration_id' => $configurationId,
                'provisioned_at' => now(),
                'previous_secret' => null,
                'previous_secret_expires_at' => null,
            ])->save();

            $this->audit->execute(
                actorType: AuditActorType::System,
                actorId: null,
                action: 'integration.webhook.provisioned',
                category: AuditCategory::Integration,
                entityType: 'WebhookEndpoint',
                entityId: $endpoint->id,
                summary: "Webhook y alerta de pánico de SAM creados en Samsara para la integración {$integration->id}",
                teamId: $integration->team_id,
                metadata: [
                    'tenant_integration_id' => $integration->id,
                    'webhook_endpoint_id' => $endpoint->id,
                    'forced' => $force,
                ],
                sourceType: 'tenant_integration',
                sourceReferenceId: (string) $integration->id,
                signature: 'audit:webhook_provisioned:'.$endpoint->id.':'.Str::uuid()->toString(),
            );

            SystemLog::ok('integrations.webhook.provisioned', input: $input, calc: ['forced' => $force], result: [
                'setup_mode' => WebhookEndpoint::SETUP_AUTOMATIC,
                'has_alert_configuration' => true,
            ]);

            return WebhookEndpoint::SETUP_STATUS_PROVISIONED;
        });
    }

    /**
     * URL pública del endpoint: `services.samsara.webhook_base_url` si está,
     * si no `app.url`.
     */
    public static function publicUrl(WebhookEndpoint $endpoint): string
    {
        $base = config('services.samsara.webhook_base_url');
        $path = route('webhooks.handle', ['endpoint_url' => $endpoint->url], absolute: false);

        if (is_string($base) && $base !== '') {
            return rtrim($base, '/').$path;
        }

        return rtrim((string) config('app.url'), '/').$path;
    }

    private function teamName(TenantIntegration $integration): string
    {
        $name = $integration->team()->value('name');

        return is_string($name) && $name !== '' ? $name : 'Cuenta '.$integration->team_id;
    }

    /**
     * @param  array<string, int>  $input
     */
    private function skipped(string $reason, array $input): string
    {
        SystemLog::skipped('integrations.webhook.provision_skipped', reason: $reason, input: $input);

        return self::SKIPPED;
    }

    /**
     * @param  array<string, int>  $input
     */
    private function compensate(TenantIntegration $integration, string $webhookId, array $input): bool
    {
        try {
            $this->providerAdapter->deleteWebhook($integration, $webhookId);

            return true;
        } catch (ProviderRequestFailedException $e) {
            SystemLog::degraded('integrations.webhook.provision_compensation_failed', reason: 'provider_error', input: $input, error: $e);

            return false;
        }
    }

    /**
     * @param  array<string, int>  $input
     * @return 'missing_permissions'|'failed'
     */
    private function failed(WebhookEndpoint $endpoint, ProviderRequestFailedException $e, array $input, bool $compensated): string
    {
        $status = $e->isUnauthorized() ? WebhookEndpoint::SETUP_STATUS_MISSING_PERMISSIONS : WebhookEndpoint::SETUP_STATUS_FAILED;

        $endpoint->forceFill([
            'setup_status' => $status,
            'setup_error' => mb_substr(SafeErrorMessage::from($e), 0, 255),
        ])->save();

        SystemLog::failed('integrations.webhook.provision_failed', reason: $e->isUnauthorized() ? 'missing_permissions' : 'provider_error', input: $input, calc: [
            'endpoint' => $e->endpoint,
            'status' => $e->status,
            'compensated' => $compensated,
        ], error: $e);

        return $status;
    }
}
