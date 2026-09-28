<?php

namespace App\Domains\Notifications\Enums;

/**
 * Tipo de recurso Twilio detrás de un envío: define qué endpoint consulta el
 * reconciliador (`messages($sid)` o `calls($sid)`) y cómo se leen sus estados.
 */
enum MessagingResourceType: string
{
    case Message = 'message';
    case Call = 'call';
}
