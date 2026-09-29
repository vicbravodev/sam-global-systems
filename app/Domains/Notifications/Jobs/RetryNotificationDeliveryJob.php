<?php

namespace App\Domains\Notifications\Jobs;

use App\Domains\Notifications\Actions\AttemptDelivery;
use App\Domains\Notifications\Actions\RenderNotificationContent;
use App\Domains\Notifications\Data\RenderedNotification;
use App\Domains\Notifications\Enums\ChannelType;
use App\Domains\Notifications\Enums\DeliveryStatus;
use App\Domains\Notifications\Models\NotificationChannel;
use App\Domains\Notifications\Models\NotificationDelivery;
use App\Domains\Notifications\Support\ChannelAddress;
use App\Domains\Notifications\Support\DeliveryEscalationGuard;
use App\Support\JobFailureReporter;
use App\Support\SystemLog;
use App\Support\TenantContext;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

/**
 * Reenvía UNA entrega fallida por el mismo canal.
 *
 * Reenvía exactamente el payload guardado de la entrega original (dirección,
 * asunto, cuerpo — incluidas las instrucciones SI/NO/ESC): re-renderizar
 * podía mandar el SMS al email del destinatario o perder el token.
 *
 * El número de intentos "de negocio" vive en `attempt_number` y lo controla
 * {@see RetryOrFallbackOnNotificationFailed}: cada intento fallido emite
 * NotificationFailed y el listener programa el siguiente (o el fallback).
 * Por eso el job es `tries = 1` en la cola: si algo lanza después de un
 * envío real, la cola NO debe re-ejecutarlo y reenviar.
 */
class RetryNotificationDeliveryJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 1;

    public int $timeout = 120;

    public function __construct(
        public readonly int $deliveryId,
    ) {
        $this->onQueue('notifications');
    }

    /**
     * Business attempts per channel. Webhook caps at 3 (spec-13 PR #2a
     * policy); everyone else keeps the spec-13 default of 5.
     */
    public function maxAttempts(): int
    {
        return $this->isWebhook() ? 3 : 5;
    }

    /**
     * Per-channel delay before each retry. Webhook follows the spec-13 PR #2
     * policy (30s / 2min / 10min); other channels keep the default ramp.
     *
     * @return array<int, int>
     */
    public function retryDelays(): array
    {
        if ($this->isWebhook()) {
            return [30, 120, 600];
        }

        return [30, 60, 120, 300, 600];
    }

    private function isWebhook(): bool
    {
        $delivery = NotificationDelivery::withoutGlobalScopes()
            ->with('channel')
            ->find($this->deliveryId);

        return $delivery?->channel?->channel_type === ChannelType::Webhook;
    }

    public function handle(AttemptDelivery $attemptDelivery, RenderNotificationContent $render): void
    {
        $delivery = NotificationDelivery::withoutGlobalScopes()->find($this->deliveryId);
        $input = ['delivery_id' => $this->deliveryId, 'stage' => 'retry_job'];

        // Sólo una entrega que sigue fallida se reintenta: si entretanto llegó
        // (callback tardío) o ya está en vuelo, reenviar sería duplicar.
        if ($delivery === null || $delivery->status !== DeliveryStatus::Failed) {
            SystemLog::skipped('notifications.retry.skipped', reason: 'not_failed', input: $input, debug: true);

            return;
        }

        // Trabaja dentro del tenant de la entrega: el lookup de entrada no
        // puede estar scopeado, todo lo que sigue sí. Ver §2.1.
        TenantContext::set($delivery->team_id);

        $delivery->load(['notification', 'recipient', 'channel']);

        if ($delivery->notification === null || $delivery->recipient === null || $delivery->channel === null) {
            SystemLog::skipped('notifications.retry.skipped', reason: 'relations_missing', input: $input);

            return;
        }

        if ($delivery->permanent_failure) {
            SystemLog::skipped('notifications.retry.skipped', reason: 'permanent_failure', input: $input);

            return;
        }

        $guard = DeliveryEscalationGuard::explain($delivery);

        if ($guard['reason'] !== null) {
            DeliveryEscalationGuard::logBlocked($delivery, $guard, 'retry_job');

            return;
        }

        // The tenant may have switched the channel off (or the platform
        // disabled it) between the failure and this delayed retry: the toggle
        // is checked at send time, not at dispatch time. The recipient still
        // gets the next usable channel of the fallback policy.
        $stillUsable = NotificationChannel::query()
            ->usableByTeam((int) $delivery->team_id)
            ->whereKey($delivery->channel_id)
            ->exists();

        if (! $stillUsable) {
            $delivery->update([
                'status' => DeliveryStatus::Cancelled,
                'error_message' => "{$delivery->channel->channel_type->value} channel disabled for the tenant before the retry",
            ]);

            FallbackNotificationChannelJob::dispatch($delivery->id);

            SystemLog::skipped('notifications.retry.skipped', reason: 'channel_disabled', input: $input, result: [
                'delivery_status' => 'cancelled',
                'fallback_requested' => true,
            ]);

            return;
        }

        $rendered = $this->originalPayload($delivery) ?? $this->rerender($delivery, $render);

        if ($rendered === null) {
            $delivery->update([
                'status' => DeliveryStatus::Skipped,
                'error_message' => "no valid {$delivery->channel->channel_type->value} address to retry",
            ]);

            SystemLog::skipped('notifications.retry.skipped', reason: 'no_valid_address', input: $input, result: ['delivery_status' => 'skipped']);

            return;
        }

        $delivery->update(['attempt_number' => $delivery->attempt_number + 1]);

        $attemptDelivery->execute(
            $delivery,
            $delivery->channel,
            $rendered,
            usageEventKey: "notif_retry_{$delivery->id}_{$delivery->attempt_number}",
        );
    }

    /**
     * The exact message the first attempt sent.
     */
    private function originalPayload(NotificationDelivery $delivery): ?RenderedNotification
    {
        $payload = $delivery->payload_json ?? [];
        $address = $payload['address'] ?? null;
        $body = $payload['body'] ?? null;

        if (! is_string($address) || $address === '' || ! is_string($body)) {
            return null;
        }

        return new RenderedNotification(
            channelType: $delivery->channel->channel_type,
            address: $address,
            subject: is_string($payload['subject'] ?? null) ? $payload['subject'] : null,
            body: $body,
        );
    }

    /**
     * Deliveries that failed before storing a payload (legacy rows): render
     * again against the channel-specific address, never the generic one.
     */
    private function rerender(NotificationDelivery $delivery, RenderNotificationContent $render): ?RenderedNotification
    {
        $type = $delivery->channel->channel_type;
        $address = $delivery->recipient->addressForChannel($type);

        if ($address === null || $address === '' || ChannelAddress::invalidReason($type, $address) !== null) {
            return null;
        }

        return $render->execute($delivery->notification, $delivery->recipient, $type, null, $address);
    }

    public function failed(\Throwable $exception): void
    {
        JobFailureReporter::report(static::class, $exception, [
            'delivery_id' => $this->deliveryId,
        ]);
    }
}
