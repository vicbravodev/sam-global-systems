<?php

namespace App\Domains\Assets\Jobs;

use App\Domains\Integrations\Enums\TenantIntegrationStatus;
use App\Domains\Integrations\Models\TenantIntegration;
use App\Support\TenantContext;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

/**
 * Scheduled orchestrator: fans out a {@see PollAssetConnectivityJob} for every
 * active integration, on the cadence of its only reader, the offline
 * watchdog ({@see DetectOfflineAssetsJob}). Positions and diagnostics no
 * longer ride here: they arrive through the telematics feed.
 */
class PollAllDeviceConnectivityJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public function __construct()
    {
        $this->onQueue('sync');
    }

    public function handle(): void
    {
        // Fan-out de plataforma: recorre todos los tenants a propósito, y
        // mete cada iteración en el contexto de SU tenant. Ver §2.1.
        TenantContext::withoutTenant(fn () => TenantIntegration::query()
            ->where('status', TenantIntegrationStatus::Active)
            ->ofLiveTeam()
            ->with('provider')
            ->each(fn (TenantIntegration $integration) => TenantContext::for($integration->team_id, function () use ($integration): void {
                if (($integration->config_json['sync']['enabled'] ?? true) !== false) {
                    PollAssetConnectivityJob::dispatch($integration);
                }
            })));
    }
}
