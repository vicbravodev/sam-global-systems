<?php

namespace App\Support\Http;

use RuntimeException;

/**
 * URL de salida rechazada por OutboundUrlGuard. El mensaje es genérico a
 * propósito: se muestra al tenant y no debe describir la red interna. El
 * motivo concreto va en `reason` (para logs).
 */
class UnsafeOutboundUrlException extends RuntimeException
{
    public function __construct(public readonly string $reason)
    {
        parent::__construct('La URL de destino no está permitida.');
    }
}
