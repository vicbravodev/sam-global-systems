<?php

namespace App\Domains\Tenancy\Enums;

/**
 * Seguimiento comercial de una solicitud de demo: llega `new`, el operador la
 * marca `contacted` al hablar con el prospecto y `closed` al terminar (ganada,
 * descartada o duplicada).
 */
enum DemoRequestStatus: string
{
    case New = 'new';
    case Contacted = 'contacted';
    case Closed = 'closed';
}
