<?php

namespace App\Domains\Notifications\Channels;

use App\Contracts\Notifications\NotificationDriver;
use App\Domains\Notifications\Data\DeliveryResult;
use App\Domains\Notifications\Data\RenderedNotification;
use App\Domains\Notifications\Models\NotificationChannel;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;

/**
 * Outbound webhook driver. Posts a JSON payload to the endpoint configured
 * in `notification_channels.config_json` with an HMAC-SHA256 signature
 * derived from the channel's secret.
 *
 * Required config keys:
 *   - endpoint_url   — fully-qualified URL the payload is POSTed to
 *   - secret         — shared secret used to sign the payload (cifrado at rest)
 *
 * Optional:
 *   - timeout        — request timeout in seconds (default 10)
 *   - extra_headers  — assoc array of additional headers
 */
class WebhookNotificationDriver implements NotificationDriver
{
    public const DEFAULT_TIMEOUT_SECONDS = 10;

    public function send(RenderedNotification $notification, NotificationChannel $channel): DeliveryResult
    {
        $config = $channel->config_json ?? [];

        $endpoint = $config['endpoint_url'] ?? null;
        $secret = $config['secret'] ?? $config['webhook_secret'] ?? null;

        if (! is_string($endpoint) || $endpoint === '') {
            return DeliveryResult::failure('webhook endpoint_url missing');
        }

        if (! is_string($secret) || $secret === '') {
            return DeliveryResult::failure('webhook secret missing');
        }

        $eventKey = (string) Str::uuid();
        $timestamp = (string) now()->getTimestamp();

        $payload = [
            'event_key' => $eventKey,
            'channel_type' => $notification->channelType->value,
            'subject' => $notification->subject,
            'body' => $notification->body,
            'recipient' => [
                'address' => $notification->address,
                'name' => $notification->recipientName,
            ],
            'variables' => $notification->variables,
            'timestamp' => $timestamp,
        ];

        $body = (string) json_encode($payload, JSON_UNESCAPED_SLASHES);
        $signature = hash_hmac('sha256', $timestamp.'.'.$body, $secret);

        $headers = array_merge(
            is_array($config['extra_headers'] ?? null) ? $config['extra_headers'] : [],
            [
                'Content-Type' => 'application/json',
                'X-SAM-Signature' => 'sha256='.$signature,
                'X-SAM-Timestamp' => $timestamp,
                'X-SAM-Event-Key' => $eventKey,
            ],
        );

        $timeout = is_int($config['timeout'] ?? null) ? (int) $config['timeout'] : self::DEFAULT_TIMEOUT_SECONDS;

        try {
            /** @var PendingRequest $request */
            $request = Http::withHeaders($headers)->timeout($timeout);

            /** @var Response $response */
            $response = $request->withBody($body, 'application/json')->post($endpoint);
        } catch (ConnectionException $e) {
            return DeliveryResult::failure('webhook connection error: '.$e->getMessage(), [
                'driver' => 'webhook',
                'event_key' => $eventKey,
            ]);
        } catch (\Throwable $e) {
            return DeliveryResult::failure('webhook error: '.$e->getMessage(), [
                'driver' => 'webhook',
                'event_key' => $eventKey,
            ]);
        }

        $responsePayload = [
            'driver' => 'webhook',
            'event_key' => $eventKey,
            'status_code' => $response->status(),
            'body' => Str::limit((string) $response->body(), 500, ''),
        ];

        if ($response->successful()) {
            $providerId = $response->header('X-Message-ID') ?: $response->header('Idempotency-Key') ?: ('webhook-'.$eventKey);

            return DeliveryResult::success(
                providerMessageId: $providerId,
                response: $responsePayload,
            );
        }

        return DeliveryResult::failure(
            'webhook returned HTTP '.$response->status(),
            $responsePayload,
        );
    }
}
