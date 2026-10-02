<?php

namespace App\Domains\Notifications\Channels;

use App\Domains\Notifications\Data\DeliveryResult;
use App\Domains\Notifications\Enums\MessagingResourceType;
use App\Domains\Notifications\Support\TwilioErrorCatalog;
use App\Support\SafeErrorMessage;
use Twilio\Exceptions\RestException;

/**
 * Traducción común de las respuestas de Twilio a DeliveryResult para los
 * drivers SMS / WhatsApp / voz.
 */
final class TwilioDeliveryResults
{
    /**
     * Twilio aceptó el recurso: la entrega queda pendiente de su feedback.
     *
     * @param  array<string, mixed>  $extra  Datos adicionales para `response_json`.
     */
    public static function accepted(object $resource, MessagingResourceType $type, string $driver, array $extra = []): DeliveryResult
    {
        $sid = (string) ($resource->sid ?? '');
        $status = (string) ($resource->status ?? '');
        $segments = isset($resource->numSegments) && is_numeric($resource->numSegments)
            ? (int) $resource->numSegments
            : null;

        return DeliveryResult::accepted(
            providerMessageId: $sid,
            resourceType: $type,
            providerStatus: $status !== '' ? $status : null,
            segments: $segments,
            response: ['driver' => $driver, 'status' => $status, 'sid' => $sid, ...$extra],
        );
    }

    /**
     * Error de configuración de plataforma (sin remitente, sin cuenta): no
     * se arregla reintentando, así que es permanente para este canal.
     */
    public static function misconfigured(string $message, string $driver): DeliveryResult
    {
        return DeliveryResult::failure($message, ['driver' => $driver], permanent: true);
    }

    /**
     * - RestException: Twilio respondió con un error, el recurso NO se creó;
     *   el código decide si es permanente.
     * - Cualquier otra cosa (timeout, red, respuesta ilegible): no se sabe si
     *   Twilio lo creó → resultado incierto, nunca un reintento a ciegas.
     */
    public static function fromException(\Throwable $e, string $driver): DeliveryResult
    {
        if ($e instanceof RestException) {
            $code = $e->getCode() !== 0 ? (string) $e->getCode() : null;

            return DeliveryResult::failure(
                "twilio {$driver} error: ".SafeErrorMessage::from($e),
                ['driver' => $driver, 'twilio_code' => $e->getCode()],
                permanent: TwilioErrorCatalog::isPermanent($code),
                providerErrorCode: $code,
            );
        }

        return DeliveryResult::uncertain(
            "{$driver} request outcome unknown: ".SafeErrorMessage::from($e),
            ['driver' => $driver, 'exception' => $e::class],
        );
    }
}
