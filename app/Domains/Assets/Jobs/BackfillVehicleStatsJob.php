<?php

namespace App\Domains\Assets\Jobs;

use App\Domains\Assets\Actions\IngestVehicleStatsPage;
use App\Domains\Assets\Enums\TelematicsFeed;
use App\Domains\Integrations\Contracts\ProviderAdapter;
use App\Domains\Integrations\Exceptions\ProviderRateLimited;
use App\Domains\Integrations\Exceptions\ProviderUnauthorized;
use App\Domains\Integrations\Models\TenantIntegration;
use App\Support\SystemLog;
use App\Support\TenantContext;
use Carbon\CarbonInterface;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\DB;

/**
 * Refill a gap the feed can no longer replay (its cursor expired or was
 * rejected) from the provider's history endpoint, capped at
 * `telematics.backfill_hours` back.
 *
 * Runs apart from the live feed so a long backfill never delays the map.
 * Writing through the same idempotent ingest means any overlap with what the
 * restarted feed already stored is simply ignored, and older points only
 * land as history — they never rewind an asset's live position.
 */
class BackfillVehicleStatsJob implements ShouldBeUnique, ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 5;

    /** Below the queue's retry_after (240 s), so a long backfill is never picked up twice. */
    public int $timeout = 200;

    public int $uniqueFor = 900;

    private const MAX_PAGES = 500;

    public function __construct(
        public readonly TenantIntegration $integration,
        public readonly TelematicsFeed $feed,
        public readonly CarbonInterface $from,
        public readonly CarbonInterface $until,
    ) {
        $this->onQueue('telematics');
    }

    public function handle(ProviderAdapter $providerAdapter, IngestVehicleStatsPage $ingest): void
    {
        TenantContext::set($this->integration->team_id);

        $floor = $this->until->copy()->subHours((int) config('telematics.backfill_hours', 24));
        $from = $this->from->greaterThan($floor) ? $this->from : $floor;

        if ($from->greaterThanOrEqualTo($this->until)) {
            return;
        }

        $cursor = null;
        $pages = 0;
        $stored = 0;

        try {
            do {
                $page = $providerAdapter->fetchVehicleStatsHistory($this->integration, $this->feed, $from, $this->until, $cursor);
                $result = DB::transaction(fn () => $ingest->execute($this->integration, $page));

                $stored += $result->locationsStored + $result->readingsStored;
                $cursor = $page->endCursor;
                $pages++;
            } while ($page->hasNextPage && $cursor !== null && $pages < self::MAX_PAGES);
        } catch (ProviderRateLimited $e) {
            // Pages already stored stay stored; the retry re-reads the window
            // and the unique indexes skip what is there.
            $this->release((int) ceil(max(1.0, $e->retryAfterSeconds)));

            return;
        } catch (ProviderUnauthorized) {
            // The live feed opens the circuit for this; nothing to backfill.
            return;
        }

        SystemLog::ok('telematics.backfill.completed', input: ['integration_id' => $this->integration->id, 'feed' => $this->feed->value, 'from' => $from->toIso8601ZuluString(), 'until' => $this->until->toIso8601ZuluString()], result: ['pages' => $pages, 'stored' => $stored], channel: 'telematics');
    }

    /**
     * @return array<int, int>
     */
    public function backoff(): array
    {
        return [30, 120, 300, 900];
    }

    public function uniqueId(): string
    {
        return "telematics-backfill:{$this->integration->id}:{$this->feed->value}";
    }
}
