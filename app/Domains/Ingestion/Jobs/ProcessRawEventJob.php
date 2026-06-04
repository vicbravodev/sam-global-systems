<?php

namespace App\Domains\Ingestion\Jobs;

use App\Domains\Ingestion\Actions\DetectDuplicateEvent;
use App\Domains\Ingestion\Events\RawEventFailed;
use App\Domains\Ingestion\Events\RawEventProcessed;
use App\Domains\Ingestion\Models\RawEvent;
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
        $rawEvent = RawEvent::withoutGlobalScopes()->findOrFail($this->rawEventId);

        $isDuplicate = $detectDuplicate->execute($rawEvent);

        if ($isDuplicate) {
            return;
        }

        $rawEvent->markAsProcessing();

        $rawEvent->markAsProcessed();

        RawEventProcessed::dispatch($rawEvent);
    }

    public function failed(\Throwable $exception): void
    {
        $rawEvent = RawEvent::withoutGlobalScopes()->find($this->rawEventId);

        if ($rawEvent) {
            $rawEvent->markAsFailed();

            RawEventFailed::dispatch($rawEvent, $exception->getMessage());
        }
    }
}
