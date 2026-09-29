<?php

namespace App\Domains\Automation\Support;

use RuntimeException;
use Throwable;

/**
 * Fallo esperado de una acción de automatización con un código estable
 * (`kind`) para el log. El mensaje es el mismo de siempre (va a
 * `error_message`, al log de la ejecución y a `ActionFailed`) y puede llevar
 * el `target_reference` o ids ajenos: nunca se registra, sólo `kind` y
 * `context`.
 */
final class ActionFailure extends RuntimeException
{
    /** @param array<string, int|string|bool|null> $context */
    public function __construct(public readonly string $kind, string $message, public readonly array $context = [], ?Throwable $previous = null)
    {
        parent::__construct($message, 0, $previous);
    }

    /**
     * Clase del fallo para el log: la de la excepción original cuando esta
     * sólo la re-etiqueta con un `kind` estable.
     */
    public function errorClass(): string
    {
        return class_basename($this->getPrevious() ?? $this);
    }
}
