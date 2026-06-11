<?php

namespace App\Domains\Incidents\Support;

use App\Domains\Incidents\Models\Incident;
use App\Domains\Incidents\Models\IncidentCallVerification;

/**
 * TwiML builder for the operator verification call (Roadmap V2-A3): a Spanish
 * TTS prompt inside a one-digit <Gather> — 1 confirms the emergency, 2 flags
 * a false alarm — repeated once before giving up the call.
 */
class VerificationCallTwiml
{
    public static function prompt(IncidentCallVerification $verification, Incident $incident, string $actionUrl): string
    {
        $asset = $incident->asset?->name ?? $incident->asset?->code;
        $subject = $asset !== null && $asset !== ''
            ? "alerta de pánico en la unidad {$asset}"
            : 'alerta de pánico en su flota';

        $say = self::escape(
            "Atención. SAM reporta una {$subject}, incidente número {$incident->id}. "
            .'Presione 1 para confirmar una emergencia real. '
            .'Presione 2 si se trata de un error o falsa alarma.',
        );

        $action = self::escape($actionUrl);

        return '<?xml version="1.0" encoding="UTF-8"?>'
            .'<Response>'
            .'<Gather numDigits="1" timeout="10" action="'.$action.'" method="POST">'
            .'<Say language="es-MX">'.$say.'</Say>'
            .'<Pause length="1"/>'
            .'<Say language="es-MX">'.$say.'</Say>'
            .'</Gather>'
            .'<Say language="es-MX">No recibimos respuesta. SAM continuará con el protocolo de escalación.</Say>'
            .'</Response>';
    }

    public static function say(string $message): string
    {
        return '<?xml version="1.0" encoding="UTF-8"?>'
            .'<Response><Say language="es-MX">'.self::escape($message).'</Say></Response>';
    }

    private static function escape(string $value): string
    {
        return htmlspecialchars($value, ENT_XML1 | ENT_QUOTES, 'UTF-8');
    }
}
