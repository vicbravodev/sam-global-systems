<?php

namespace App\Domains\Context\Jobs;

use App\Domains\Context\Actions\RefreshContextMediaSnapshot;
use App\Domains\Context\Enums\MediaRequestStatus;
use App\Domains\Context\Events\EventMediaFailed;
use App\Domains\Context\Models\EventMediaRequest;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

class FetchDeferredEventMediaJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 5;

    /** @var array<int, int> */
    public array $backoff = [30, 60, 120, 300, 600];

    public function __construct(
        public readonly int $eventMediaRequestId,
    ) {
        $this->onQueue('context');
    }

    public function handle(RefreshContextMediaSnapshot $refreshSnapshot): void
    {
        $request = EventMediaRequest::withoutGlobalScopes()->find($this->eventMediaRequestId);

        if ($request === null) {
            return;
        }

        if (! $request->status->isInFlight()) {
            return;
        }

        $request->forceFill(['status' => MediaRequestStatus::Sent])->save();

        // Provider integration is wired up by the Integrations domain. Until a
        // provider adapter is registered we keep the request in `sent` so the
        // operator UI can surface it; the snapshot still reflects current
        // media state.
        $refreshSnapshot->execute($request->normalized_event_id);

        Log::info('FetchDeferredEventMediaJob marked request as sent', [
            'event_media_request_id' => $this->eventMediaRequestId,
            'normalized_event_id' => $request->normalized_event_id,
            'request_type' => $request->request_type->value,
        ]);
    }

    public function failed(\Throwable $exception): void
    {
        $request = EventMediaRequest::withoutGlobalScopes()->find($this->eventMediaRequestId);

        if ($request !== null) {
            $request->forceFill([
                'status' => MediaRequestStatus::Failed,
                'completed_at' => now(),
            ])->save();

            EventMediaFailed::dispatch($request, $exception->getMessage());
        }

        Log::warning('FetchDeferredEventMediaJob failed', [
            'event_media_request_id' => $this->eventMediaRequestId,
            'error' => $exception->getMessage(),
        ]);
    }
}
