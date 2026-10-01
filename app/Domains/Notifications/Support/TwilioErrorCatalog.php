<?php

namespace App\Domains\Notifications\Support;

/**
 * Códigos de error de Twilio que importan para decidir si reintentar.
 *
 * Permanente = reintentar por el MISMO canal nunca va a funcionar (y cada
 * intento se cobra): número inválido, destinatario dado de baja (STOP), no
 * es un móvil, fijo, WhatsApp fuera de la ventana de 24 h, cuenta trial sin
 * verificar... Esos fallos saltan directo al canal de fallback.
 * Cualquier otro código (o ausencia de código) se trata como transitorio.
 *
 * @see https://www.twilio.com/docs/api/errors
 */
final class TwilioErrorCatalog
{
    /**
     * @var array<int, string> código => explicación para el tenant (PHP convierte las claves numéricas a int)
     */
    private const PERMANENT = [
        '21211' => 'El número de destino no es válido.',
        '21214' => 'El número de destino no puede recibir llamadas.',
        '21217' => 'El número de destino no es válido.',
        '21219' => 'Cuenta Twilio de prueba: el número no está verificado.',
        '21401' => 'El número de destino no es válido.',
        '21407' => 'El país de destino no está habilitado para SMS.',
        '21408' => 'El país de destino no está habilitado.',
        '21608' => 'Cuenta Twilio de prueba: el número no está verificado.',
        '21610' => 'El destinatario se dio de baja de los mensajes (STOP).',
        '21612' => 'Twilio no puede enviar a este número desde el remitente configurado.',
        '21614' => 'El número de destino no es un celular.',
        '30004' => 'El número de destino bloquea los mensajes.',
        '30005' => 'El número de destino no existe o ya no está activo.',
        '30006' => 'El número de destino es fijo o no puede recibir SMS.',
        '30007' => 'El operador filtró el mensaje como no deseado.',
        '63003' => 'El número no tiene WhatsApp.',
        '63016' => 'WhatsApp fuera de la ventana de 24 h: requiere una plantilla aprobada.',
        '63024' => 'El destinatario no es válido en WhatsApp.',
    ];

    /**
     * @var array<int, string>
     */
    private const TRANSIENT = [
        '30001' => 'La cola de envío de Twilio se saturó.',
        '30002' => 'La cuenta Twilio está suspendida.',
        '30003' => 'El teléfono de destino no estaba disponible (apagado o sin señal).',
        '30008' => 'Error desconocido del operador.',
        '30009' => 'Falta un segmento del mensaje.',
        '30010' => 'El precio del mensaje excede el máximo permitido.',
    ];

    public static function isPermanent(?string $code): bool
    {
        return $code !== null && array_key_exists($code, self::PERMANENT);
    }

    /**
     * Explicación en español para el detalle de entregas, o null si el
     * código no está catalogado.
     */
    public static function describe(?string $code): ?string
    {
        if ($code === null || $code === '') {
            return null;
        }

        return self::PERMANENT[$code] ?? self::TRANSIENT[$code] ?? null;
    }
}
