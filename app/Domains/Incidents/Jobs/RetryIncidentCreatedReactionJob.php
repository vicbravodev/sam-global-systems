<?php

namespace App\Domains\Incidents\Jobs;

use App\Domains\Incidents\Events\IncidentCreated;
use App\Domains\Incidents\Models\Incident;
use App\Domains\Incidents\Support\IncidentCreatedReaction;
use App\Domains\Incidents\Support\IsolatesIncidentCreatedReaction;
use App\Support\JobFailureReporter;
use App\Support\PipelineTrace;
use App\Support\SystemLog;
use App\Support\TenantContext;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * Reintento en cola de un efecto de IncidentCreated que falló en línea (ver
 * {@see IsolatesIncidentCreatedReaction}).
 * Corre en la cola del dominio del efecto, en su propia transacción; los
 * efectos son idempotentes por incidente (event_key de la notificación,
 * asignación existente, verificación en curso o concluida, recorrido ya
 * congelado), así que repetirlo nunca duplica.
 */
class RetryIncidentCreatedReactionJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;

    public int $timeout = 60;

    /** @var list<int> */
    public array $backoff = [10, 60];

    /**
     * @param  class-string  $reaction
     */
    public function __construct(
        public readonly string $reaction,
        public readonly int $incidentId,
        public readonly int $teamId,
        string $queue,
    ) {
        $this->onQueue($queue);
    }

    public function handle(): void
    {
        $input = ['reaction' => class_basename($this->reaction), 'attempt' => $this->attempts()];

        // Las líneas de salto también quedan en el tenant del job.
        PipelineTrace::adopt(null, $this->teamId);

        if (! is_a($this->reaction, IncidentCreatedReaction::class, true)) {
            SystemLog::skipped('incidents.created_reaction.retry_skipped', reason: 'unknown_reaction', input: $input);

            return;
        }

        // Lookup de entrada sin scope, acotado al team del job: un incidente
        // de otro tenant es, para este job, un incidente que no existe (§2.1).
        $incident = Incident::withoutGlobalScopes()
            ->where('team_id', $this->teamId)
            ->find($this->incidentId);

        if ($incident === null) {
            SystemLog::skipped('incidents.created_reaction.retry_skipped', reason: 'incident_missing', input: $input);

            return;
        }

        PipelineTrace::adopt(null, (int) $incident->team_id, ['incident_id' => $incident->id]);
        TenantContext::set($incident->team_id);

        $incident->load(['type', 'status', 'priority']);

        /** @var IncidentCreatedReaction $reaction */
        $reaction = app($this->reaction);

        DB::transaction(fn () => $reaction->react(new IncidentCreated($incident)));

        SystemLog::ok('incidents.created_reaction.retried', input: [...$input, 'incident_id' => $incident->id], result: ['recovered' => true]);
    }

    public function failed(Throwable $exception): void
    {
        JobFailureReporter::report(static::class, $exception, [
            'reaction' => class_basename($this->reaction),
            'incident_id' => $this->incidentId,
            'team_id' => $this->teamId,
        ]);
    }
}
