<?php

namespace App\Support;

use InvalidArgumentException;

/**
 * Línea de SystemLog que no cumple el esquema (código o reason inválidos).
 * Se lanza fuera de producción; es el único error de log que se propaga.
 */
final class SystemLogSchemaViolation extends InvalidArgumentException {}
