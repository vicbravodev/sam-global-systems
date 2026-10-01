<?php

namespace App\Domains\Incidents\Jobs;

use App\Domains\Incidents\Actions\RecordIncidentWorkflowUsage;
use App\Domains\Incidents\Models\Incident;
use App\Domains\Tenancy\Actions\RecordUsageEvent;
use App\Support\JobFailureReporter;
use App\Support\PipelineTrace;
use App\Support\SystemLog;
use App\Support\TenantContext;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Carbon;
use Throwable;

/**
 * Reintento del cobro de apertura de un incidente cuando falló dentro de la
 * apertura (ver {@see RecordIncidentWorkflowUsage}). Usa la MISMA `event_key`
 * (`incident_workflows:{incident_id}`) y el mismo `occurred_at`, así que es
 * idempotente: si el uso ya existe, `RecordUsageEvent` no inserta nada.
 */
class RetryIncidentWorkflowUsageJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public const string QUEUE = 'billing';

    public int $tries = 5;

    public int $timeout = 60;

    /** @var list<int> */
    public array $backoff = [30, 120, 600, 1800];

    /**
     * @param  array<string, mixed>  $metadata
     */
    public function __construct(
        public readonly int $incidentId,
        public readonly int $teamId,
        public readonly array $metadata,
        public readonly string $occurredAt,
    ) {
        $this->onQueue('billing');
    }

    public function handle(RecordUsageEvent $recordUsageEvent): void
    {
        PipelineTrace::adopt(null, $this->teamId);

        $input = ['meter_code' => RecordIncidentWorkflowUsage::METER_CODE, 'attempt' => $this->attempts()];

        // Lookup de entrada sin scope, acotado al team del job: un incidente
        // de otro tenant es, para este job, uno que no existe (§2.1).
        $incident = Incident::withoutGlobalScopes()
            ->where('team_id', $this->teamId)
            ->find($this->incidentId);

        if ($incident === null) {
            SystemLog::skipped('incidents.usage.retry_skipped', reason: 'incident_missing', input: $input);

            return;
        }

        TenantContext::set($incident->team_id);

        $eventKey = RecordIncidentWorkflowUsage::eventKey($incident->id);

        $inserted = $recordUsageEvent->record(
            teamId: $incident->team_id,
            meterCode: RecordIncidentWorkflowUsage::METER_CODE,
            quantity: 1,
            eventKey: $eventKey,
            metadata: $this->metadata,
            occurredAt: Carbon::parse($this->occurredAt),
        );

        SystemLog::ok('incidents.usage.retried',
            input: [...$input, 'incident_id' => $incident->id, 'event_key' => $eventKey],
            result: ['recorded' => $inserted, 'already_recorded' => ! $inserted],
        );
    }

    public function failed(Throwable $exception): void
    {
        JobFailureReporter::report(static::class, $exception, [
            'incident_id' => $this->incidentId,
            'team_id' => $this->teamId,
        ]);
    }
}
