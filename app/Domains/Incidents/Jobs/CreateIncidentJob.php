<?php

namespace App\Domains\Incidents\Jobs;

use App\Domains\Decisions\Models\Decision;
use App\Domains\Incidents\Actions\ApplyReevaluationToIncident;
use App\Domains\Incidents\Actions\CreateIncidentFromEvent;
use App\Domains\Normalization\Models\NormalizedEvent;
use App\Support\JobFailureReporter;
use App\Support\TenantContext;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

class CreateIncidentJob implements ShouldBeUnique, ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 2;

    public int $timeout = 60;

    public int $uniqueFor = 120;

    /**
     * @param  array<string, mixed>  $context
     */
    public function __construct(
        public readonly int $normalizedEventId,
        public readonly array $context = [],
    ) {
        $this->onQueue('incidents');
    }

    public function uniqueId(): string
    {
        return 'incident:'.$this->normalizedEventId;
    }

    public function handle(CreateIncidentFromEvent $createIncidentFromEvent, ?ApplyReevaluationToIncident $applyReevaluation = null): void
    {
        $applyReevaluation ??= app(ApplyReevaluationToIncident::class);

        $event = NormalizedEvent::withoutGlobalScopes()->find($this->normalizedEventId);

        if ($event === null) {
            return;
        }

        // Trabaja dentro del tenant del propio registro: el lookup de
        // entrada no puede estar scopeado, todo lo que sigue sí. Ver §2.1.
        TenantContext::set($event->team_id);

        // Reevaluación de un evento que ya tiene incidente (v2+ de la IA):
        // se actualiza ese incidente en vez de abrir otro. Cubre también los
        // eventos sin activo/conductor, que el dedup por ventana no agrupa.
        $existing = $applyReevaluation->findExistingFor($event);

        if ($existing !== null) {
            $decision = isset($this->context['decision_id'])
                ? Decision::query()->where('team_id', $event->team_id)->find($this->context['decision_id'])
                : null;

            if ($decision !== null) {
                $applyReevaluation->execute($existing, $decision);
            }

            return;
        }

        $incident = $createIncidentFromEvent->execute($event, $this->context);

        AutoAssignIncidentJob::dispatch($incident->id);
    }

    public function failed(\Throwable $exception): void
    {
        JobFailureReporter::report(static::class, $exception, [
            'normalized_event_id' => $this->normalizedEventId,
        ]);
    }
}
