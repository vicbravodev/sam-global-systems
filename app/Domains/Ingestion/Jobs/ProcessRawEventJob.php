<?php

namespace App\Domains\Ingestion\Jobs;

use App\Domains\Ingestion\Actions\DetectDuplicateEvent;
use App\Domains\Ingestion\Events\RawEventFailed;
use App\Domains\Ingestion\Events\RawEventProcessed;
use App\Domains\Ingestion\Models\RawEvent;
use App\Support\JobFailureReporter;
use App\Support\TenantContext;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

class ProcessRawEventJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;

    /** @var array<int, int> */
    public array $backoff = [10, 60, 300];

    public function __construct(
        public readonly int $rawEventId,
    ) {
        $this->onQueue('ingestion');
    }

    public function handle(DetectDuplicateEvent $detectDuplicate): void
    {
        // El job entra por su propio id, así que la búsqueda inicial no puede
        // estar scopeada; a partir de ahí se mete en el tenant del evento para
        // que todo lo demás sí lo esté, herede o no contexto del que despachó.
        // Ver §2.1.
        $rawEvent = RawEvent::withoutGlobalScopes()->findOrFail($this->rawEventId);

        TenantContext::for($rawEvent->team_id, function () use ($rawEvent, $detectDuplicate) {
            if ($detectDuplicate->execute($rawEvent)) {
                return;
            }

            $rawEvent->markAsProcessing();

            $rawEvent->markAsProcessed();

            RawEventProcessed::dispatch($rawEvent);
        });
    }

    public function failed(\Throwable $exception): void
    {
        JobFailureReporter::report(static::class, $exception, [
            'raw_event_id' => $this->rawEventId,
        ]);

        $rawEvent = RawEvent::withoutGlobalScopes()->find($this->rawEventId);

        if ($rawEvent) {
            TenantContext::for($rawEvent->team_id, function () use ($rawEvent, $exception) {
                $rawEvent->markAsFailed();

                RawEventFailed::dispatch($rawEvent, $exception->getMessage());
            });
        }
    }
}
