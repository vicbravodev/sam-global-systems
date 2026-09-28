<?php

namespace App\Domains\Assets\Jobs;

use App\Domains\Assets\Actions\RecordAssetTelemetry;
use App\Domains\Assets\Actions\ResolveAssetsFromExternalIds;
use App\Domains\Assets\Enums\TelemetryType;
use App\Domains\Assets\Models\AssetTelemetrySnapshot;
use App\Domains\Integrations\Contracts\ProviderAdapter;
use App\Domains\Integrations\Models\TenantIntegration;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Carbon;

/**
 * Poll onboard-diagnostic readings for a single integration and persist the
 * ones that changed. Unknown assets (no external reference yet) are skipped —
 * the catalog sync creates them and the next poll picks up their telemetry.
 *
 * Runs every minute, and most readings hold steady between polls, so the job
 * decides "unchanged" in bulk: assets are resolved in two queries and the
 * newest stored reading of every (asset, type) pair is loaded in one, which
 * leaves only the readings that really changed to hit the write path.
 *
 * Unique per integration so overlapping ticks never double-poll the provider.
 */
class PollAssetTelemetryJob implements ShouldBeUnique, ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    /**
     * No retries: the next tick is a minute away and re-reads the latest
     * values; a delayed retry would only bring back older ones.
     */
    public int $tries = 1;

    public int $timeout = 120;

    /**
     * Releases the lock if a worker dies mid-poll, so a crash can never stall
     * the diagnostics of a whole tenant until someone clears the cache.
     */
    public int $uniqueFor = 180;

    public function __construct(
        public readonly TenantIntegration $integration,
    ) {
        $this->onQueue('sync');
    }

    public function handle(
        ProviderAdapter $providerAdapter,
        ResolveAssetsFromExternalIds $resolveAssets,
        RecordAssetTelemetry $recordTelemetry,
    ): void {
        // Stamped with the start of the poll so a slow poll does not push the
        // next one a whole tick further out.
        $startedAt = now();

        $readings = array_values(array_filter(
            $providerAdapter->fetchAssetTelemetry($this->integration),
            fn (array $reading): bool => (string) ($reading['external_id'] ?? '') !== ''
                && ($reading['type'] ?? null) instanceof TelemetryType,
        ));

        // The team is part of the lookup, so an external id that belongs to
        // another tenant resolves to nothing instead of leaking its asset here.
        $assets = $resolveAssets->execute(
            $this->integration->provider_id,
            array_map(fn (array $reading): string => (string) $reading['external_id'], $readings),
            $this->integration->team_id,
        );

        $latest = $this->latestReadings(array_map(fn ($asset) => $asset->id, array_values($assets)));

        foreach ($readings as $reading) {
            $asset = $assets[(string) $reading['external_id']] ?? null;

            if ($asset === null) {
                continue;
            }

            /** @var TelemetryType $type */
            $type = $reading['type'];
            $recordedAt = isset($reading['recorded_at']) && $reading['recorded_at'] !== null
                ? Carbon::parse($reading['recorded_at'])
                : now();

            if (! $recordTelemetry->supersedes($latest[$asset->id.'|'.$type->value] ?? null, $reading['value'], $recordedAt)) {
                continue;
            }

            $recordTelemetry->execute(
                asset: $asset,
                type: $type,
                value: $reading['value'],
                unit: $reading['unit'] ?? null,
                recordedAt: $recordedAt,
            );
        }

        $this->integration->update(['last_telemetry_poll_at' => $startedAt]);
    }

    /**
     * The newest stored reading of every (asset, type) pair of these assets,
     * keyed "assetId|type", in one query over the (asset_id, telemetry_type,
     * recorded_at) index.
     *
     * @param  list<int>  $assetIds
     * @return array<string, AssetTelemetrySnapshot>
     */
    private function latestReadings(array $assetIds): array
    {
        if ($assetIds === []) {
            return [];
        }

        $newest = AssetTelemetrySnapshot::query()
            ->selectRaw('asset_id, telemetry_type, MAX(recorded_at) AS newest_at')
            ->whereIn('asset_id', $assetIds)
            ->groupBy('asset_id', 'telemetry_type');

        $latest = [];

        // Ties on recorded_at resolve to the highest id, matching the order
        // RecordAssetTelemetry uses for its own "latest".
        AssetTelemetrySnapshot::query()
            ->joinSub($newest, 'newest', fn ($join) => $join
                ->on('asset_telemetry_snapshots.asset_id', '=', 'newest.asset_id')
                ->on('asset_telemetry_snapshots.telemetry_type', '=', 'newest.telemetry_type')
                ->on('asset_telemetry_snapshots.recorded_at', '=', 'newest.newest_at'))
            ->orderBy('asset_telemetry_snapshots.id')
            ->get(['asset_telemetry_snapshots.*'])
            ->each(function (AssetTelemetrySnapshot $snapshot) use (&$latest): void {
                $latest[$snapshot->asset_id.'|'.$snapshot->telemetry_type->value] = $snapshot;
            });

        return $latest;
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
        return "poll-telemetry-{$this->integration->id}";
    }
}
