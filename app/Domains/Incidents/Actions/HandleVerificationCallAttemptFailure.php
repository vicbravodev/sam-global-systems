<?php

namespace App\Domains\Incidents\Actions;

use App\Contracts\TenantConfig\TenantConfigResolver;
use App\Domains\Incidents\Enums\CallVerificationOutcome;
use App\Domains\Incidents\Enums\CallVerificationStatus;
use App\Domains\Incidents\Enums\IncidentCreatorType;
use App\Domains\Incidents\Enums\TimelineActorType;
use App\Domains\Incidents\Enums\TimelineEntryType;
use App\Domains\Incidents\Models\Incident;
use App\Domains\Incidents\Models\IncidentCallVerification;

/**
 * Close one unanswered/failed verification-call attempt (Roadmap V2-A3):
 * chain the next attempt while the tenant's `voice.call_attempts` budget
 * lasts; once exhausted, record the final `no_answer` outcome and escalate
 * the incident immediately — an unreachable operator IS the protocol trigger.
 */
class HandleVerificationCallAttemptFailure
{
    public function __construct(
        private readonly TenantConfigResolver $tenantConfig,
        private readonly StartIncidentCallVerification $startVerification,
        private readonly EscalateIncident $escalateIncident,
        private readonly AppendTimelineEntry $appendTimelineEntry,
    ) {}

    public function execute(IncidentCallVerification $verification, string $reason): void
    {
        // The gather webhook may have landed first — an answered attempt is
        // never reinterpreted as a failure.
        if (! $verification->status->isInFlight()) {
            return;
        }

        $metadata = $verification->metadata_json ?? [];
        $metadata['failure_reason'] = $reason;

        $verification->forceFill([
            'status' => CallVerificationStatus::NoAnswer,
            'metadata_json' => $metadata,
        ])->save();

        $incident = Incident::withoutGlobalScopes()->find($verification->incident_id);

        if ($incident === null || $incident->isTerminal()) {
            return;
        }

        $maxAttempts = max(1, (int) $this->tenantConfig->resolve(
            (int) $verification->team_id,
            StartIncidentCallVerification::SETTING_ATTEMPTS,
            StartIncidentCallVerification::DEFAULT_ATTEMPTS,
        ));

        if ($verification->attempt < $maxAttempts) {
            $this->startVerification->execute($incident, $verification->attempt + 1);

            return;
        }

        $verification->forceFill(['outcome' => CallVerificationOutcome::NoAnswer])->save();

        $this->appendTimelineEntry->execute(
            incident: $incident,
            entryType: TimelineEntryType::VerificationCall,
            actorType: TimelineActorType::System,
            title: "Llamada de verificación sin respuesta tras {$verification->attempt} intentos",
            description: "Ningún operador respondió la llamada de verificación al {$verification->phone}. Se ejecuta el protocolo de escalación.",
            payload: [
                'verification_id' => $verification->id,
                'attempts' => $verification->attempt,
                'outcome' => CallVerificationOutcome::NoAnswer->value,
            ],
        );

        $this->escalateIncident->execute(
            incident: $incident,
            reason: "Llamada de verificación sin respuesta tras {$verification->attempt} intentos al {$verification->phone}.",
            escalatedByType: IncidentCreatorType::System,
        );
    }
}
