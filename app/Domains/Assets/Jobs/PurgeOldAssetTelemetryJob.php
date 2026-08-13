<?php

namespace App\Domains\Assets\Jobs;

use App\Domains\Assets\Models\AssetTelemetrySnapshot;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

/**
 * Enforce the telemetry retention window.
 *
 * Even with write-if-changed dedup, a moving fleet produces readings on every
 * poll, so the table only ever grows. The detail panel shows the latest value
 * and the history endpoint serves recent trends; neither needs data older than
 * the retention window.
 *
 * Deletes in chunks so a large backlog never holds a single long transaction.
 */
class PurgeOldAssetTelemetryJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public const RETENTION_DAYS = 90;

    private const CHUNK = 1000;

    public function __construct(
        public readonly ?int $retentionDays = null,
    ) {
        $this->onQueue('sync');
    }

    public function handle(): int
    {
        $cutoff = now()->subDays($this->retentionDays ?? self::RETENTION_DAYS);
        $deleted = 0;

        do {
            $removed = AssetTelemetrySnapshot::query()
                ->where('recorded_at', '<', $cutoff)
                ->limit(self::CHUNK)
                ->delete();

            $deleted += $removed;
        } while ($removed > 0);

        return $deleted;
    }
}
