<?php

namespace App\Domains\Notifications\Support;

use App\Domains\Notifications\Enums\ChannelType;
use App\Support\PhoneNumber;

/**
 * ¿La dirección sirve para el canal? Evita envíos cobrables condenados a
 * fallar: un email como destino de SMS, un teléfono como destino de correo.
 */
final class ChannelAddress
{
    /**
     * Motivo legible si la dirección NO sirve para el canal; null si sirve.
     */
    public static function invalidReason(ChannelType $type, string $address): ?string
    {
        $address = trim($address);

        return match ($type) {
            ChannelType::Sms, ChannelType::Voice => PhoneNumber::isE164($address)
                ? null
                : "{$type->value} address is not an E.164 phone number",
            ChannelType::Whatsapp => PhoneNumber::isE164((string) preg_replace('/^whatsapp:/i', '', $address))
                ? null
                : 'whatsapp address is not an E.164 phone number',
            ChannelType::Email => filter_var($address, FILTER_VALIDATE_EMAIL) !== false
                ? null
                : 'email address is not a valid email',
            default => null,
        };
    }
}
