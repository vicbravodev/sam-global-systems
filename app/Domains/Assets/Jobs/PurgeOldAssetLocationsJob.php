<?php

namespace App\Domains\Assets\Jobs;

use App\Domains\Assets\Models\AssetLocationSnapshot;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

/**
 * Retention for raw GPS points (`telematics.retention.location_days`, 30 by
 * default). The feed stores every point a vehicle reports, so without this
 * the table grows by millions of rows a month.
 *
 * Nothing that needs older points depends on this table: every reader looks
 * at most 24 h back, the current position lives on the asset row, and an
 * incident's trail is frozen into its evidence long before the cutoff.
 *
 * Deletes in chunks over the `recorded_at` index so a large backlog never
 * holds one long transaction.
 */
class PurgeOldAssetLocationsJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    private const CHUNK = 5000;

    public function __construct(
        public readonly ?int $retentionDays = null,
    ) {
        $this->onQueue('sync');
    }

    public function handle(): int
    {
        $cutoff = now()->subDays($this->retentionDays ?? (int) config('telematics.retention.location_days', 30));
        $deleted = 0;

        do {
            $ids = AssetLocationSnapshot::query()
                ->where('recorded_at', '<', $cutoff)
                ->limit(self::CHUNK)
                ->pluck('id');

            $removed = $ids->isEmpty() ? 0 : AssetLocationSnapshot::query()->whereKey($ids->all())->delete();
            $deleted += $removed;
        } while ($removed > 0);

        return $deleted;
    }
}
