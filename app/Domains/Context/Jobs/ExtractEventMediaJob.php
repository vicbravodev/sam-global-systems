<?php

namespace App\Domains\Context\Jobs;

use App\Domains\Context\Actions\AttachImmediateEventMedia;
use App\Domains\Context\Actions\RefreshContextMediaSnapshot;
use App\Domains\Normalization\Models\NormalizedEvent;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

class ExtractEventMediaJob implements ShouldBeUnique, ShouldQueue
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

    public function handle(
        AttachImmediateEventMedia $attachImmediate,
        RefreshContextMediaSnapshot $refreshSnapshot,
    ): void {
        $normalizedEvent = NormalizedEvent::withoutGlobalScopes()
            ->with(['rawEvent.attachments'])
            ->find($this->normalizedEventId);

        if ($normalizedEvent === null) {
            return;
        }

        $created = $attachImmediate->execute($normalizedEvent);

        $refreshSnapshot->execute($normalizedEvent->id);

        Log::info('ExtractEventMediaJob processed event media', [
            'normalized_event_id' => $this->normalizedEventId,
            'media_created_count' => $created->count(),
        ]);
    }

    public function failed(\Throwable $exception): void
    {
        Log::warning('ExtractEventMediaJob failed', [
            'normalized_event_id' => $this->normalizedEventId,
            'error' => $exception->getMessage(),
        ]);
    }
}
