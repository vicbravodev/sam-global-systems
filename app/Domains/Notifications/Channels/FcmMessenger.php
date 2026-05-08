<?php

namespace App\Domains\Notifications\Channels;

use App\Domains\Notifications\Data\FcmSendReport;
use Kreait\Firebase\Factory;
use Kreait\Firebase\Messaging\CloudMessage;

/**
 * Wrapper around the kreait Firebase Messaging SDK so PushNotificationDriver
 * can be unit-tested by mocking THIS class instead of the SDK internals
 * (the SDK exposes `final` DTOs that Mockery cannot stub).
 *
 * Production builds the Firebase Messaging client per-call from the channel
 * config; tests bind a mock against this class in the container.
 */
class FcmMessenger
{
    /**
     * @param  array<string, mixed>  $config  Channel `config_json`. Expects
     *                                        `firebase_credentials` (JSON string of the service-account file).
     * @param  array<string, mixed>  $payload  {title, body, data?}
     * @param  list<string>  $tokens
     */
    public function sendMulticast(array $config, array $payload, array $tokens): FcmSendReport
    {
        $credentials = $config['firebase_credentials'] ?? null;

        if (! is_string($credentials) || $credentials === '') {
            throw new \RuntimeException('firebase_credentials missing');
        }

        $decoded = json_decode($credentials, true);

        if (! is_array($decoded)) {
            throw new \RuntimeException('firebase_credentials must be a JSON-encoded service-account');
        }

        $messaging = (new Factory)->withServiceAccount($decoded)->createMessaging();

        $message = CloudMessage::new()->withNotification([
            'title' => (string) ($payload['title'] ?? ''),
            'body' => (string) ($payload['body'] ?? ''),
        ]);

        if (isset($payload['data']) && is_array($payload['data'])) {
            $stringData = [];
            foreach ($payload['data'] as $k => $v) {
                if (is_scalar($v)) {
                    $stringData[(string) $k] = (string) $v;
                }
            }
            if ($stringData !== []) {
                $message = $message->withData($stringData);
            }
        }

        $report = $messaging->sendMulticast($message, $tokens);

        $invalid = $report->unknownTokens();

        if (method_exists($report, 'invalidTokens')) {
            $invalid = array_merge($invalid, $report->invalidTokens());
        }

        return new FcmSendReport(
            successes: $report->successes()->count(),
            failures: $report->failures()->count(),
            invalidTokens: array_values(array_unique($invalid)),
        );
    }
}
