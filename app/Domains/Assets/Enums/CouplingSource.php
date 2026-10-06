<?php

namespace App\Domains\Assets\Enums;

/**
 * De dónde salió un enganche tracto–remolque. Hoy sólo se infiere por
 * co-movimiento: las asignaciones declaradas de Samsara llegan vacías.
 */
enum CouplingSource: string
{
    case CoMovement = 'co_movement';
}
