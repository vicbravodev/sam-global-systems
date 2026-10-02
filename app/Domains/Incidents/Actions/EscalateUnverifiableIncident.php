<?php

namespace App\Domains\Incidents\Actions;

use App\Domains\Incidents\Enums\IncidentCreatorType;
use App\Domains\Incidents\Enums\IncidentStatusCode;
use App\Domains\Incidents\Enums\TimelineActorType;
use App\Domains\Incidents\Enums\TimelineEntryType;
use App\Domains\Incidents\Models\Incident;
use App\Domains\Incidents\Models\IncidentTimeline;
use App\Support\SystemLog;
use Illuminate\Support\Facades\DB;

/**
 * La llamada de verificación NO se pudo hacer (sin teléfono a quién llamar,
 * canal de voz apagado o sin credenciales): antes eso sólo quedaba en el log
 * y el pánico se quedaba esperando. Ahora queda en la línea de tiempo del
 * incidente y se ejecuta la escalación en ese mismo momento. Idempotente por
 * motivo: un reintento no duplica la entrada.
 */
class EscalateUnverifiableIncident
{
    public function __construct(
        private readonly AppendTimelineEntry $appendTimelineEntry,
        private readonly EscalateIncident $escalateIncident,
        private readonly NotifyEscalationLevel $notifyEscalationLevel,
        private readonly ArmIncidentEscalation $armIncidentEscalation,
    ) {}

    public function execute(Incident $incident, string $reason, string $description): void
    {
        // `$reason` es una constante del código; `$description` (texto en
        // español) nunca se registra.
        $logInput = ['incident_id' => $incident->id, 'unverifiable_code' => $reason];

        if ($incident->isTerminal()) {
            DB::afterCommit(fn () => SystemLog::skipped('incidents.call_verification.unverifiable_escalated', reason: 'incident_terminal', input: $logInput));

            return;
        }

        $alreadyRecorded = IncidentTimeline::query()
            ->where('incident_id', $incident->id)
            ->where('entry_type', TimelineEntryType::VerificationCall)
            ->where('payload_json->unverifiable_reason', $reason)
            ->exists();

        if ($alreadyRecorded) {
            DB::afterCommit(fn () => SystemLog::skipped('incidents.call_verification.unverifiable_escalated', reason: 'already_recorded', input: $logInput));

            return;
        }

        $this->appendTimelineEntry->execute(
            incident: $incident,
            entryType: TimelineEntryType::VerificationCall,
            actorType: TimelineActorType::System,
            title: 'No se pudo hacer la llamada de verificación',
            description: $description.' Se ejecuta el protocolo de escalación.',
            payload: ['unverifiable_reason' => $reason],
        );

        $incident->loadMissing('status');

        $escalatedNow = $incident->status?->code !== IncidentStatusCode::Escalated->value;

        if ($escalatedNow) {
            $incident = $this->escalateIncident->execute(
                incident: $incident,
                reason: $description,
                escalatedByType: IncidentCreatorType::System,
            );
        }

        // Escalar no es sólo cambiar de estado: el primer nivel se entera YA.
        $this->notifyEscalationLevel->execute(
            incident: $incident,
            level: 0,
            eventKey: "incident_unverifiable:{$incident->id}:{$reason}",
            notificationType: 'incident.verification_unavailable',
            subject: 'Emergencia sin verificar: '.$incident->title,
            body: $description.' Atiéndela ahora.',
        );

        // Ese aviso cuenta como el paso 0 de la escalera (ver accelerate()).
        $this->armIncidentEscalation->accelerate($incident, 'verification_unavailable');

        $incident->loadMissing('status');
        $escalatedResult = ['escalated_now' => $escalatedNow, 'status_after' => $incident->status?->code];
        DB::afterCommit(fn () => SystemLog::ok('incidents.call_verification.unverifiable_escalated', input: $logInput, result: $escalatedResult));
    }
}
