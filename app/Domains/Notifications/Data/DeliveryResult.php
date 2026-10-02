<?php

namespace App\Domains\Notifications\Data;

use App\Domains\Notifications\Enums\MessagingResourceType;

/**
 * Resultado de un intento de envío de un driver.
 *
 * `success` sólo dice que el driver/proveedor aceptó el envío. Los drivers
 * síncronos (email, web, push...) no tienen más feedback y su éxito es una
 * entrega. Los drivers Twilio devuelven `accepted()`: Twilio encoló el
 * mensaje/llamada (hay SID) y la entrega real llega después por status
 * callback o por el reconciliador (`awaitingProviderConfirmation`).
 *
 * `permanent` marca fallos que no se arreglan reintentando por el mismo
 * canal (número inválido, opt-out, canal sin configurar...).
 */
class DeliveryResult
{
    /**
     * @param  array<string, mixed>|null  $response
     */
    public function __construct(
        public readonly bool $success,
        public readonly ?string $providerMessageId = null,
        public readonly ?array $response = null,
        public readonly ?string $errorMessage = null,
        public readonly bool $awaitingProviderConfirmation = false,
        public readonly bool $permanent = false,
        public readonly ?string $providerErrorCode = null,
        public readonly ?string $providerStatus = null,
        public readonly ?MessagingResourceType $resourceType = null,
        public readonly ?int $segments = null,
        public readonly bool $uncertain = false,
    ) {}

    /**
     * Entrega síncrona confirmada (el canal no tiene feedback posterior).
     *
     * @param  array<string, mixed>|null  $response
     */
    public static function success(?string $providerMessageId = null, ?array $response = null): self
    {
        return new self(true, $providerMessageId, $response);
    }

    /**
     * El proveedor aceptó el envío y confirmará la entrega más tarde.
     *
     * @param  array<string, mixed>|null  $response
     */
    public static function accepted(
        string $providerMessageId,
        MessagingResourceType $resourceType,
        ?string $providerStatus = null,
        ?int $segments = null,
        ?array $response = null,
    ): self {
        return new self(
            success: true,
            providerMessageId: $providerMessageId,
            response: $response,
            awaitingProviderConfirmation: true,
            providerStatus: $providerStatus,
            resourceType: $resourceType,
            segments: $segments,
        );
    }

    /**
     * La petición al proveedor no tuvo respuesta (timeout, red caída): pudo
     * o no haberse creado el mensaje/llamada. No es un fallo — reintentar a
     * ciegas manda un duplicado que se cobra —: la entrega queda en vuelo y
     * ResolveUncertainDeliveryJob lo averigua en el proveedor.
     *
     * @param  array<string, mixed>|null  $response
     */
    public static function uncertain(string $errorMessage, ?array $response = null): self
    {
        return new self(
            success: false,
            response: $response,
            errorMessage: $errorMessage,
            uncertain: true,
        );
    }

    /**
     * @param  array<string, mixed>|null  $response
     */
    public static function failure(
        string $errorMessage,
        ?array $response = null,
        bool $permanent = false,
        ?string $providerErrorCode = null,
    ): self {
        return new self(
            success: false,
            response: $response,
            errorMessage: $errorMessage,
            permanent: $permanent,
            providerErrorCode: $providerErrorCode,
        );
    }
}
