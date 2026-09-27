<?php

namespace App\Domains\Normalization\Jobs;

use App\Domains\Ingestion\Enums\RawEventStatus;
use App\Domains\Ingestion\Models\RawEvent;
use App\Domains\Normalization\Actions\NormalizeRawEvent;
use App\Support\TenantContext;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

class NormalizeEventJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;

    /** @var array<int, int> */
    public array $backoff = [10, 30, 60];

    public function __construct(
        public readonly int $rawEventId,
    ) {
        $this->onQueue('normalization');
    }

    public function handle(NormalizeRawEvent $normalizeRawEvent): void
    {
        $rawEvent = RawEvent::withoutGlobalScopes()->find($this->rawEventId);

        if (! $rawEvent) {
            return;
        }

        $allowedStatuses = [
            RawEventStatus::PendingProcessing,
            RawEventStatus::Processing,
            RawEventStatus::Processed,
        ];

        if (! in_array($rawEvent->status, $allowedStatuses, true)) {
            return;
        }

        // Se mete en el tenant del evento antes de normalizar: la resolución de
        // activo y conductor por id de proveedor ocurre aquí dentro, y es donde
        // se coló la fuga que arregló 3695360. Ver §2.1.
        TenantContext::for($rawEvent->team_id, function () use ($rawEvent, $normalizeRawEvent) {
            $rawEvent->markAsProcessing();

            $normalizeRawEvent->execute($rawEvent);
        });
    }

    public function failed(\Throwable $exception): void
    {
        $rawEvent = RawEvent::withoutGlobalScopes()->find($this->rawEventId);

        if ($rawEvent) {
            $rawEvent->markAsFailed();
        }

        Log::error('NormalizeEventJob failed', [
            'raw_event_id' => $this->rawEventId,
            'exception' => $exception->getMessage(),
        ]);
    }
}
