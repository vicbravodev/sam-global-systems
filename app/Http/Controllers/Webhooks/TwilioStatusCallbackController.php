<?php

namespace App\Http\Controllers\Webhooks;

use App\Domains\Notifications\Actions\ApplyTwilioStatusUpdate;
use App\Domains\Notifications\Actions\RecordMessagingCharge;
use App\Domains\Notifications\Enums\ChannelType;
use App\Domains\Notifications\Enums\MessagingChargeSource;
use App\Domains\Notifications\Enums\MessagingResourceType;
use App\Domains\Notifications\Models\MessagingCharge;
use App\Domains\Notifications\Models\NotificationDelivery;
use App\Domains\Notifications\Support\TwilioWebhookSignature;
use App\Http\Controllers\Controller;
use App\Support\SystemLog;
use App\Support\TenantContext;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

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
    public function __invoke(Request $request, ApplyTwilioStatusUpdate $applyStatus, RecordMessagingCharge $recordCharge): Response
    {
        // Twilio firma la URL exacta a la que se le pidió reportar. Si está
        // fijada por config (proxy/túnel), esa es la URL firmada, no la que
        // ve Laravel detrás del proxy.
        $configured = config('services.twilio.status_callback_url');

        TwilioWebhookSignature::verify($request, 'status_callback', is_string($configured) && $configured !== '' ? $configured : null);

        $isCall = $request->filled('CallSid');
        $sid = (string) ($isCall ? $request->input('CallSid') : $request->input('MessageSid', ''));
        $status = (string) ($isCall ? $request->input('CallStatus', '') : $request->input('MessageStatus', ''));

        if ($sid === '' || $status === '') {
            SystemLog::skipped('notifications.provider_status.skipped', reason: 'missing_fields', calc: [
                'sid_present' => $sid !== '',
                'status_present' => $status !== '',
            ]);

            return response('', 200);
        }

        // Lookup de entrada sin scope: el webhook no tiene sesión y es como
        // descubre a qué tenant pertenece el recurso. Ver §2.1.
        $charge = MessagingCharge::withoutGlobalScopes()->where('provider_sid', $sid)->first()
            ?? $this->adoptFromDelivery($sid, $isCall, $recordCharge);

        if ($charge === null) {
            // Sin el SID: viene del request y no hay tenant resuelto.
            SystemLog::skipped('notifications.provider_status.skipped', reason: 'unknown_sid', calc: [
                'resource_type' => $isCall ? 'call' : 'message',
            ]);

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
            answeredBy: $isCall && $request->filled('AnsweredBy') ? (string) $request->input('AnsweredBy') : null,
        );

        return response('', 200);
    }

    /**
     * El callback llegó antes que el registro del cargo (Twilio puede avisar
     * en milisegundos, antes de que el envío termine de guardar) o el
     * registro falló: si una entrega ya tiene ese SID, el cargo se crea aquí
     * y el estado se aplica, en vez de perderse hasta el siguiente sondeo.
     * El tenant sale de la entrega en DB, nunca del payload.
     */
    private function adoptFromDelivery(string $sid, bool $isCall, RecordMessagingCharge $recordCharge): ?MessagingCharge
    {
        // Lookup de entrada sin scope, por el SID que Twilio firmó.
        $matches = NotificationDelivery::withoutGlobalScopes()
            ->with('channel')
            ->where('provider_message_id', $sid)
            ->limit(2)
            ->get();

        // Inequívoco o nada: el SID sólo prueba que es de la cuenta de
        // plataforma, no de qué tenant. Dos entregas con el mismo SID, o un
        // tipo de recurso que no cuadra con el canal, no se adoptan.
        $delivery = $matches->count() === 1 ? $matches->first() : null;
        $typeMatches = $delivery?->channel !== null
            && ($delivery->channel->channel_type === ChannelType::Voice) === $isCall;

        if ($delivery === null || ! $typeMatches) {
            if ($matches->isNotEmpty()) {
                SystemLog::degraded('notifications.provider_status.charge_adopted', reason: $matches->count() > 1 ? 'ambiguous_sid' : 'resource_type_mismatch', calc: ['matches_count' => $matches->count()]);
            }

            return null;
        }

        $charge = $recordCharge->execute(
            teamId: $delivery->team_id,
            providerSid: $sid,
            resourceType: $isCall ? MessagingResourceType::Call : MessagingResourceType::Message,
            sourceType: MessagingChargeSource::NotificationDelivery,
            sourceId: $delivery->id,
            channelType: $delivery->channel->channel_type,
        );

        SystemLog::ok('notifications.provider_status.charge_adopted', input: ['delivery_id' => $delivery->id], result: ['charge_id' => $charge?->id]);

        return $charge;
    }
}
