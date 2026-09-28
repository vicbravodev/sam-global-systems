<?php

namespace App\Domains\Notifications\Support;

use App\Domains\Notifications\Enums\ChannelType;
use App\Domains\Notifications\Enums\DeliveryStatus;
use App\Domains\Notifications\Models\NotificationDelivery;

/**
 * Traduce el estado técnico de una entrega a lo que el tenant necesita leer
 * ("Entregado", "Llamada contestada · 0:42", "No entregado — el número no
 * existe"...). El costo de proveedor nunca sale por aquí.
 */
final class DeliveryFeedbackPresenter
{
    /**
     * Tono visual del estado para el UI.
     */
    public static function tone(NotificationDelivery $delivery): string
    {
        return match ($delivery->status) {
            DeliveryStatus::Delivered => 'success',
            DeliveryStatus::Failed, DeliveryStatus::Bounced => 'danger',
            DeliveryStatus::Skipped, DeliveryStatus::Cancelled => 'muted',
            default => 'pending',
        };
    }

    public static function label(NotificationDelivery $delivery, ?ChannelType $channelType): string
    {
        $isCall = $channelType === ChannelType::Voice;

        return match ($delivery->status) {
            DeliveryStatus::Delivered => match (true) {
                $isCall => 'Llamada contestada'.($delivery->call_duration_seconds ? ' · '.self::duration($delivery->call_duration_seconds) : ''),
                $delivery->read_at !== null => 'Leído',
                default => 'Entregado',
            },
            DeliveryStatus::Failed, DeliveryStatus::Bounced => match (true) {
                $isCall && $delivery->provider_status === 'no-answer' => 'No contestó',
                $isCall && $delivery->provider_status === 'busy' => 'Ocupado',
                default => 'No entregado',
            },
            DeliveryStatus::Sending => $isCall ? 'Llamando…' : 'Enviando',
            DeliveryStatus::Queued => 'En cola',
            DeliveryStatus::Sent => 'Enviado al operador',
            DeliveryStatus::Skipped => 'Omitido',
            default => $delivery->status->label(),
        };
    }

    /**
     * Motivo legible de un fallo u omisión, o null.
     */
    public static function reason(NotificationDelivery $delivery): ?string
    {
        if (! in_array($delivery->status, [DeliveryStatus::Failed, DeliveryStatus::Bounced, DeliveryStatus::Skipped], true)) {
            return null;
        }

        if (($described = TwilioErrorCatalog::describe($delivery->provider_error_code)) !== null) {
            return $described;
        }

        $message = (string) $delivery->error_message;

        return match (true) {
            $message === '' => null,
            str_contains($message, 'missing phone/email') => 'El destinatario no tiene dato de contacto para este canal.',
            str_contains($message, 'not an E.164') => 'El teléfono del destinatario no tiene formato internacional válido.',
            str_contains($message, 'not a valid email') => 'El correo del destinatario no es válido.',
            str_contains($message, '`from` missing'), str_contains($message, 'credentials missing') => 'El canal no está configurado en la plataforma.',
            str_contains($message, 'no-answer') => 'La llamada no fue contestada.',
            str_contains($message, 'busy') => 'La línea estaba ocupada.',
            default => 'El proveedor rechazó el envío.',
        };
    }

    /**
     * Enmascara la dirección: se ve lo suficiente para reconocerla, no para
     * copiarla.
     */
    public static function maskAddress(?string $address): ?string
    {
        if ($address === null || $address === '') {
            return null;
        }

        $address = (string) preg_replace('/^whatsapp:/i', '', $address);

        if (str_contains($address, '@')) {
            [$local, $domain] = explode('@', $address, 2);

            return mb_substr($local, 0, 1).'***@'.$domain;
        }

        $length = mb_strlen($address);

        if ($length <= 4) {
            return str_repeat('•', $length);
        }

        return mb_substr($address, 0, min(3, $length - 4)).str_repeat('•', max(0, $length - 7)).mb_substr($address, -4);
    }

    public static function providerEventLabel(string $status): string
    {
        return match ($status) {
            'accepted', 'queued', 'scheduled' => 'Aceptado por Twilio',
            'sending' => 'Enviando',
            'sent' => 'Enviado al operador',
            'delivered' => 'Entregado',
            'read' => 'Leído',
            'undelivered' => 'No entregado',
            'failed' => 'Falló',
            'canceled' => 'Cancelado',
            'initiated' => 'Llamada iniciada',
            'ringing' => 'Timbrando',
            'in-progress' => 'Contestada',
            'completed' => 'Llamada terminada',
            'busy' => 'Ocupado',
            'no-answer' => 'No contestó',
            default => $status,
        };
    }

    private static function duration(int $seconds): string
    {
        return intdiv($seconds, 60).':'.str_pad((string) ($seconds % 60), 2, '0', STR_PAD_LEFT);
    }
}
