<?php

namespace App\Domains\Incidents\Actions;

use App\Domains\Incidents\Enums\CallVerificationOutcome;
use App\Domains\Incidents\Enums\CallVerificationStatus;
use App\Domains\Incidents\Enums\IncidentCreatorType;
use App\Domains\Incidents\Enums\TimelineActorType;
use App\Domains\Incidents\Enums\TimelineEntryType;
use App\Domains\Incidents\Models\Incident;
use App\Domains\Incidents\Models\IncidentCallVerification;
use App\Domains\Incidents\Support\IncidentNoticeCopy;
use App\Domains\Incidents\Support\IncidentSuppression;
use App\Support\LoggableCode;
use App\Support\SystemLog;

/**
 * Close one unanswered/failed verification-call attempt (Roadmap V2-A3):
 * chain the next attempt while the tenant's `voice.call_attempts` budget
 * lasts; once exhausted, record the final `no_answer` outcome and escalate
 * the incident immediately — an unreachable operator IS the protocol trigger.
 */
class HandleVerificationCallAttemptFailure
{
    public function __construct(
        private readonly StartIncidentCallVerification $startVerification,
        private readonly EscalateIncident $escalateIncident,
        private readonly AppendTimelineEntry $appendTimelineEntry,
        private readonly NotifyEscalationLevel $notifyEscalationLevel,
        private readonly ArmIncidentEscalation $armIncidentEscalation,
    ) {}

    public function execute(IncidentCallVerification $verification, string $reason): void
    {
        // Sólo el prefijo estable del motivo (`placement_failed`,
        // `timeout_without_callback`, `call_status`): el resto puede traer el
        // mensaje del proveedor (con el número marcado) y nunca se registra.
        $failureCode = LoggableCode::guard(strtok($reason, ':'));
        $callStatus = $failureCode === 'call_status'
            ? LoggableCode::guard(trim(substr($reason, strlen('call_status:'))))
            : null;

        // The gather webhook may have landed first — an answered attempt is
        // never reinterpreted as a failure.
        if (! $verification->status->isInFlight()) {
            SystemLog::skipped('incidents.call_verification.attempt_failed', reason: 'already_answered', input: ['verification_id' => $verification->id], calc: ['failure_code' => $failureCode]);

            return;
        }

        $logInput = [
            'verification_id' => $verification->id,
            'incident_id' => $verification->incident_id,
            'attempt' => $verification->attempt,
        ];

        $metadata = $verification->metadata_json ?? [];
        $metadata['failure_reason'] = $reason;

        $verification->forceFill([
            'status' => CallVerificationStatus::NoAnswer,
            'metadata_json' => $metadata,
        ])->save();

        $incident = Incident::query()->find($verification->incident_id);

        if ($incident === null || $incident->isTerminal()) {
            SystemLog::skipped('incidents.call_verification.attempt_failed', reason: 'incident_terminal', input: $logInput, calc: ['failure_code' => $failureCode]);

            return;
        }

        // Un humano tomó el incidente o acusó recibo mientras sonaba la
        // llamada: ni otro intento ni escalación, el operador ya está encima.
        if (IncidentSuppression::isUnderHumanControl($incident)) {
            SystemLog::skipped('incidents.call_verification.attempt_failed', reason: 'human_control', input: $logInput, calc: ['failure_code' => $failureCode]);

            return;
        }

        $budget = $this->startVerification->attemptBudgetTerms($verification);
        $maxAttempts = $budget['budget'];
        $logCalc = ['failure_code' => $failureCode, 'call_status' => $callStatus, ...$budget];

        if ($verification->attempt < $maxAttempts) {
            $next = $this->startVerification->execute($incident, $verification->attempt + 1);

            // `next` es lo pedido; si el aviso llegó tarde (status callback
            // tras el safety net) execute() devuelve el intento que ya existía
            // (o null si no arrancó): sólo un registro recién creado es nuevo.
            SystemLog::ok('incidents.call_verification.attempt_failed', input: $logInput, calc: $logCalc, result: [
                'next' => 'next_attempt',
                'next_attempt' => $verification->attempt + 1,
                'next_attempt_created' => $next?->wasRecentlyCreated === true,
            ]);

            return;
        }

        $verification->forceFill(['outcome' => CallVerificationOutcome::NoAnswer])->save();

        $this->appendTimelineEntry->execute(
            incident: $incident,
            entryType: TimelineEntryType::VerificationCall,
            actorType: TimelineActorType::System,
            title: "Llamada de verificación sin respuesta tras {$verification->attempt} intentos",
            description: 'Nadie respondió la llamada de verificación ('.implode(', ', (array) ($verification->metadata_json['candidates'] ?? [$verification->phone])).'). Se ejecuta el protocolo de escalación.',
            payload: [
                'verification_id' => $verification->id,
                'attempts' => $verification->attempt,
                'outcome' => CallVerificationOutcome::NoAnswer->value,
            ],
        );

        $incident = $this->escalateIncident->execute(
            incident: $incident,
            reason: "Llamada de verificación sin respuesta tras {$verification->attempt} intentos.",
            escalatedByType: IncidentCreatorType::System,
        );

        $copy = IncidentNoticeCopy::verificationUnanswered($incident);

        $this->notifyEscalationLevel->execute(
            incident: $incident,
            level: 0,
            eventKey: "incident_verification_no_answer:{$incident->id}",
            notificationType: 'incident.verification_no_answer',
            subject: $copy['subject'],
            body: $copy['body'],
            spoken: $copy['spoken'],
        );

        // Ese aviso cuenta como el paso 0 de la escalera: el SLA no vuelve a
        // avisar al mismo nivel, sigue con el siguiente.
        $this->armIncidentEscalation->accelerate($incident, 'verification_no_answer');

        SystemLog::ok('incidents.call_verification.attempt_failed', input: $logInput, calc: $logCalc, result: ['next' => 'exhausted_escalated', 'outcome' => CallVerificationOutcome::NoAnswer->value]);
    }
}
