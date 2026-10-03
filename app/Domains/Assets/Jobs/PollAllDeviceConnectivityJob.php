<?php

namespace App\Domains\Assets\Jobs;

use App\Domains\Integrations\Enums\TenantIntegrationStatus;
use App\Domains\Integrations\Models\TenantIntegration;
use App\Domains\Tenancy\Support\TenantCanSend;
use App\Support\SystemLog;
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
 *
 * Tenants whose subscription blocks them ({@see TenantCanSend}: suspended,
 * canceled, expired) are not polled: they are no longer billed, and the only
 * consumer is the "offline while moving" watchdog, which is not an emergency.
 * Panics, collisions and rollovers keep flowing through the webhook and the
 * alert-incidents backup poller, which are not gated here.
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
        $dispatched = 0;
        $syncDisabled = 0;
        $tenantBlocked = 0;

        // Fan-out de plataforma: recorre todos los tenants a propósito, y
        // mete cada iteración en el contexto de SU tenant. Ver §2.1.
        TenantContext::withoutTenant(function () use (&$dispatched, &$syncDisabled, &$tenantBlocked): void {
            TenantIntegration::query()
                ->where('status', TenantIntegrationStatus::Active)
                ->ofLiveTeam()
                ->with('provider')
                ->each(function (TenantIntegration $integration) use (&$dispatched, &$syncDisabled, &$tenantBlocked): void {
                    TenantContext::for($integration->team_id, function () use ($integration, &$dispatched, &$syncDisabled, &$tenantBlocked): void {
                        if (($integration->config_json['sync']['enabled'] ?? true) === false) {
                            $syncDisabled++;

                            return;
                        }

                        if (($blocked = TenantCanSend::blockedReason($integration->team_id)) !== null) {
                            SystemLog::skipped(
                                'assets.connectivity.skipped',
                                reason: 'tenant_blocked',
                                input: ['team_id' => $integration->team_id, 'integration_id' => $integration->id],
                                calc: ['blocked_reason' => $blocked],
                                result: ['dispatched' => false],
                            );
                            $tenantBlocked++;

                            return;
                        }

                        PollAssetConnectivityJob::dispatch($integration);
                        $dispatched++;
                    });
                });
        });

        // Recorrido de plataforma: sólo conteos, nunca ids de un tenant.
        SystemLog::ok('assets.connectivity.dispatched', result: [
            'dispatched_count' => $dispatched,
            'sync_disabled_count' => $syncDisabled,
            'tenant_blocked_count' => $tenantBlocked,
        ]);
    }
}
