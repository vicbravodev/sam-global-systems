<?php

namespace App\Http\Controllers\Integrations;

use App\Domains\Integrations\Actions\ProvisionSamsaraWebhook;
use App\Domains\Integrations\Actions\RotateSamsaraWebhookSecret;
use App\Domains\Integrations\Models\TenantIntegration;
use App\Domains\Integrations\Models\WebhookEndpoint;
use App\Http\Controllers\Controller;
use App\Models\Team;
use App\Models\User;
use Illuminate\Container\Attributes\CurrentUser;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Alta automática del webhook de Samsara desde Integraciones: "Configurar
 * automáticamente" y "Rotar llave". Corren en la petición (dos o tres llamadas
 * a Samsara) para que el operador vea el resultado al momento. La Secret Key
 * nunca vuelve: sólo el estado.
 */
class WebhookSetupController extends Controller
{
    public function provision(Team $current_team, TenantIntegration $integration, ProvisionSamsaraWebhook $provision): JsonResponse
    {
        $this->authorize('update', $integration);

        $result = $provision->execute($integration, force: true);

        return $this->respond($integration, $result);
    }

    public function rotate(
        Request $request,
        Team $current_team,
        TenantIntegration $integration,
        RotateSamsaraWebhookSecret $rotate,
        #[CurrentUser] User $user,
    ): JsonResponse {
        $this->authorize('update', $integration);

        $result = $rotate->execute($integration, $user, $request->ip(), $request->userAgent());

        return $this->respond($integration, $result);
    }

    private function respond(TenantIntegration $integration, string $result): JsonResponse
    {
        $endpoint = WebhookEndpoint::query()
            ->where('tenant_integration_id', $integration->id)
            ->orderBy('id')
            ->first();

        return response()->json([
            'data' => [
                'result' => $result,
                'setup_mode' => $endpoint?->setup_mode,
                'setup_status' => $endpoint?->setup_status,
                'webhook_health' => $endpoint?->signatureHealth(),
            ],
        ], in_array($result, [WebhookEndpoint::SETUP_STATUS_FAILED, RotateSamsaraWebhookSecret::FAILED], true) ? 502 : 200);
    }
}
