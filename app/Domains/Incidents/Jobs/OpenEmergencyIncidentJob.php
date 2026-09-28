<?php

namespace App\Domains\Incidents\Jobs;

use App\Domains\Incidents\Actions\ApplyReevaluationToIncident;
use App\Domains\Incidents\Actions\CreateIncidentFromEvent;
use App\Domains\Incidents\Enums\IncidentPriorityCode;
use App\Domains\Normalization\Models\NormalizedEvent;
use App\Support\JobFailureReporter;
use App\Support\TenantContext;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

/**
 * Abre el incidente CRÍTICO de una emergencia antes de la IA (ver
 * OpenEmergencyIncidentOnEventNormalized). Idempotente: si el evento ya tiene
 * incidente (reintento, o la IA ganó la carrera) no hace nada; el dedup por
 * activo de CreateIncidentFromEvent agrupa pulsaciones repetidas.
 */
class OpenEmergencyIncidentJob implements ShouldBeUnique, ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 5;

    /** @var array<int, int> */
    public array $backoff = [2, 5, 10, 30];

    public int $timeout = 60;

    public int $uniqueFor = 300;

    public function __construct(
        public readonly int $normalizedEventId,
        public readonly int $teamId,
        public readonly string $priorityCode = IncidentPriorityCode::Critical->value,
    ) {
        $this->onQueue('incidents');
    }

    public function uniqueId(): string
    {
        return 'emergency-incident:'.$this->normalizedEventId;
    }

    public function handle(CreateIncidentFromEvent $createIncident, ApplyReevaluationToIncident $reevaluation): void
    {
        // Lookup de entrada sin scope: así descubre el job su tenant (§2.1).
        $event = NormalizedEvent::withoutGlobalScopes()->find($this->normalizedEventId);

        if ($event === null || (int) $event->team_id !== $this->teamId) {
            return;
        }

        TenantContext::set($event->team_id);

        if ($reevaluation->findExistingFor($event) !== null) {
            return;
        }

        $createIncident->execute($event, [
            'priority_code' => $this->priorityCode,
            'metadata' => [
                'emergency_fast_path' => true,
                'unmonitored_asset' => (bool) ($event->payload_normalized_json['unmonitored_asset'] ?? false),
            ],
        ]);
    }

    public function failed(\Throwable $exception): void
    {
        JobFailureReporter::report(static::class, $exception, [
            'normalized_event_id' => $this->normalizedEventId,
            'team_id' => $this->teamId,
        ]);
    }
}
