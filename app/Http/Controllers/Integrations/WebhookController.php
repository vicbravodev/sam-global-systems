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

        // Capture the exact raw body bytes and Samsara's signature headers. The
        // HMAC must be recomputed over the byte-for-byte body that Samsara
        // signed, so we cannot rely on the re-encoded parsed array.
        $rawPayload = $request->getContent();
        $signature = (string) $request->header('X-Samsara-Signature', '');
        $signatureTimestamp = $request->header('X-Samsara-Timestamp');

        $handleWebhook->execute(
            $endpoint,
            $eventType,
            $payload,
            $rawPayload,
            $signature,
            $signatureTimestamp,
        );

        return response()->json(['status' => 'received'], 202);
    }
}
