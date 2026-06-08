<?php

namespace App\Http\Controllers\Integrations;

use App\Domains\Integrations\Enums\AuthType;
use App\Domains\Integrations\Enums\IntegrationProviderStatus;
use App\Domains\Integrations\Enums\TenantIntegrationStatus;
use App\Domains\Integrations\Models\IntegrationProvider;
use App\Domains\Integrations\Models\TenantIntegration;
use App\Domains\Integrations\Models\WebhookEndpoint;
use App\Http\Controllers\Controller;
use App\Models\Team;
use Inertia\Inertia;
use Inertia\Response;

class IntegrationPageController extends Controller
{
    /**
     * Maps the persisted integration status to the dashboard health vocabulary
     * (drives the bg-health-* dots reused from the dashboard panel).
     */
    private const HEALTH_MAP = [
        TenantIntegrationStatus::Active->value => 'ok',
        TenantIntegrationStatus::Pending->value => 'warn',
        TenantIntegrationStatus::Error->value => 'down',
        TenantIntegrationStatus::Inactive->value => 'unknown',
    ];

    /**
     * Render the integrations management page with the tenant's connected
     * integrations and the catalog of providers available for connection.
     */
    public function index(Team $current_team): Response
    {
        $this->authorize('viewAny', TenantIntegration::class);

        $integrations = TenantIntegration::query()
            ->with(['provider', 'webhookEndpoint'])
            ->where('team_id', $current_team->id)
            ->orderByDesc('id')
            ->get();

        return Inertia::render('integrations/index', [
            'integrations' => $integrations
                ->map(fn (TenantIntegration $integration) => $this->presentIntegration($integration))
                ->all(),
            'providers' => fn () => $this->availableProviders(),
            'authTypes' => fn () => $this->authTypes(),
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    private function presentIntegration(TenantIntegration $integration): array
    {
        $endpoint = $integration->webhookEndpoint;

        return [
            'id' => (int) $integration->id,
            'name' => (string) $integration->name,
            'provider' => (string) ($integration->provider?->name ?? '—'),
            'providerCode' => (string) ($integration->provider?->code ?? ''),
            'status' => $integration->status->value,
            'health' => self::HEALTH_MAP[$integration->status->value] ?? 'unknown',
            'authType' => $integration->auth_type->value,
            'config' => $integration->config_json ?? null,
            'lastSyncAt' => $integration->last_sync_at?->toIso8601String(),
            'lastErrorAt' => $integration->last_error_at?->toIso8601String(),
            'lastErrorMessage' => $integration->last_error_message,
            'webhook' => $endpoint ? $this->presentWebhook($endpoint) : null,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function presentWebhook(WebhookEndpoint $endpoint): array
    {
        return [
            'url' => route('webhooks.handle', ['endpoint_url' => $endpoint->url]),
            'status' => (string) $endpoint->status,
            'lastReceivedAt' => $endpoint->last_received_at?->toIso8601String(),
        ];
    }

    /**
     * Providers the tenant can connect to: anything not deprecated.
     *
     * @return array<int, array<string, mixed>>
     */
    private function availableProviders(): array
    {
        return IntegrationProvider::query()
            ->whereNot('status', IntegrationProviderStatus::Deprecated)
            ->orderBy('name')
            ->get(['id', 'code', 'name', 'type', 'capabilities_json'])
            ->map(fn (IntegrationProvider $provider) => [
                'id' => (int) $provider->id,
                'code' => (string) $provider->code,
                'name' => (string) $provider->name,
                'type' => $provider->type->value,
                'capabilities' => $provider->capabilities_json ?? [],
            ])
            ->all();
    }

    /**
     * Auth strategies offered in the connect form.
     *
     * @return array<int, array<string, string>>
     */
    private function authTypes(): array
    {
        return array_map(
            fn (AuthType $type) => ['value' => $type->value, 'label' => $this->authTypeLabel($type)],
            AuthType::cases(),
        );
    }

    private function authTypeLabel(AuthType $type): string
    {
        return match ($type) {
            AuthType::ApiKey => 'API Key',
            AuthType::Oauth2 => 'OAuth 2.0',
            AuthType::BasicAuth => 'Basic Auth',
            AuthType::Token => 'Token',
        };
    }
}
