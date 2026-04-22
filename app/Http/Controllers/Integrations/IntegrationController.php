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

class IntegrationController extends Controller
{
    public function index(Team $current_team): JsonResponse
    {
        $integrations = TenantIntegration::with('provider')
            ->where('team_id', $current_team->id)
            ->orderByDesc('id')
            ->get();

        return response()->json(['data' => $integrations]);
    }

    public function store(StoreIntegrationRequest $request, Team $current_team): JsonResponse
    {
        $provider = IntegrationProvider::findOrFail($request->validated('provider_id'));

        abort_if(
            $provider->isDeprecated(),
            422,
            'Cannot create an integration for a deprecated provider.',
        );

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
            'data' => $integration->load('provider'),
        ], 201);
    }

    public function update(UpdateIntegrationRequest $request, Team $current_team, TenantIntegration $integration): JsonResponse
    {
        $data = array_filter([
            'name' => $request->validated('name'),
            'config_json' => $request->validated('config'),
        ], fn ($v) => $v !== null);

        if ($request->has('credentials')) {
            $data['credentials_encrypted'] = $request->validated('credentials');
        }

        $integration->update($data);

        return response()->json(['data' => $integration->fresh()->load('provider')]);
    }

    public function destroy(Team $current_team, TenantIntegration $integration): JsonResponse
    {
        $providerCode = $integration->provider->code;

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
        $result = $testConnection->execute($integration);

        return response()->json(['data' => $result]);
    }
}
