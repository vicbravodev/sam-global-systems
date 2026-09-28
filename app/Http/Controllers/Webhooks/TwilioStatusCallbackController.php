<?php

namespace App\Http\Controllers\Webhooks;

use App\Domains\Notifications\Actions\ApplyTwilioStatusUpdate;
use App\Domains\Notifications\Models\MessagingCharge;
use App\Domains\Notifications\Support\PlatformTwilioConfig;
use App\Http\Controllers\Controller;
use App\Support\TenantContext;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Twilio\Security\RequestValidator;

/**
 * Status callback de Twilio para mensajes (SMS/WhatsApp) y llamadas de
 * notificación: `MessageSid` + `MessageStatus` (+ `ErrorCode`), o `CallSid` +
 * `CallStatus` (+ `CallDuration`). La firma `X-Twilio-Signature` se valida
 * con el auth token de la cuenta de plataforma.
 *
 * El tenant sale del recurso registrado al enviar (`messaging_charges`), no
 * del payload: un SID desconocido responde 200 vacío, sin efectos ni pistas.
 */
class TwilioStatusCallbackController extends Controller
{
    public function __invoke(Request $request, ApplyTwilioStatusUpdate $applyStatus): Response
    {
        $authToken = PlatformTwilioConfig::authToken();

        if ($authToken === null) {
            abort(403, 'Twilio is not configured.');
        }

        $isValid = (new RequestValidator($authToken))->validate(
            (string) $request->header('X-Twilio-Signature', ''),
            $request->fullUrl(),
            $request->post(),
        );

        abort_unless($isValid, 403, 'Invalid Twilio signature.');

        $isCall = $request->filled('CallSid');
        $sid = (string) ($isCall ? $request->input('CallSid') : $request->input('MessageSid', ''));
        $status = (string) ($isCall ? $request->input('CallStatus', '') : $request->input('MessageStatus', ''));

        if ($sid === '' || $status === '') {
            return response('', 200);
        }

        // Lookup de entrada sin scope: el webhook no tiene sesión y es como
        // descubre a qué tenant pertenece el recurso. Ver §2.1.
        $charge = MessagingCharge::withoutGlobalScopes()->where('provider_sid', $sid)->first();

        if ($charge === null) {
            return response('', 200);
        }

        TenantContext::set($charge->team_id);

        $duration = $request->input('CallDuration');
        $segments = $request->input('NumSegments');

        $applyStatus->execute(
            $charge,
            $status,
            $request->filled('ErrorCode') ? (string) $request->input('ErrorCode') : null,
            durationSeconds: $isCall && is_numeric($duration) ? (int) $duration : null,
            segments: ! $isCall && is_numeric($segments) ? (int) $segments : null,
            source: 'callback',
        );

        return response('', 200);
    }
}
