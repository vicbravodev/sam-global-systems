<?php

namespace App\Domains\Incidents\Jobs;

use App\Domains\Incidents\Actions\CreateIncidentFromEvent;
use App\Domains\Normalization\Models\NormalizedEvent;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

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

    public function handle(CreateIncidentFromEvent $createIncidentFromEvent): void
    {
        $event = NormalizedEvent::withoutGlobalScopes()->find($this->normalizedEventId);

        if ($event === null) {
            return;
        }

        $incident = $createIncidentFromEvent->execute($event, $this->context);

        AutoAssignIncidentJob::dispatch($incident->id);
    }

    public function failed(\Throwable $exception): void
    {
        Log::warning('CreateIncidentJob failed', [
            'normalized_event_id' => $this->normalizedEventId,
            'error' => $exception->getMessage(),
        ]);
    }
}
