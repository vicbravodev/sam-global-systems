<?php

namespace App\Domains\Incidents\Actions;

use App\Domains\Incidents\Models\Incident;
use App\Domains\Tenancy\Actions\RecordUsageEvent;
use App\Support\SystemLog;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * Cobra la apertura de un incidente (meter `incident_workflows`) sin poder
 * tumbarla: corre en un savepoint propio dentro de la transacción de la
 * apertura, así que un fallo (meter ausente, error de DB) revierte sólo el
 * cobro, se reporta y queda `incidents.usage.record_failed`. Idempotente por
 * `event_key` (`incident_workflows:{incident_id}`).
 */
class RecordIncidentWorkflowUsage
{
    public const string METER_CODE = 'incident_workflows';

    public function __construct(
        private readonly RecordUsageEvent $recordUsageEvent,
    ) {}

    public static function eventKey(int $incidentId): string
    {
        return self::METER_CODE.':'.$incidentId;
    }

    /**
     * @param  array<string, mixed>  $metadata
     * @return bool si el cobro quedó registrado (o ya lo estaba)
     */
    public function execute(Incident $incident, array $metadata): bool
    {
        $teamId = (int) $incident->team_id;
        $eventKey = self::eventKey((int) $incident->id);

        try {
            DB::transaction(fn () => $this->recordUsageEvent->execute(
                teamId: $teamId,
                meterCode: self::METER_CODE,
                quantity: 1,
                eventKey: $eventKey,
                metadata: $metadata,
            ));

            return true;
        } catch (Throwable $e) {
            report($e);

            $input = ['incident_id' => $incident->id, 'team_id' => $teamId, 'meter_code' => self::METER_CODE, 'event_key' => $eventKey];

            // Sólo es un cobro perdido si la apertura se confirma.
            DB::afterCommit(fn () => SystemLog::failed('incidents.usage.record_failed', reason: 'exception', input: $input, error: $e));

            return false;
        }
    }
}
