<?php

namespace App\Domains\Incidents\Support;

use App\Domains\Incidents\Models\Incident;
use App\Domains\Incidents\Models\IncidentCallVerification;
use App\Domains\Notifications\Support\TwilioSpeech;

/**
 * TwiML builder for the verification call (Roadmap V2-A3): a calm Spanish
 * prompt inside a one-digit <Gather> — 1 confirms the emergency, 2 flags a
 * false alarm — repeated once before giving up the call. Voice and pace come
 * from TwilioSpeech.
 */
class VerificationCallTwiml
{
    public static function prompt(IncidentCallVerification $verification, Incident $incident, string $actionUrl): string
    {
        $unit = IncidentNoticeCopy::spokenUnit($incident);
        $alert = $unit !== null
            ? "Recibimos una alerta del botón de pánico en la unidad {$unit}."
            : 'Recibimos una alerta del botón de pánico en tu flota.';

        $options = [
            'Si es una emergencia real, presiona 1.',
            'Si fue un error o una falsa alarma, presiona 2.',
        ];

        $action = htmlspecialchars($actionUrl, ENT_XML1 | ENT_QUOTES, 'UTF-8');

        return '<?xml version="1.0" encoding="UTF-8"?>'
            .'<Response>'
            .'<Gather numDigits="1" timeout="10" action="'.$action.'" method="POST">'
            .TwilioSpeech::say(['Hola, te llamamos de SAM.', $alert, ...$options])
            .'<Pause length="1"/>'
            .TwilioSpeech::say(['Te repito.', ...$options])
            .'</Gather>'
            .TwilioSpeech::say(['No recibimos respuesta.', 'Vamos a avisar a tu equipo de monitoreo para que te apoye.'])
            .'</Response>';
    }

    /**
     * @param  string|list<string>  $message
     */
    public static function say(string|array $message): string
    {
        return TwilioSpeech::response($message);
    }
}
