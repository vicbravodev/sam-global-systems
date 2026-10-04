<?php

namespace App\Domains\Notifications\Channels;

use App\Domains\Notifications\Data\WebPushOutcome;
use App\Domains\Notifications\Data\WebPushTarget;
use App\Support\RedactSensitiveLogData;
use GuzzleHttp\Client;
use Illuminate\Support\Str;
use Minishlink\WebPush\Subscription;
use Minishlink\WebPush\WebPush;
use RuntimeException;
use Throwable;

/**
 * Envoltura de minishlink/web-push para que PushNotificationDriver no dependa
 * del SDK (y los tests lo sustituyan con app()->instance). Las llaves VAPID
 * son de plataforma (config/webpush.php).
 */
class WebPushMessenger
{
    private const TIMEOUT_SECONDS = 10;

    public function isConfigured(): bool
    {
        $vapid = config('webpush.vapid');

        return is_array($vapid)
            && is_string($vapid['subject'] ?? null) && $vapid['subject'] !== ''
            && is_string($vapid['public_key'] ?? null) && $vapid['public_key'] !== ''
            && is_string($vapid['private_key'] ?? null) && $vapid['private_key'] !== '';
    }

    /**
     * @param  list<WebPushTarget>  $targets
     * @return list<WebPushOutcome>
     */
    public function send(array $targets, string $payload, int $ttl, string $urgency): array
    {
        if (! $this->isConfigured()) {
            throw new RuntimeException('webpush_not_configured');
        }

        if ($targets === []) {
            return [];
        }

        $webPush = new WebPush(
            auth: ['VAPID' => [
                'subject' => (string) config('webpush.vapid.subject'),
                'publicKey' => (string) config('webpush.vapid.public_key'),
                'privateKey' => (string) config('webpush.vapid.private_key'),
            ]],
            defaultOptions: ['TTL' => $ttl, 'urgency' => $urgency],
            client: new Client(['timeout' => self::TIMEOUT_SECONDS]),
        );

        $byEndpoint = [];
        $hosts = [];
        $outcomes = [];

        foreach ($targets as $target) {
            $byEndpoint[$target->endpoint] = $target->subscriptionId;
            $host = parse_url($target->endpoint, PHP_URL_HOST);

            if (is_string($host) && $host !== '') {
                $hosts[$host] = true;
            }

            if (! $this->hasValidKeys($target)) {
                // Una suscripción malformada no tumba el lote: sólo ella falla.
                $outcomes[] = new WebPushOutcome($target->subscriptionId, false, false, null, 'invalid_subscription');

                continue;
            }

            try {
                $webPush->queueNotification(
                    Subscription::create([
                        'endpoint' => $target->endpoint,
                        'publicKey' => $target->publicKey,
                        'authToken' => $target->authToken,
                        'contentEncoding' => $target->contentEncoding,
                    ]),
                    $payload,
                );
            } catch (Throwable) {
                unset($byEndpoint[$target->endpoint]);
                $outcomes[] = new WebPushOutcome($target->subscriptionId, false, false, null, 'invalid_subscription');
            }
        }

        foreach ($webPush->flush() as $report) {
            $outcomes[] = new WebPushOutcome(
                subscriptionId: $byEndpoint[$report->getEndpoint()] ?? 0,
                success: $report->isSuccess(),
                expired: $report->isSubscriptionExpired(),
                statusCode: $report->getResponse()?->getStatusCode(),
                reason: $report->isSuccess() ? null : $this->safeReason($report->getReason(), array_keys($hosts)),
            );
        }

        return $outcomes;
    }

    /**
     * El cifrado (en flush) revienta con llaves que no son base64url válido
     * del tamaño esperado y tumbaría el lote entero: se descartan antes.
     */
    private function hasValidKeys(WebPushTarget $target): bool
    {
        $publicKey = base64_decode(strtr($target->publicKey, '-_', '+/'), true);
        $authToken = base64_decode(strtr($target->authToken, '-_', '+/'), true);

        return $publicKey !== false && strlen($publicKey) === 65
            && $authToken !== false && strlen($authToken) >= 16;
    }

    /**
     * La razón puede traer el endpoint (secreto por dispositivo) o su host (cURL
     * dice "Failed to connect to <host>"): se quitan URLs y hosts y se redacta.
     *
     * @param  list<string>  $hosts
     */
    private function safeReason(string $reason, array $hosts): string
    {
        $clean = (string) preg_replace('#https?://\S+#', '[url]', $reason);

        foreach ($hosts as $host) {
            $clean = str_replace($host, '[host]', $clean);
        }

        return Str::limit(RedactSensitiveLogData::sanitize($clean), 200);
    }
}
