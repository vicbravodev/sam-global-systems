<?php

namespace App\Domains\Context\Jobs;

use App\Domains\Context\Actions\BuildEventContext;
use App\Domains\Normalization\Enums\NormalizedEventStatus;
use App\Domains\Normalization\Models\NormalizedEvent;
use App\Support\TenantContext;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

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
            return;
        }

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

        if ($normalizedEvent) {
            $normalizedEvent->forceFill(['status' => NormalizedEventStatus::Failed])->save();
        }

        Log::warning('EnrichContextJob failed', [
            'normalized_event_id' => $this->normalizedEventId,
            'error' => $exception->getMessage(),
        ]);
    }
}
