<?php

namespace App\Http\Controllers\Webhooks;

use App\Domains\Audit\Actions\RecordAuditEntry;
use App\Domains\Audit\Enums\AuditActorType;
use App\Domains\Audit\Enums\AuditCategory;
use App\Domains\Incidents\Actions\AcknowledgeIncident;
use App\Domains\Incidents\Actions\AppendTimelineEntry;
use App\Domains\Incidents\Actions\CloseIncident;
use App\Domains\Incidents\Actions\HandleVerificationCallAttemptFailure;
use App\Domains\Incidents\Enums\CallVerificationOutcome;
use App\Domains\Incidents\Enums\CallVerificationStatus;
use App\Domains\Incidents\Enums\IncidentCreatorType;
use App\Domains\Incidents\Enums\ResolutionCode;
use App\Domains\Incidents\Enums\TimelineActorType;
use App\Domains\Incidents\Enums\TimelineEntryType;
use App\Domains\Incidents\Models\Incident;
use App\Domains\Incidents\Models\IncidentCallVerification;
use App\Domains\Incidents\Support\VerificationCallTwiml;
use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Twilio\Security\RequestValidator;

/**
 * Twilio Voice webhooks for the operator verification call (Roadmap V2-A3).
 *
 * `gather` receives the DTMF digit — 1 acknowledges the incident as a real
 * emergency, 2 closes it as a false alarm. `status` receives Twilio's call
 * status callback so unanswered/busy/failed calls advance the retry chain.
 * Both validate `X-Twilio-Signature` against the channel that placed the
 * call.
 */
class TwilioVoiceController extends Controller
{
    public function __construct(
        private readonly AcknowledgeIncident $acknowledgeIncident,
        private readonly CloseIncident $closeIncident,
        private readonly HandleVerificationCallAttemptFailure $handleFailure,
        private readonly AppendTimelineEntry $appendTimelineEntry,
        private readonly RecordAuditEntry $recordAuditEntry,
    ) {}

    public function gather(Request $request, int $verification): Response
    {
        $row = $this->authorizeWebhook($request, $verification);

        if ($row->outcome !== null || $row->status === CallVerificationStatus::Answered) {
            return $this->twiml(VerificationCallTwiml::say('Ya registramos su respuesta. Gracias.'));
        }

        $digits = trim((string) $request->input('Digits', ''));

        if (! in_array($digits, ['1', '2'], true)) {
            // Invalid or absent digit: re-prompt once more on the same call.
            $incident = Incident::withoutGlobalScopes()->with('asset')->find($row->incident_id);

            if ($incident === null) {
                return $this->twiml(VerificationCallTwiml::say('El incidente ya no existe. Gracias.'));
            }

            return $this->twiml(VerificationCallTwiml::prompt(
                $row,
                $incident,
                route('webhooks.twilio.voice.gather', ['verification' => $row->id]),
            ));
        }

        $incident = Incident::withoutGlobalScopes()->find($row->incident_id);

        if ($incident === null || $incident->isTerminal()) {
            $this->consume($row, $digits, $digits === '1' ? CallVerificationOutcome::ConfirmedReal : CallVerificationOutcome::ConfirmedFalse);

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
            $this->handleFailure->execute($row, "call_status: {$callStatus}");
        }

        return response('', 204);
    }

    private function confirmReal(IncidentCallVerification $row, Incident $incident): Response
    {
        $this->acknowledgeIncident->execute($incident, null, via: 'voice');

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

        return $this->twiml(VerificationCallTwiml::say(
            'Emergencia confirmada. SAM activó el protocolo y notificó a los contactos del incidente. Gracias.',
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

        return $this->twiml(VerificationCallTwiml::say(
            'Registrado como falsa alarma. El incidente fue cerrado. Gracias.',
        ));
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
            summary: "Verificación telefónica del incidente #{$incident->id}: {$result} (DTMF desde {$row->phone}).",
            teamId: $row->team_id,
            metadata: ['verification_id' => $row->id, 'attempt' => $row->attempt],
            sourceType: 'twilio_voice',
            sourceReferenceId: (string) $row->id,
        );
    }

    /**
     * Find the verification and validate the request signature against the
     * Twilio channel that placed the call.
     */
    private function authorizeWebhook(Request $request, int $verificationId): IncidentCallVerification
    {
        $row = IncidentCallVerification::withoutGlobalScopes()
            ->with('channel')
            ->find($verificationId);

        abort_if($row === null, 404, 'Unknown verification.');

        $config = $row->channel?->config_json ?? [];
        $authToken = $config['twilio_auth_token'] ?? $config['auth_token'] ?? null;

        if (! is_string($authToken) || $authToken === '') {
            abort(403, 'Verification has no Twilio channel to validate against.');
        }

        $validator = new RequestValidator($authToken);

        $isValid = $validator->validate(
            (string) $request->header('X-Twilio-Signature', ''),
            $request->fullUrl(),
            $request->post(),
        );

        abort_unless($isValid, 403, 'Invalid Twilio signature.');

        return $row;
    }

    private function twiml(string $xml): Response
    {
        return response($xml, 200)->header('Content-Type', 'text/xml');
    }
}
