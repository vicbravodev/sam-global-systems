<?php

namespace App\Domains\Incidents\Actions;

use App\Domains\Incidents\Jobs\RetryIncidentWorkflowUsageJob;
use App\Domains\Incidents\Models\Incident;
use App\Domains\Tenancy\Actions\RecordUsageEvent;
use App\Support\SystemLog;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * Cobra la apertura de un incidente (meter `incident_workflows`) sin poder
 * tumbarla: corre en un savepoint propio dentro de la transacción de la
 * apertura, así que un fallo (meter ausente, error de DB) revierte sólo el
 * cobro, se reporta, queda `incidents.usage.record_failed` y, tras el commit,
 * se encola {@see RetryIncidentWorkflowUsageJob} (cola `billing`) con la MISMA
 * `event_key` (`incident_workflows:{incident_id}`): el cobro nunca se pierde
 * ni se duplica.
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
        $teamId = $incident->team_id;
        $incidentId = $incident->id;
        $eventKey = self::eventKey($incidentId);
        // El reintento cobra en el mismo periodo que el intento original.
        $occurredAt = Carbon::now();

        try {
            DB::transaction(fn () => $this->recordUsageEvent->execute(
                teamId: $teamId,
                meterCode: self::METER_CODE,
                quantity: 1,
                eventKey: $eventKey,
                metadata: $metadata,
                occurredAt: $occurredAt,
            ));

            return true;
        } catch (Throwable $e) {
            report($e);

            $input = ['incident_id' => $incidentId, 'team_id' => $teamId, 'meter_code' => self::METER_CODE, 'event_key' => $eventKey];

            // Sólo es un cobro perdido si la apertura se confirma: el log y el
            // reintento esperan al commit (y nunca salen si revierte).
            DB::afterCommit(function () use ($e, $input, $incidentId, $teamId, $metadata, $occurredAt) {
                $retryError = null;

                try {
                    RetryIncidentWorkflowUsageJob::dispatch($incidentId, $teamId, $metadata, $occurredAt->toIso8601String());
                } catch (Throwable $dispatchError) {
                    report($dispatchError);
                    $retryError = $dispatchError;
                }

                SystemLog::failed('incidents.usage.record_failed', reason: 'exception', input: $input, result: ['retry_requested' => $retryError === null, 'retry_queue' => RetryIncidentWorkflowUsageJob::QUEUE], error: $e);

                if ($retryError !== null) {
                    SystemLog::failed('incidents.usage.retry_unavailable', reason: 'dispatch_failed', input: $input, error: $retryError);
                }
            });

            return false;
        }
    }
}
