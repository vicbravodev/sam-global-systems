<?php

namespace App\Domains\Incidents\Support;

use App\Domains\Incidents\Events\IncidentCreated;
use App\Domains\Incidents\Jobs\RetryIncidentCreatedReactionJob;
use App\Support\SystemLog;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * `handle()` de un listener de IncidentCreated que implementa
 * {@see IncidentCreatedReaction}.
 *
 * IncidentCreated se despacha tras el commit del incidente, así que el efecto
 * corre en línea (sin salto de cola: el aviso y la llamada de un pánico salen
 * tan rápido como antes) pero en su propia transacción. Si lanza, revierte
 * sólo lo suyo, se reporta, queda `incidents.created_reaction.failed` y se
 * pide un reintento en la cola del dominio. Nunca relanza: el incidente ya
 * está confirmado y los demás efectos tienen que correr igual.
 */
trait IsolatesIncidentCreatedReaction
{
    public function handle(IncidentCreated $event): void
    {
        try {
            DB::transaction(fn () => $this->react($event));

            return;
        } catch (Throwable $e) {
            report($e);
            $failure = $e;
        }

        $incident = $event->incident;
        $queue = $this->retryQueue();
        // `self::class` es el listener real aunque `$this` sea un doble de test.
        $input = ['reaction' => class_basename(self::class), 'incident_id' => $incident->id, 'stage' => 'inline'];
        $dispatchError = null;

        if ($incident->team_id !== null) {
            try {
                RetryIncidentCreatedReactionJob::dispatch(self::class, (int) $incident->id, (int) $incident->team_id, $queue);
            } catch (Throwable $e) {
                report($e);
                $dispatchError = $e;
            }
        }

        $retryRequested = $incident->team_id !== null && $dispatchError === null;

        SystemLog::failed('incidents.created_reaction.failed',
            reason: 'exception',
            input: $input,
            result: ['retry_requested' => $retryRequested, 'retry_queue' => $queue],
            error: $failure,
        );

        if ($dispatchError !== null) {
            SystemLog::failed('incidents.created_reaction.retry_unavailable', reason: 'dispatch_failed', input: $input, result: ['retry_queue' => $queue], error: $dispatchError);
        }
    }
}
