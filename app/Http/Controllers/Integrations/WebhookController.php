<?php

namespace App\Http\Controllers\Integrations;

use App\Domains\Integrations\Actions\HandleWebhook;
use App\Domains\Integrations\Models\WebhookEndpoint;
use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class WebhookController extends Controller
{
    public function handle(Request $request, string $endpoint_url, HandleWebhook $handleWebhook): JsonResponse
    {
        $endpoint = WebhookEndpoint::where('url', $endpoint_url)
            ->where('status', 'active')
            ->firstOrFail();

        $eventType = $request->input('event_type', 'unknown');
        $payload = $request->all();

        $handleWebhook->execute($endpoint, $eventType, $payload);

        return response()->json(['status' => 'received'], 202);
    }
}
