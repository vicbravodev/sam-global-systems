<?php

namespace App\Http\Controllers\Webhooks;

use App\Domains\Incidents\Actions\AcknowledgeIncident;
use App\Domains\Incidents\Models\Incident;
use App\Domains\Notifications\Enums\NotificationSourceType;
use App\Domains\Notifications\Enums\RecipientType;
use App\Domains\Notifications\Models\NotificationDelivery;
use App\Domains\Notifications\Support\TwilioSpeech;
use App\Domains\Notifications\Support\TwilioWebhookSignature;
use App\Domains\Notifications\Support\TwilioWebhookUrl;
use App\Http\Controllers\Controller;
use App\Models\Membership;
use App\Support\SystemLog;
use App\Support\TenantContext;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

/**
 * La tecla de una llamada de aviso de incidente (VoiceNotificationDriver):
 * 1 = "lo atiendo", reconoce el incidente y detiene la escalera. Antes la
 * llamada sólo leía el mensaje y nadie podía atender desde ella.
 *
 * - Firma: la cuenta Twilio de plataforma (env TWILIO_*).
 * - Tenant: el de la entrega en DB, nunca el payload; el incidente se busca
 *   dentro de ese tenant.
 * - Quién atiende: el usuario destinatario si es miembro del team; un
 *   contacto externo configurado en la escalera atiende sin usuario.
 */
class TwilioNotificationCallController extends Controller
{
    public function __construct(
        private readonly AcknowledgeIncident $acknowledgeIncident,
    ) {}

    public function gather(Request $request, int $delivery): Response
    {
        $row = $this->authorizeWebhook($request, $delivery);
        $input = ['delivery_id' => $row->id, 'notification_id' => $row->notification_id];
        $digits = (string) $request->input('Digits', '');

        $notification = $row->notification;

        if ($notification === null
            || $notification->source_type !== NotificationSourceType::Incident
            || ! is_numeric($notification->source_reference_id)) {
            SystemLog::skipped('notifications.voice_ack.received', reason: 'not_an_incident_notice', input: $input);

            return $this->say('Gracias.');
        }

        $incident = Incident::query()
            ->where('team_id', $row->team_id)
            ->with('status')
            ->find((int) $notification->source_reference_id);

        if ($incident === null) {
            SystemLog::skipped('notifications.voice_ack.received', reason: 'incident_missing', input: $input);

            return $this->say('Gracias.');
        }

        $input['incident_id'] = $incident->id;

        if ($digits !== '1') {
            // Tecla equivocada (#, *, 2…): se repite la pregunta una vez; a la
            // segunda se cierra y la escalera sigue avisando.
            $retried = $request->query('retry') === '1';

            SystemLog::skipped('notifications.voice_ack.received', reason: 'invalid_digit', input: $input, calc: [
                'digits_length' => strlen($digits),
                'reprompted' => ! $retried,
            ]);

            if ($retried) {
                return $this->say(['No pudimos entender tu respuesta.', 'Vamos a seguir avisando al equipo.']);
            }

            return $this->retry($row);
        }

        if ($incident->acknowledged_at !== null || $incident->isTerminal()) {
            SystemLog::skipped('notifications.voice_ack.received', reason: 'already_handled', input: $input);

            return $this->say(['Alguien más ya está atendiendo esta alerta.', 'Gracias.']);
        }

        $userId = $this->acknowledgingUserId($row);

        $this->acknowledgeIncident->execute($incident, $userId, via: 'voice');

        SystemLog::ok('notifications.voice_ack.received', input: $input, result: ['acknowledged' => true, 'by_user' => $userId !== null]);

        return $this->say(['Gracias, quedaste a cargo de esta alerta.', 'Ya no vamos a avisar a nadie más.']);
    }

    /**
     * El usuario destinatario, sólo si sigue siendo miembro del team.
     */
    private function acknowledgingUserId(NotificationDelivery $row): ?int
    {
        $recipient = $row->recipient;

        if ($recipient === null
            || $recipient->recipient_type !== RecipientType::User
            || ! is_numeric($recipient->recipient_reference_id)) {
            return null;
        }

        $userId = (int) $recipient->recipient_reference_id;

        $isMember = Membership::query()
            ->where('team_id', $row->team_id)
            ->where('user_id', $userId)
            ->exists();

        return $isMember ? $userId : null;
    }

    private function authorizeWebhook(Request $request, int $deliveryId): NotificationDelivery
    {
        // La firma primero: sin ella no se revela qué entregas existen.
        TwilioWebhookSignature::verify($request, 'notification_call');

        // Lookup de entrada sin scope: el webhook descubre aquí su tenant.
        $row = NotificationDelivery::withoutGlobalScopes()->find($deliveryId);

        abort_if($row === null, 404, 'Unknown delivery.');

        TenantContext::set($row->team_id);

        return $row;
    }

    /**
     * "Esa opción no es válida" y la pregunta otra vez, con `retry=1` en la
     * acción para no preguntar en bucle.
     */
    private function retry(NotificationDelivery $row): Response
    {
        $action = htmlspecialchars(
            TwilioWebhookUrl::route('webhooks.twilio.voice.notification.gather', ['delivery' => $row->id, 'retry' => 1]),
            ENT_XML1 | ENT_QUOTES,
            'UTF-8',
        );

        $twiml = '<?xml version="1.0" encoding="UTF-8"?>'
            .'<Response>'
            .'<Gather numDigits="1" timeout="8" finishOnKey="" action="'.$action.'" method="POST">'
            .TwilioSpeech::say(['Esa opción no es válida.', 'Si tú la vas a atender, presiona 1.'])
            .'</Gather>'
            .TwilioSpeech::say(['No recibimos respuesta.', 'Vamos a seguir avisando al equipo.'])
            .'</Response>';

        return response($twiml, 200)->header('Content-Type', 'text/xml');
    }

    /**
     * @param  string|list<string>  $text
     */
    private function say(string|array $text): Response
    {
        return response(TwilioSpeech::response($text), 200)
            ->header('Content-Type', 'text/xml');
    }
}
