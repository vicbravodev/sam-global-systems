<?php

namespace App\Domains\Notifications\Jobs;

use App\Domains\Notifications\Actions\AttemptDelivery;
use App\Domains\Notifications\Actions\RecordDeliveryAttempt;
use App\Domains\Notifications\Channels\TwilioDeliveryResults;
use App\Domains\Notifications\Channels\TwilioMessenger;
use App\Domains\Notifications\Channels\TwilioVoiceCaller;
use App\Domains\Notifications\Data\DeliveryResult;
use App\Domains\Notifications\Enums\ChannelType;
use App\Domains\Notifications\Enums\DeliveryStatus;
use App\Domains\Notifications\Enums\MessagingResourceType;
use App\Domains\Notifications\Models\NotificationDelivery;
use App\Domains\Notifications\Support\PlatformTwilioConfig;
use App\Domains\Notifications\Support\TwilioResourceAttribution;
use App\Support\SystemLog;
use App\Support\TenantContext;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Throwable;

/**
 * Averigua en Twilio qué pasó con un envío cuyo resultado no se supo (timeout
 * o red caída tras mandar la petición) o que quedó atascado "enviando" porque
 * el worker murió a medio envío:
 *
 * - Twilio lo tiene (mismo destino y remitente, creado desde el intento):
 *   se adopta su SID como si la petición hubiera respondido — cargo, uso y
 *   feedback normales, sin reenviar.
 * - No lo tiene: el intento falla (transitorio) y el reintento/fallback de
 *   siempre decide. Así un timeout nunca manda un SMS o una llamada doble.
 *
 * El tenant sale de la entrega en DB.
 */
class ResolveUncertainDeliveryJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    /** Margen para que Twilio termine de registrar el recurso. */
    public const int DELAY_SECONDS = 60;

    /** Ventana antes del intento en la que se busca el recurso. */
    public const int LOOKBACK_MINUTES = 3;

    /** Margen tras el intento (la fila se escribe después de la petición). */
    public const int LOOKAHEAD_MINUTES = 2;

    public int $tries = 3;

    public int $backoff = 60;

    public function __construct(
        public readonly int $deliveryId,
    ) {
        $this->onQueue('notifications');
    }

    public function handle(AttemptDelivery $attemptDelivery, TwilioMessenger $messenger, TwilioVoiceCaller $caller): void
    {
        $input = ['delivery_id' => $this->deliveryId];

        // Lookup de entrada sin scope: el job descubre aquí su tenant.
        $delivery = NotificationDelivery::withoutGlobalScopes()->find($this->deliveryId);

        if ($delivery === null) {
            SystemLog::skipped('notifications.delivery.uncertain_resolved', reason: 'delivery_missing', input: $input);

            return;
        }

        TenantContext::set($delivery->team_id);

        $delivery->load('channel');
        $channel = $delivery->channel;

        if (! in_array($delivery->status, [DeliveryStatus::Sending, DeliveryStatus::Pending], true) || $delivery->provider_message_id !== null || $channel === null) {
            SystemLog::skipped('notifications.delivery.uncertain_resolved', reason: 'already_resolved', input: $input, calc: ['delivery_status' => $delivery->status->value]);

            return;
        }

        $type = $channel->channel_type;
        $input['channel_type'] = $type->value;

        if (! in_array($type, [ChannelType::Sms, ChannelType::Whatsapp, ChannelType::Voice], true)) {
            $attemptDelivery->applyResult($delivery, $channel, DeliveryResult::failure("{$type->value} delivery left in flight"), self::usageEventKey($delivery));

            SystemLog::ok('notifications.delivery.uncertain_resolved', input: $input, result: ['outcome' => 'failed_for_retry']);

            return;
        }

        $to = is_string($delivery->payload_json['address'] ?? null) ? $delivery->payload_json['address'] : null;
        $from = PlatformTwilioConfig::resolve($channel->config_json ?? [], $type)['from'];
        // Ventana del intento por ambos lados: un envío posterior al mismo
        // número (de otro tenant o de otro aviso) nunca entra.
        $attemptAt = ($delivery->updated_at ?? now())->copy();
        $since = $attemptAt->copy()->subMinutes(self::LOOKBACK_MINUTES);
        $until = $attemptAt->copy()->addMinutes(self::LOOKAHEAD_MINUTES);

        if ($to === null || $from === null) {
            $attemptDelivery->applyResult($delivery, $channel, DeliveryResult::failure('uncertain delivery without address or sender'), self::usageEventKey($delivery));

            SystemLog::ok('notifications.delivery.uncertain_resolved', input: $input, result: ['outcome' => 'failed_for_retry'], calc: ['address_present' => $to !== null, 'from_present' => $from !== null]);

            return;
        }

        if ($type === ChannelType::Whatsapp) {
            $to = self::whatsapp($to);
            $from = self::whatsapp($from);
        }

        try {
            $candidates = $type === ChannelType::Voice
                ? $caller->findRecentCalls($to, $from, $since)
                : $messenger->findRecentMessages($to, $from, $since);
        } catch (Throwable $e) {
            // La consulta misma falló: otro intento del job (tries/backoff).
            SystemLog::degraded('notifications.delivery.uncertain_resolved', reason: 'lookup_failed', input: $input, error: $e);

            throw $e;
        }

        $body = is_string($delivery->payload_json['body'] ?? null) ? $delivery->payload_json['body'] : null;
        $pick = TwilioResourceAttribution::pick($candidates, $since, $until, $type === ChannelType::Voice ? null : $body);
        $resource = $pick['resource'];
        $calc = [
            'lookback_minutes' => self::LOOKBACK_MINUTES,
            'lookahead_minutes' => self::LOOKAHEAD_MINUTES,
            'since_at' => $since->toIso8601String(),
            'until_at' => $until->toIso8601String(),
            'candidates_count' => count($candidates),
            'unclaimed_count' => $pick['unclaimed_count'],
        ];

        if ($resource !== null) {
            $result = TwilioDeliveryResults::accepted(
                $resource,
                $type === ChannelType::Voice ? MessagingResourceType::Call : MessagingResourceType::Message,
                $type->value,
                ['resolved_from' => RecordDeliveryAttempt::UNCERTAIN],
            );

            $attemptDelivery->applyResult($delivery, $channel, $result, self::usageEventKey($delivery));

            SystemLog::ok('notifications.delivery.uncertain_resolved', input: $input, calc: $calc, result: ['outcome' => 'adopted']);

            return;
        }

        $attemptDelivery->applyResult(
            $delivery,
            $channel,
            DeliveryResult::failure("twilio {$type->value} not found after an uncertain request"),
            self::usageEventKey($delivery),
        );

        SystemLog::ok('notifications.delivery.uncertain_resolved', input: $input, calc: $calc, result: ['outcome' => 'failed_for_retry']);
    }

    private static function whatsapp(string $value): string
    {
        return str_starts_with($value, 'whatsapp:') ? $value : 'whatsapp:'.$value;
    }

    /**
     * La clave de uso del intento original (primero, reintento o fallback),
     * derivada de la propia entrega: cobrarlo al adoptarlo es idempotente y
     * nunca cae en la clave de otra entrega.
     */
    public static function usageEventKey(NotificationDelivery $delivery): string
    {
        return match (true) {
            $delivery->fallback_from_delivery_id !== null => "notif_fallback_{$delivery->id}",
            $delivery->attempt_number > 1 => "notif_retry_{$delivery->id}_{$delivery->attempt_number}",
            default => "notif_delivery_{$delivery->id}",
        };
    }
}
