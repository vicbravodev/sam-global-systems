<?php

namespace App\Domains\Integrations\Jobs;

use App\Domains\Integrations\Enums\SyncStatus;
use App\Domains\Integrations\Enums\SyncType;
use App\Domains\Integrations\Enums\TenantIntegrationStatus;
use App\Domains\Integrations\Models\IntegrationSyncJob;
use App\Domains\Integrations\Models\TenantIntegration;
use App\Support\TenantContext;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

/**
 * Scheduled orchestrator: dispatches an incremental catalog sync for every
 * active integration that is due, across all tenants (global scope bypassed).
 *
 * Due-ness is gated against last_sync_at using a per-integration interval from
 * config_json.sync, and integrations with an in-flight sync are skipped so a
 * fast scheduler tick never stacks redundant syncs or orphans tracking rows.
 */
class SyncDueIntegrationsJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public const DEFAULT_INTERVAL_MINUTES = 30;

    public const STALE_SYNC_MINUTES = 120;

    public function __construct()
    {
        $this->onQueue('sync');
    }

    public function handle(): void
    {
        // Fan-out de plataforma: recorre todos los tenants a propósito, y
        // mete cada iteración en el contexto de SU tenant para que todo lo
        // que se despache desde ahí viaje ya scopeado. Ver §2.1.
        TenantContext::withoutTenant(fn () => TenantIntegration::query()
            ->where('status', TenantIntegrationStatus::Active)
            ->ofLiveTeam()
            ->with('provider')
            ->each(fn (TenantIntegration $integration) => TenantContext::for($integration->team_id, function () use ($integration): void {
                if (! $this->isDue($integration)) {
                    return;
                }

                if ($this->hasInFlightSync($integration)) {
                    return;
                }

                SyncIntegrationJob::dispatchUnlessInFlight($integration, SyncType::Incremental);
            })));
    }

    private function isDue(TenantIntegration $integration): bool
    {
        $sync = $integration->config_json['sync'] ?? [];

        if (($sync['enabled'] ?? true) === false) {
            return false;
        }

        $interval = max(1, (int) ($sync['catalog_interval_minutes'] ?? self::DEFAULT_INTERVAL_MINUTES));
        $lastSync = $integration->last_sync_at;

        return $lastSync === null || $lastSync->lte(now()->subMinutes($interval));
    }

    /**
     * A row left `pending`/`running` by a worker that was killed (OOM,
     * deploy, SIGKILL on timeout) never reaches `failed()`; past the longest
     * a sync can legitimately take (3 tries × 1800 s + backoff) it no longer
     * counts as in flight, or the integration would never sync again.
     */
    private function hasInFlightSync(TenantIntegration $integration): bool
    {
        return IntegrationSyncJob::where('tenant_integration_id', $integration->id)
            ->whereIn('status', [SyncStatus::Pending, SyncStatus::Running])
            ->where('updated_at', '>=', now()->subMinutes(self::STALE_SYNC_MINUTES))
            ->exists();
    }
}
