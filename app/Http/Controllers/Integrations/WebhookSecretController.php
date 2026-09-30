<?php

namespace App\Http\Controllers\Integrations;

use App\Domains\Integrations\Actions\ConfigureWebhookSecret;
use App\Domains\Integrations\Models\TenantIntegration;
use App\Http\Controllers\Controller;
use App\Http\Requests\Integrations\UpdateWebhookSecretRequest;
use App\Models\Team;
use Illuminate\Http\JsonResponse;

/**
 * El tenant copia a SAM la Secret Key que Samsara generó para su webhook. La
 * respuesta sólo dice si quedó configurada y cuándo: el valor nunca vuelve.
 */
class WebhookSecretController extends Controller
{
    public function update(
        UpdateWebhookSecretRequest $request,
        Team $current_team,
        TenantIntegration $integration,
        ConfigureWebhookSecret $configureWebhookSecret,
    ): JsonResponse {
        $this->authorize('update', $integration);

        $endpoint = $configureWebhookSecret->execute(
            $integration,
            (string) $request->validated('webhook_secret'),
            $request->user(),
            $request->ip(),
            $request->userAgent(),
        );

        return response()->json([
            'data' => [
                'webhook_secret_configured' => $endpoint->hasSecret(),
                'webhook_secret_configured_at' => $endpoint->secret_configured_at?->toIso8601String(),
                'webhook_health' => $endpoint->signatureHealth(),
            ],
        ]);
    }
}
