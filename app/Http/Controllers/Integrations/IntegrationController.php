<?php

namespace App\Http\Controllers\Integrations;

use App\Domains\Integrations\Actions\TestIntegrationConnection;
use App\Domains\Integrations\Enums\TenantIntegrationStatus;
use App\Domains\Integrations\Events\IntegrationConnected;
use App\Domains\Integrations\Events\IntegrationDisconnected;
use App\Domains\Integrations\Events\IntegrationStatusChanged;
use App\Domains\Integrations\Models\IntegrationProvider;
use App\Domains\Integrations\Models\TenantIntegration;
use App\Domains\Integrations\Models\WebhookEndpoint;
use App\Http\Controllers\Controller;
use App\Http\Requests\Integrations\StoreIntegrationRequest;
use App\Http\Requests\Integrations\UpdateIntegrationRequest;
use App\Models\Team;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\DB;

class IntegrationController extends Controller
{
    public function index(Team $current_team): JsonResponse
    {
        $this->authorize('viewAny', TenantIntegration::class);

        $integrations = TenantIntegration::with('provider')
            ->where('team_id', $current_team->id)
            ->orderByDesc('id')
            ->get();

        return response()->json([
            'data' => $integrations->map(fn (TenantIntegration $integration) => $this->present($integration))->all(),
        ]);
    }

    public function store(StoreIntegrationRequest $request, Team $current_team): JsonResponse
    {
        $this->authorize('create', TenantIntegration::class);

        $provider = IntegrationProvider::query()->findOrFail($request->integer('provider_id'));

        abort_if(
            $provider->isDeprecated(),
            422,
            'Cannot create an integration for a deprecated provider.',
        );

        // La integración y su endpoint de webhooks nacen juntos o no nacen:
        // una integración activa sin endpoint no podría recibir ni un pánico.
        $integration = DB::transaction(function () use ($request, $current_team, $provider): TenantIntegration {
            $integration = TenantIntegration::create([
                'team_id' => $current_team->id,
                'provider_id' => $provider->id,
                'name' => $request->validated('name'),
                'auth_type' => $request->validated('auth_type'),
                'credentials_encrypted' => $request->validated('credentials'),
                'config_json' => $request->validated('config'),
                'status' => TenantIntegrationStatus::Active,
            ]);

            WebhookEndpoint::create([
                'tenant_integration_id' => $integration->id,
            ]);

            return $integration;
        });

        IntegrationConnected::dispatch(
            $current_team->id,
            $integration->id,
            $provider->code,
        );

        IntegrationStatusChanged::dispatch(
            $current_team->id,
            $integration->id,
            $provider->code,
            TenantIntegrationStatus::Active->value,
        );

        return response()->json([
            'data' => $this->present($integration->load('provider')),
        ], 201);
    }

    public function update(UpdateIntegrationRequest $request, Team $current_team, TenantIntegration $integration): JsonResponse
    {
        $this->authorize('update', $integration);

        $submittedConfig = $request->validated('config');

        $data = array_filter([
            'name' => $request->validated('name'),
            'config_json' => is_array($submittedConfig)
                ? $integration->mergeSubmittedConfig($submittedConfig)
                : null,
        ], fn ($v) => $v !== null);

        if ($request->has('credentials')) {
            $data['credentials_encrypted'] = $request->validated('credentials');
        }

        $integration->update($data);

        // Recién actualizada: sólo falta si otra petición la borró entre medias.
        $fresh = $integration->fresh(['provider']);
        abort_if($fresh === null, 404);

        return response()->json(['data' => $this->present($fresh)]);
    }

    public function destroy(Team $current_team, TenantIntegration $integration): JsonResponse
    {
        $this->authorize('delete', $integration);

        // provider_id es NOT NULL con FK (sin soft-delete): firstOrFail sólo
        // falla si el catálogo se corrompió.
        $providerCode = $integration->provider()->firstOrFail()->code;

        $integration->update(['status' => TenantIntegrationStatus::Inactive]);

        IntegrationDisconnected::dispatch(
            $current_team->id,
            $integration->id,
            $providerCode,
        );

        IntegrationStatusChanged::dispatch(
            $current_team->id,
            $integration->id,
            $providerCode,
            TenantIntegrationStatus::Inactive->value,
        );

        $integration->delete();

        return response()->json(null, 204);
    }

    public function test(Team $current_team, TenantIntegration $integration, TestIntegrationConnection $testConnection): JsonResponse
    {
        $this->authorize('update', $integration);

        $result = $testConnection->execute($integration);

        return response()->json(['data' => $result]);
    }

    /**
     * API shape of an integration: `config_json` is reduced to the
     * `PUBLIC_CONFIG_KEYS` allowlist so provider tokens or secrets a tenant
     * stored in the config never travel back in a JSON response.
     *
     * @return array<string, mixed>
     */
    private function present(TenantIntegration $integration): array
    {
        return array_merge($integration->toArray(), [
            'config_json' => $integration->publicConfig(),
        ]);
    }
}
