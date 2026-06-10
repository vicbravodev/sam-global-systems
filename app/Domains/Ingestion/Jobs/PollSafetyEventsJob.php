<?php

namespace App\Domains\Ingestion\Jobs;

use App\Domains\Ingestion\Actions\IngestSafetyEvent;
use App\Domains\Integrations\Contracts\ProviderAdapter;
use App\Domains\Integrations\Models\TenantIntegration;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

/**
 * Poll the provider's safety-event feed for a single integration and push
 * every event through the raw-event funnel.
 *
 * The feed cursor is persisted in `sync_state_json.safety_events.cursor` only
 * AFTER the page's events have been stored, so a crash mid-poll re-reads the
 * same window on the next run and the `safety:{id}:{eventState}` dedup keys
 * absorb the replay without duplicate side effects. The first poll (no
 * cursor) backfills from 24 hours ago.
 *
 * Unique per integration so overlapping scheduler ticks never double-poll.
 */
class PollSafetyEventsJob implements ShouldBeUnique, ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public const int BACKFILL_HOURS = 24;

    public int $tries = 3;

    /** @var array<int, int> */
    public array $backoff = [60, 300, 900];

    public int $timeout = 600;

    public function __construct(
        public readonly TenantIntegration $integration,
    ) {
        $this->onQueue('ingestion');
    }

    public function handle(
        ProviderAdapter $providerAdapter,
        IngestSafetyEvent $ingestSafetyEvent,
    ): void {
        $state = $this->integration->sync_state_json ?? [];
        $cursor = $state['safety_events']['cursor'] ?? null;

        $result = $providerAdapter->fetchSafetyEvents(
            $this->integration,
            is_string($cursor) && $cursor !== '' ? $cursor : null,
            now()->subHours(self::BACKFILL_HOURS),
        );

        foreach ($result['events'] as $payload) {
            $ingestSafetyEvent->execute($this->integration, (array) $payload);
        }

        $state['safety_events'] = [
            'cursor' => $result['cursor'],
            'last_polled_at' => now()->toIso8601String(),
        ];

        $this->integration->update(['sync_state_json' => $state]);
    }

    public function failed(\Throwable $exception): void
    {
        $this->integration->update([
            'last_error_at' => now(),
            'last_error_message' => $exception->getMessage(),
        ]);
    }

    public function uniqueId(): string
    {
        return "poll-safety-events-{$this->integration->id}";
    }
}
