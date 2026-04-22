<?php

namespace App\Domains\Ingestion\Jobs;

use App\Domains\Ingestion\Actions\QueueRawEventForProcessing;
use App\Domains\Ingestion\Actions\StoreRawEvent;
use App\Domains\Ingestion\Enums\EventSourceType;
use App\Domains\Ingestion\Models\EventSource;
use App\Domains\Integrations\Contracts\ProviderAdapter;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

class PollExternalProviderJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 2;

    /** @var array<int, int> */
    public array $backoff = [30, 120];

    public function __construct(
        public readonly int $eventSourceId,
    ) {
        $this->onQueue('sync');
    }

    public function handle(
        ProviderAdapter $providerAdapter,
        StoreRawEvent $storeRawEvent,
        QueueRawEventForProcessing $queueForProcessing,
    ): void {
        $eventSource = EventSource::withoutGlobalScopes()->findOrFail($this->eventSourceId);
        $integration = $eventSource->tenantIntegration;

        if ($integration === null) {
            return;
        }

        $result = $providerAdapter->sync($integration, 'incremental');
        $events = $result['events'] ?? [];

        foreach ($events as $eventData) {
            $rawEvent = $storeRawEvent->execute(
                payload: $eventData,
                sourceType: EventSourceType::Polling->value,
                teamId: $eventSource->team_id,
                providerId: $eventSource->provider_id,
                externalEventId: $eventData['eventId'] ?? $eventData['id'] ?? null,
            );

            $queueForProcessing->execute($rawEvent);
        }

        $config = $eventSource->config_json ?? [];
        $config['last_polled_at'] = now()->toIso8601String();
        $eventSource->update(['config_json' => $config]);
    }
}
