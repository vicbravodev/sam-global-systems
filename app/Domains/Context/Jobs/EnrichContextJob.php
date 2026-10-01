<?php

namespace App\Domains\Context\Jobs;

use App\Domains\Context\Actions\BuildEventContext;
use App\Domains\Normalization\Enums\NormalizedEventStatus;
use App\Domains\Normalization\Models\NormalizedEvent;
use App\Support\JobFailureReporter;
use App\Support\PipelineTrace;
use App\Support\SystemLog;
use App\Support\TenantContext;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

class EnrichContextJob implements ShouldBeUnique, ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;

    public int $uniqueFor = 60;

    /** @var array<int, int> */
    public array $backoff = [10, 30, 60];

    public function __construct(
        public readonly int $normalizedEventId,
    ) {
        $this->onQueue('context');
    }

    public function uniqueId(): string
    {
        return (string) $this->normalizedEventId;
    }

    public function handle(BuildEventContext $buildEventContext): void
    {
        $normalizedEvent = NormalizedEvent::withoutGlobalScopes()->find($this->normalizedEventId);

        if ($normalizedEvent === null) {
            SystemLog::skipped('context.enrich.skipped', reason: 'normalized_event_missing', input: ['normalized_event_id' => $this->normalizedEventId]);

            return;
        }

        PipelineTrace::adopt($normalizedEvent->trace_id, $normalizedEvent->team_id, ['normalized_event_id' => $normalizedEvent->id]);

        // Entra en el tenant del evento antes de construir el contexto: el
        // enriquecimiento lee historial, geocercas e incidentes previos, y todo
        // eso tiene que quedarse dentro del tenant. Ver §2.1.
        TenantContext::for(
            $normalizedEvent->team_id,
            fn () => $buildEventContext->execute($normalizedEvent),
        );
    }

    public function failed(\Throwable $exception): void
    {
        $normalizedEvent = NormalizedEvent::withoutGlobalScopes()->find($this->normalizedEventId);

        if ($normalizedEvent !== null) {
            $normalizedEvent->forceFill(['status' => NormalizedEventStatus::Failed])->save();
        }

        JobFailureReporter::report(static::class, $exception, ['normalized_event_id' => $this->normalizedEventId]);
    }
}
