<?php

namespace App\Infrastructure\AI\Clef;

use RuntimeException;

/**
 * Fallo de una llamada a Clef. El mensaje es un código propio: nunca lleva el
 * cuerpo de la respuesta, el estado enviado ni el token.
 */
class ClefRequestFailedException extends RuntimeException
{
    public function __construct(
        public readonly string $reason,
        public readonly bool $retryable,
    ) {
        parent::__construct('Clef request failed: '.$reason);
    }
}
