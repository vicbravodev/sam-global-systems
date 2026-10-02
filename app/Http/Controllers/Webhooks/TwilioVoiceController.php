<?php

namespace App\Http\Controllers\Webhooks;

use App\Domains\Audit\Actions\RecordAuditEntry;
use App\Domains\Audit\Enums\AuditActorType;
use App\Domains\Audit\Enums\AuditCategory;
use App\Domains\Incidents\Actions\AppendTimelineEntry;
use App\Domains\Incidents\Actions\ArmIncidentEscalation;
use App\Domains\Incidents\Actions\CloseIncident;
use App\Domains\Incidents\Actions\EscalateIncident;
use App\Domains\Incidents\Actions\HandleVerificationCallAttemptFailure;
use App\Domains\Incidents\Actions\NotifyEscalationLevel;
use App\Domains\Incidents\Enums\CallVerificationOutcome;
use App\Domains\Incidents\Enums\CallVerificationStatus;
use App\Domains\Incidents\Enums\IncidentCreatorType;
use App\Domains\Incidents\Enums\ResolutionCode;
use App\Domains\Incidents\Enums\TimelineActorType;
use App\Domains\Incidents\Enums\TimelineEntryType;
use App\Domains\Incidents\Models\Incident;
use App\Domains\Incidents\Models\IncidentCallVerification;
use App\Domains\Incidents\Support\VerificationCallTwiml;
use App\Domains\Notifications\Enums\NotificationPriority;
use App\Domains\Notifications\Support\PlatformTwilioConfig;
use App\Http\Controllers\Controller;
use App\Support\LoggableCode;
use App\Support\SystemLog;
use App\Support\TenantContext;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Twilio\Security\RequestValidator;

/**
 * Twilio Voice webhooks for the operator verification call (Roadmap V2-A3).
 *
 * `gather` receives the DTMF digit — 1 confirms a real emergency (ACK +
 * escalation + immediate notice to the first escalation level), 2 closes it
 * as a false alarm with no further protocol (decisión 2026-09-28). `status` receives Twilio's call
 * status callback so unanswered/busy/failed calls advance the retry chain.
 * Both validate `X-Twilio-Signature` with SAM's platform Twilio auth token.
 */
class TwilioVoiceController extends Controller
{
    public function __construct(
        private readonly CloseIncident $closeIncident,
        private readonly HandleVerificationCallAttemptFailure $handleFailure,
        private readonly AppendTimelineEntry $appendTimelineEntry,
        private readonly RecordAuditEntry $recordAuditEntry,
        private readonly EscalateIncident $escalateIncident,
        private readonly NotifyEscalationLevel $notifyEscalationLevel,
        private readonly ArmIncidentEscalation $armIncidentEscalation,
    ) {}

    public function gather(Request $request, int $verification): Response
    {
        $row = $this->authorizeWebhook($request, $verification);

        if ($row->outcome !== null || $row->status === CallVerificationStatus::Answered) {
            SystemLog::skipped('incidents.call_verification.answered', reason: 'already_answered', input: $this->logInput($row));

            return $this->twiml(VerificationCallTwiml::say('Ya registramos su respuesta. Gracias.'));
        }

        $digits = trim((string) $request->input('Digits', ''));

        if (! in_array($digits, ['1', '2'], true)) {
            // Nunca los dígitos: sólo cuántos llegaron.
            SystemLog::skipped('incidents.call_verification.answered', reason: 'invalid_digit', input: $this->logInput($row), calc: ['digits_length' => strlen($digits)]);

            // Invalid or absent digit: re-prompt once more on the same call.
            $incident = Incident::query()->with('asset')->find($row->incident_id);

            if ($incident === null) {
                return $this->twiml(VerificationCallTwiml::say('El incidente ya no existe. Gracias.'));
            }

            return $this->twiml(VerificationCallTwiml::prompt(
                $row,
                $incident,
                route('webhooks.twilio.voice.gather', ['verification' => $row->id]),
            ));
        }

        $incident = Incident::query()->find($row->incident_id);

        if ($incident === null || $incident->isTerminal()) {
            $outcome = $digits === '1' ? CallVerificationOutcome::ConfirmedReal : CallVerificationOutcome::ConfirmedFalse;
            $this->consume($row, $digits, $outcome);

            SystemLog::skipped('incidents.call_verification.answered', reason: 'incident_closed', input: $this->logInput($row), result: ['outcome' => $outcome->value]);

            return $this->twiml(VerificationCallTwiml::say("El incidente número {$row->incident_id} ya está cerrado. Gracias."));
        }

        return $digits === '1'
            ? $this->confirmReal($row, $incident)
            : $this->confirmFalseAlarm($row, $incident);
    }

