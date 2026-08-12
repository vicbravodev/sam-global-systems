<?php

namespace App\Domains\Assets\Jobs;

use App\Domains\Assets\Actions\RecordAssetTelemetry;
use App\Domains\Assets\Actions\ResolveAssetFromExternalId;
use App\Domains\Assets\Enums\TelemetryType;
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
 * Unique per integration so overlapping ticks never double-poll the provider.
 */
class PollAssetTelemetryJob implements ShouldBeUnique, ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;

    /** @var array<int, int> */
    public array $backoff = [60, 300, 900];

    public int $timeout = 600;

    public function __construct(
        public readonly TenantIntegration $integration,
    ) {
        $this->onQueue('sync');
    }

    public function handle(
        ProviderAdapter $providerAdapter,
        ResolveAssetFromExternalId $resolveAsset,
        RecordAssetTelemetry $recordTelemetry,
    ): void {
        $readings = $providerAdapter->fetchAssetTelemetry($this->integration);

        foreach ($readings as $reading) {
            $externalId = $reading['external_id'] ?? null;
            $type = $reading['type'] ?? null;

            if ($externalId === null || $externalId === '' || ! $type instanceof TelemetryType) {
                continue;
            }

            $asset = $resolveAsset->execute($this->integration->provider_id, (string) $externalId);

            if ($asset === null) {
                continue;
            }

            // ResolveAssetFromExternalId looks up (provider, external_id)
            // across all tenants, so it can hand back another team's asset.
            // Provider-side ids are unique in practice, but tenant isolation
            // must not rest on an external system's id allocation.
            if ($asset->team_id !== $this->integration->team_id) {
                continue;
            }

            $recordTelemetry->execute(
                asset: $asset,
                type: $type,
                value: $reading['value'],
                unit: $reading['unit'] ?? null,
                recordedAt: isset($reading['recorded_at']) && $reading['recorded_at'] !== null
                    ? Carbon::parse($reading['recorded_at'])
                    : null,
            );
        }

        $this->integration->update(['last_telemetry_poll_at' => now()]);
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
