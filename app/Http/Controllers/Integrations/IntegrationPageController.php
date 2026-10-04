<?php

namespace App\Http\Controllers\Integrations;

use App\Contracts\Normalization\NormalizedEventStatsQuery;
use App\Domains\Assets\Enums\AssetMonitoringState;
use App\Domains\Assets\Models\Asset;
use App\Domains\Assets\Models\TelematicsFeedCursor;
use App\Domains\Drivers\Models\Driver;
use App\Domains\Integrations\Enums\AuthType;
use App\Domains\Integrations\Enums\IntegrationProblem;
use App\Domains\Integrations\Enums\IntegrationProviderStatus;
use App\Domains\Integrations\Enums\TenantIntegrationStatus;
use App\Domains\Integrations\Models\IntegrationProvider;
use App\Domains\Integrations\Models\TenantIntegration;
use App\Domains\Integrations\Models\WebhookEndpoint;
use App\Http\Controllers\Controller;
use App\Models\Team;
use App\Models\User;
use Illuminate\Container\Attributes\CurrentUser;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;
use Inertia\Response;

class IntegrationPageController extends Controller
{
    public function __construct(
        private readonly NormalizedEventStatsQuery $eventStats,
    ) {}

    /**
     * Render the integrations management page with the tenant's connected
     * integrations, a tenant-wide pulse and the catalog of providers
     * available for connection.
     */
    public function index(Team $current_team, #[CurrentUser] User $user): Response
    {
        $this->authorize('viewAny', TenantIntegration::class);

        $integrations = TenantIntegration::query()
            ->with(['provider', 'webhookEndpoint'])
            ->where('team_id', $current_team->id)
            ->orderByDesc('id')
            ->get();

        $events24h = $this->eventStats->countByIntegrationSince($current_team->id, now()->subDay());
        $fleet = $this->fleetByProvider($current_team->id);
        $liveData = $this->newestLiveDataByIntegration($current_team->id);

        // Fleet counts are per provider (assets/drivers carry provider_id, not
        // the integration). Only attribute them to a card when it is the sole
        // integration of that provider, so two Samsara accounts never both
        // claim the same units.
        $integrationsPerProvider = $integrations->countBy('provider_id');

        return Inertia::render('integrations/index', [
            'integrations' => $integrations
                ->map(fn (TenantIntegration $integration) => $this->presentIntegration(
                    $integration,
                    $user->can('update', $integration),
                    $events24h[$integration->id] ?? 0,
                    $liveData[$integration->id] ?? null,
                    ($integrationsPerProvider[$integration->provider_id] ?? 0) === 1
                        ? ($fleet[$integration->provider_id] ?? ['assets' => 0, 'monitored' => 0, 'drivers' => 0])
                        : null,
                ))
                ->all(),
            'summary' => $this->summary($integrations, $events24h, $fleet),
            'providers' => fn () => $this->availableProviders(),
            'authTypes' => fn () => $this->authTypes(),
        ]);
    }

    /**
     * @param  array{assets: int, monitored: int, drivers: int}|null  $fleet
     * @return array<string, mixed>
     */
    private function presentIntegration(TenantIntegration $integration, bool $canUpdate, int $events24h, ?string $liveDataAt, ?array $fleet): array
    {
        $endpoint = $integration->webhookEndpoint;
        $problem = IntegrationProblem::classify($integration->last_error_message);

        return [
            'id' => $integration->id,
            'name' => $integration->name,
            'provider' => $integration->provider?->name ?? '—',
            'providerCode' => $integration->provider?->code ?? '',
            'capabilities' => array_values($integration->provider?->capabilities_json ?? []),
            'status' => $integration->status->value,
            'health' => $integration->status->healthKey(),
            'authType' => $integration->auth_type->value,
            'authTypeLabel' => $this->authTypeLabel($integration->auth_type),
            // Allowlist only: config_json may hold provider secrets.
            'config' => $integration->publicConfig(),
            'connectedAt' => $integration->created_at?->toIso8601String(),
            'lastSyncAt' => $integration->last_sync_at?->toIso8601String(),
            // Newest point the live feed delivered; the legacy poll column is
            // only a fallback for integrations that predate the feed.
            'lastLocationAt' => $liveDataAt ?? $integration->last_location_poll_at?->toIso8601String(),
            'lastErrorAt' => $integration->last_error_at?->toIso8601String(),
            'lastErrorMessage' => $integration->last_error_message,
            'problem' => $problem?->value,
            'events24h' => $events24h,
            'fleet' => $fleet,
            'webhook' => $endpoint !== null ? $this->presentWebhook($endpoint) : null,
            // Gobierna el formulario de la Secret Key del webhook (y el resto
            // de acciones de gestión) por integración, con la misma Policy
            // que autoriza el PUT.
            'canUpdate' => $canUpdate,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function presentWebhook(WebhookEndpoint $endpoint): array
    {
        return [
            'url' => route('webhooks.handle', ['endpoint_url' => $endpoint->url]),
            'status' => $endpoint->status,
            'lastReceivedAt' => $endpoint->last_received_at?->toIso8601String(),
            // La Secret Key nunca viaja: sólo si está configurada y cuándo.
            'secretConfigured' => $endpoint->hasSecret(),
            'secretConfiguredAt' => $endpoint->secret_configured_at?->toIso8601String(),
            'health' => $endpoint->signatureHealth(),
            'lastValidReceivedAt' => $endpoint->last_valid_received_at?->toIso8601String(),
            'lastRejectedAt' => $endpoint->last_rejected_at?->toIso8601String(),
            'lastRejectionReason' => $endpoint->last_rejection_reason,
            // Alta automática (ProvisionSamsaraWebhook): el modo, cómo quedó y
            // un motivo corto y seguro si falló. Nunca ids ni llaves de Samsara.
            'setupMode' => $endpoint->setup_mode,
            'setupStatus' => $endpoint->setup_status,
            'setupError' => $endpoint->setup_error,
            'provisionedAt' => $endpoint->provisioned_at?->toIso8601String(),
        ];
    }

    /**
     * Tenant-wide pulse for the strip at the top of the page.
     *
     * @param  Collection<int, TenantIntegration>  $integrations
     * @param  array<int, int>  $events24h
     * @param  array<int, array{assets: int, monitored: int, drivers: int}>  $fleet
     * @return array<string, int>
     */
    private function summary(Collection $integrations, array $events24h, array $fleet): array
    {
        $byStatus = $integrations->countBy(fn (TenantIntegration $i) => $i->status->value);
        // Una Samsara que sincroniza pero rechaza (o no puede validar) los
        // webhooks no está funcionando: los pánicos no entran.
        $panicsBlocked = $integrations->filter(fn (TenantIntegration $i) => $this->panicsBlocked($i))->count();

        return [
            'total' => $integrations->count(),
            'working' => (int) ($byStatus[TenantIntegrationStatus::Active->value] ?? 0) - $panicsBlocked,
            'attention' => (int) ($byStatus[TenantIntegrationStatus::Error->value] ?? 0) + $panicsBlocked,
            'pending' => (int) ($byStatus[TenantIntegrationStatus::Pending->value] ?? 0),
            'inactive' => (int) ($byStatus[TenantIntegrationStatus::Inactive->value] ?? 0),
            'events24h' => array_sum($events24h),
            'assets' => array_sum(array_column($fleet, 'assets')),
            'monitored' => array_sum(array_column($fleet, 'monitored')),
            'drivers' => array_sum(array_column($fleet, 'drivers')),
        ];
    }

    /**
     * Activa, de Samsara, y con la firma del webhook sin configurar o
     * rechazando: el mismo criterio que la tarjeta usa para «Pánicos sin
     * recibir».
     */
    private function panicsBlocked(TenantIntegration $integration): bool
    {
        $endpoint = $integration->webhookEndpoint;

        return $integration->status === TenantIntegrationStatus::Active
            && $integration->provider?->code === 'samsara'
            && $endpoint !== null
            && in_array($endpoint->signatureHealth(), [WebhookEndpoint::HEALTH_PENDING_SECRET, WebhookEndpoint::HEALTH_REJECTING], true);
    }

    /**
     * Newest data point any telematics feed delivered, per integration.
     *
     * @return array<int, string> tenant_integration_id => ISO-8601
     */
    private function newestLiveDataByIntegration(int $teamId): array
    {
        return TelematicsFeedCursor::query()
            ->where('team_id', $teamId)
            ->whereNotNull('last_data_at')
            ->groupBy('tenant_integration_id')
            ->selectRaw('tenant_integration_id, MAX(last_data_at) AS newest')
            ->pluck('newest', 'tenant_integration_id')
            ->mapWithKeys(fn ($newest, $integrationId) => [
                (int) $integrationId => Carbon::parse($newest)->toIso8601String(),
            ])
            ->all();
    }

    /**
     * Units and drivers each provider has brought into this tenant.
     *
     * @return array<int, array{assets: int, monitored: int, drivers: int}>
     */
    private function fleetByProvider(int $teamId): array
    {
        $assets = Asset::query()
            ->where('team_id', $teamId)
            ->whereNotNull('provider_id')
            ->selectRaw('provider_id, COUNT(*) AS total, SUM(CASE WHEN monitoring_state = ? THEN 1 ELSE 0 END) AS monitored', [AssetMonitoringState::Monitored->value])
            ->groupBy('provider_id')
            ->toBase()
            ->get();

        $drivers = Driver::query()
            ->join('driver_external_references', 'driver_external_references.driver_id', '=', 'drivers.id')
            ->where('drivers.team_id', $teamId)
            ->groupBy('driver_external_references.provider_id')
            ->select('driver_external_references.provider_id', DB::raw('COUNT(DISTINCT drivers.id) AS total'))
            ->pluck('total', 'provider_id');

        $fleet = [];

        foreach ($assets as $row) {
            $fleet[(int) $row->provider_id] = [
                'assets' => (int) $row->total,
                'monitored' => (int) $row->monitored,
                'drivers' => 0,
            ];
        }

        foreach ($drivers as $providerId => $total) {
            $fleet[(int) $providerId] ??= ['assets' => 0, 'monitored' => 0, 'drivers' => 0];
            $fleet[(int) $providerId]['drivers'] = (int) $total;
        }

        return $fleet;
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
                'id' => $provider->id,
                'code' => $provider->code,
                'name' => $provider->name,
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
            AuthType::ApiKey => 'Clave de API',
            AuthType::Oauth2 => 'Inicio de sesión OAuth',
            AuthType::BasicAuth => 'Usuario y contraseña',
            AuthType::Token => 'Token de acceso',
        };
    }
}