    public function status(Request $request, int $verification): Response
    {
        $row = $this->authorizeWebhook($request, $verification);

        $callStatus = strtolower((string) $request->input('CallStatus', ''));

        if ($row->status->isInFlight() && in_array($callStatus, ['no-answer', 'busy', 'failed', 'canceled', 'completed'], true)) {
            // `completed` without a gathered digit means the callee hung up
            // without answering the prompt — also an unanswered attempt.
            // La línea la emite HandleVerificationCallAttemptFailure
            // (`failure_code = call_status`).
            $this->handleFailure->execute($row, "call_status: {$callStatus}");

            return response('', 204);
        }

        SystemLog::skipped('incidents.call_verification.status_ignored',
            reason: $row->status->isInFlight() ? 'status_not_final' : 'not_in_flight',
            input: $this->logInput($row),
            calc: ['call_status' => LoggableCode::guard($callStatus)],
            debug: true,
        );

        return response('', 204);
    }

    private function confirmReal(IncidentCallVerification $row, Incident $incident): Response
    {
        // Sin ACK (decisión 2026-10-01): quien contesta la verificación suele
        // ser el chofer, no alguien del equipo. Confirmar la emergencia acelera
        // la escalera; sólo un humano del equipo (UI, respuesta SI, tecla en
        // una llamada de escalación) la detiene.
        $this->consume($row, '1', CallVerificationOutcome::ConfirmedReal);

        $this->appendTimelineEntry->execute(
            incident: $incident,
            entryType: TimelineEntryType::VerificationCall,
            actorType: TimelineActorType::System,
            title: 'Emergencia confirmada por verificación telefónica (DTMF 1)',
            description: "El operador en {$row->phone} confirmó el incidente como emergencia real.",
            payload: ['verification_id' => $row->id, 'outcome' => CallVerificationOutcome::ConfirmedReal->value],
        );

        $this->audit($row, $incident, 'confirmed_real');

        // DTMF 1 = emergencia real: se escala, se avisa en ese momento al
        // primer nivel y ese paso de la escalera queda dado por hecho; el
        // siguiente nivel sigue su curso si nadie del equipo atiende.
        $incident = $this->escalateIncident->execute(
            incident: $incident->refresh()->load(['status', 'priority', 'type']),
            reason: "Emergencia confirmada por verificación telefónica (DTMF 1) desde {$row->phone}.",
            escalatedByType: IncidentCreatorType::System,
        );

        $this->notifyEscalationLevel->execute(
            incident: $incident,
            level: 0,
            eventKey: "incident_emergency_confirmed:{$incident->id}",
            notificationType: 'incident.emergency_confirmed',
            subject: 'EMERGENCIA CONFIRMADA: '.$incident->title,
            body: "El operador confirmó por teléfono ({$row->phone}) que la emergencia es real. Actúa ahora.",
            priority: NotificationPriority::Critical,
        );

        $this->armIncidentEscalation->accelerate($incident, 'emergency_confirmed');

        SystemLog::ok('incidents.call_verification.answered', input: $this->logInput($row), result: [
            'outcome' => CallVerificationOutcome::ConfirmedReal->value,
            'acknowledged' => false,
            'escalated' => true,
            'level_requested' => 0,
        ]);

        return $this->twiml(VerificationCallTwiml::say(
            'Emergencia confirmada. SAM escaló el incidente y está avisando a los contactos de emergencia. Gracias.',
        ));
    }

