<?php

namespace App\Domains\Notifications\Channels;

use App\Contracts\Notifications\NotificationDriver;
use App\Domains\Notifications\Data\DeliveryResult;
use App\Domains\Notifications\Data\RenderedNotification;
use App\Domains\Notifications\Data\WebPushOutcome;
use App\Domains\Notifications\Data\WebPushTarget;
use App\Domains\Notifications\Enums\NotificationPriority;
use App\Domains\Notifications\Models\NotificationChannel;
use App\Domains\Notifications\Models\NotificationDelivery;
use App\Domains\Notifications\Models\PushSubscription;
use App\Domains\Notifications\Support\PushPayload;
use App\Models\Team;
use App\Models\User;
use App\Support\SafeErrorMessage;
use App\Support\SystemLog;
use App\Support\TenantContext;
use Throwable;

/**
 * Avisos al dispositivo vía Web Push (VAPID). `address` es el id del usuario
 * (NotificationRecipient::addressForChannel) y el team sale de la fila de
 * entrega: sólo se manda a las suscripciones de ese usuario EN ese team, y
 * sólo si sigue siendo miembro. Es un canal gratuito que no interrumpe: no
 * sustituye la llamada ni frena su reintento.
 */
class PushNotificationDriver implements NotificationDriver
{
    private const CRITICAL_TTL = 3600;

    private const DEFAULT_TTL = 86400;

    public function __construct(
        private readonly WebPushMessenger $messenger,
    ) {}

    public function send(RenderedNotification $notification, NotificationChannel $channel): DeliveryResult
    {
        $input = ['delivery_id' => $notification->deliveryId];

        if (! ctype_digit($notification->address)) {
            return $this->fail('invalid_address', $input, permanent: true);
        }

        $userId = (int) $notification->address;
        $input['user_id'] = $userId;

        if (! $this->messenger->isConfigured()) {
            return $this->fail('not_configured', $input, permanent: true);
        }

        $delivery = $notification->deliveryId !== null
            ? NotificationDelivery::withoutGlobalScopes()->with('notification')->find($notification->deliveryId)
            : null;

        if ($delivery === null || $delivery->notification === null) {
            return $this->fail('no_delivery', $input, permanent: true);
        }

        $teamId = $delivery->team_id;
        $input['team_id'] = $teamId;
        $team = Team::query()->find($teamId);
        $user = User::query()->find($userId);

        if ($team === null || $user === null || ! $user->belongsToTeam($team)) {
            return $this->fail('not_member', $input, permanent: true);
        }

        $subscriptions = TenantContext::for($teamId, fn () => PushSubscription::query()
            ->where('team_id', $teamId)
            ->where('user_id', $userId)
            ->get());

        if ($subscriptions->isEmpty()) {
            return $this->fail('no_subscriptions', $input, permanent: true);
        }

        $critical = $delivery->notification->priority === NotificationPriority::Critical;
        $payload = PushPayload::build($delivery->notification, $team, (string) $notification->subject, $notification->body);

        $targets = [];

        foreach ($subscriptions as $subscription) {
            $targets[] = new WebPushTarget($subscription->id, $subscription->endpoint, $subscription->public_key, $subscription->auth_token, $subscription->content_encoding);
        }

        try {
            $outcomes = $this->messenger->send(
                $targets,
                $payload,
                $critical ? self::CRITICAL_TTL : self::DEFAULT_TTL,
                $critical ? 'high' : 'normal',
            );
        } catch (Throwable $e) {
            SystemLog::failed('notifications.push.failed', reason: 'provider_error', input: $input, error: $e);

            return DeliveryResult::failure('webpush error: '.SafeErrorMessage::from($e), ['driver' => 'push']);
        }

        $this->recordOutcomes($teamId, $outcomes, $input);

        $successes = count(array_filter($outcomes, fn (WebPushOutcome $o) => $o->success));
        $result = ['subscriptions' => count($targets), 'successes' => $successes, 'failures' => count($outcomes) - $successes];

        if ($successes === 0) {
            SystemLog::failed('notifications.push.failed', reason: 'all_failed', input: $input, result: $result);

            return DeliveryResult::failure('webpush: all deliveries failed', ['driver' => 'push', ...$result]);
        }

        SystemLog::ok('notifications.push.sent', input: $input, calc: ['critical' => $critical], result: $result);

        return DeliveryResult::success(
            providerMessageId: 'webpush-'.$notification->deliveryId,
            response: ['driver' => 'push', ...$result],
        );
    }

    /**
     * @param  list<WebPushOutcome>  $outcomes
     * @param  array<string, mixed>  $input
     */
    private function recordOutcomes(int $teamId, array $outcomes, array $input): void
    {
        foreach ($outcomes as $outcome) {
            if ($outcome->expired) {
                PushSubscription::withoutGlobalScopes()
                    ->where('team_id', $teamId)
                    ->whereKey($outcome->subscriptionId)
                    ->delete();

                SystemLog::ok('notifications.push.subscription_pruned', input: [...$input, 'subscription_id' => $outcome->subscriptionId], result: ['status_code' => $outcome->statusCode]);
            }
        }

        $delivered = array_map(fn (WebPushOutcome $o) => $o->subscriptionId, array_filter($outcomes, fn (WebPushOutcome $o) => $o->success));

        if ($delivered !== []) {
            PushSubscription::withoutGlobalScopes()
                ->where('team_id', $teamId)
                ->whereKey($delivered)
                ->update(['last_used_at' => now()]);
        }
    }

    /**
     * @param  array<string, mixed>  $input
     */
    private function fail(string $reason, array $input, bool $permanent): DeliveryResult
    {
        SystemLog::failed('notifications.push.failed', reason: $reason, input: $input);

        return DeliveryResult::failure('push_'.$reason, ['driver' => 'push'], $permanent);
    }
}
