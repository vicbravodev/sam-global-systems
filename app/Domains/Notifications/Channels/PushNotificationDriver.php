<?php

namespace App\Domains\Notifications\Channels;

use App\Contracts\Notifications\NotificationDriver;
use App\Domains\Notifications\Data\DeliveryResult;
use App\Domains\Notifications\Data\FcmSendReport;
use App\Domains\Notifications\Data\RenderedNotification;
use App\Domains\Notifications\Models\NotificationChannel;
use App\Domains\Notifications\Models\UserPushToken;

/**
 * Firebase Cloud Messaging driver.
 *
 * Required config_json keys:
 *   - firebase_credentials — JSON string of the service-account file (cifrado at rest).
 *
 * The recipient's `address` is interpreted as a User id. Tokens are looked
 * up in the `user_push_tokens` table; tokens reported by Firebase as
 * invalid (UNREGISTERED, INVALID_ARGUMENT, NOT_FOUND) are pruned from the
 * table so subsequent runs do not retry them.
 */
class PushNotificationDriver implements NotificationDriver
{
    public function __construct(
        private readonly FcmMessenger $messenger,
    ) {}

    public function send(RenderedNotification $notification, NotificationChannel $channel): DeliveryResult
    {
        $config = $channel->config_json ?? [];

        if (! isset($config['firebase_credentials']) || ! is_string($config['firebase_credentials']) || $config['firebase_credentials'] === '') {
            return DeliveryResult::failure('firebase_credentials missing');
        }

        if (! is_numeric($notification->address)) {
            return DeliveryResult::failure('push recipient must be a numeric user id');
        }

        $userId = (int) $notification->address;

        $tokens = UserPushToken::withoutGlobalScopes()
            ->where('user_id', $userId)
            ->pluck('token')
            ->all();

        if ($tokens === []) {
            return DeliveryResult::failure('no push tokens registered for user');
        }

        $payload = [
            'title' => $notification->subject ?? '',
            'body' => $notification->body,
            'data' => $notification->variables,
        ];

        try {
            $report = $this->messenger->sendMulticast($config, $payload, $tokens);
        } catch (\Throwable $e) {
            return DeliveryResult::failure('fcm error: '.$e->getMessage(), [
                'driver' => 'push',
            ]);
        }

        $this->pruneInvalidTokens($report);

        if ($report->successes === 0) {
            return DeliveryResult::failure('fcm: all '.$report->failures.' deliveries failed', [
                'driver' => 'push',
                'successes' => 0,
                'failures' => $report->failures,
            ]);
        }

        return DeliveryResult::success(
            providerMessageId: 'fcm-multicast-'.uniqid(),
            response: [
                'driver' => 'push',
                'successes' => $report->successes,
                'failures' => $report->failures,
            ],
        );
    }

    private function pruneInvalidTokens(FcmSendReport $report): void
    {
        if ($report->invalidTokens === []) {
            return;
        }

        UserPushToken::withoutGlobalScopes()
            ->whereIn('token', $report->invalidTokens)
            ->delete();
    }
}