    private function confirmFalseAlarm(IncidentCallVerification $row, Incident $incident): Response
    {
        $this->closeIncident->execute(
            incident: $incident,
            resolutionCode: ResolutionCode::FalsePositive,
            summary: "Descartado como falsa alarma por verificación telefónica (DTMF 2) desde {$row->phone}.",
            resolvedByType: IncidentCreatorType::System,
        );

        $this->consume($row, '2', CallVerificationOutcome::ConfirmedFalse);

        $this->appendTimelineEntry->execute(
            incident: $incident,
            entryType: TimelineEntryType::VerificationCall,
            actorType: TimelineActorType::System,
            title: 'Falsa alarma confirmada por verificación telefónica (DTMF 2)',
            description: "El operador en {$row->phone} marcó el incidente como error/falsa alarma.",
            payload: ['verification_id' => $row->id, 'outcome' => CallVerificationOutcome::ConfirmedFalse->value],
        );

        $this->audit($row, $incident, 'confirmed_false');

        SystemLog::ok('incidents.call_verification.answered', input: $this->logInput($row), result: [
            'outcome' => CallVerificationOutcome::ConfirmedFalse->value,
            'closed' => true,
            'resolution_code' => ResolutionCode::FalsePositive->value,
        ]);

        return $this->twiml(VerificationCallTwiml::say(
            'Registrado como falsa alarma. El incidente fue cerrado. Gracias.',
        ));
    }

    /**
     * Nunca `phone`, `digits_received` ni el TwiML.
     *
     * @return array{verification_id: int, incident_id: int, attempt: int}
     */
    private function logInput(IncidentCallVerification $row): array
    {
        return ['verification_id' => $row->id, 'incident_id' => $row->incident_id, 'attempt' => $row->attempt];
    }

    private function consume(IncidentCallVerification $row, string $digits, CallVerificationOutcome $outcome): void
    {
        $row->forceFill([
            'status' => CallVerificationStatus::Answered,
            'digits_received' => $digits,
            'outcome' => $outcome,
            'responded_at' => now(),
        ])->save();
    }

    private function audit(IncidentCallVerification $row, Incident $incident, string $result): void
    {
        $this->recordAuditEntry->execute(
            actorType: AuditActorType::System,
            actorId: null,
            action: 'incident.call_verification.'.$result,
            category: AuditCategory::Domain,
            entityType: 'incident',
            entityId: $incident->id,
            summary: "Verificación telefónica del incidente {$incident->reference()}: {$result} (DTMF desde {$row->phone}).",
            teamId: $row->team_id,
            metadata: ['verification_id' => $row->id, 'attempt' => $row->attempt],
            sourceType: 'twilio_voice',
            sourceReferenceId: (string) $row->id,
        );
    }

    /**
     * Find the verification and validate the request signature with the
     * platform Twilio account that placed the call.
     */
    private function authorizeWebhook(Request $request, int $verificationId): IncidentCallVerification
    {
        $row = IncidentCallVerification::withoutGlobalScopes()->find($verificationId);

        abort_if($row === null, 404, 'Unknown verification.');

        // Las llamadas salen siempre de la cuenta Twilio de plataforma (env
        // TWILIO_*): su auth token es el único que firma estos webhooks.
        $authToken = PlatformTwilioConfig::authToken();

        if ($authToken === null) {
            abort(403, 'Twilio is not configured.');
        }

        $validator = new RequestValidator($authToken);

        $isValid = $validator->validate(
            $request->header('X-Twilio-Signature', ''),
            $request->fullUrl(),
            $request->post(),
        );

        abort_unless($isValid, 403, 'Invalid Twilio signature.');

        // Validada la firma, el resto de la petición corre dentro del tenant de
        // la verificación: el webhook entra sin sesión, así que hasta aquí no
        // había contexto que scopeara nada. Ver §2.1.
        TenantContext::set($row->team_id);

        return $row;
    }

    private function twiml(string $xml): Response
    {
        return response($xml, 200)->header('Content-Type', 'text/xml');
    }
}
