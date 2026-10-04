<?php

namespace App\Domains\Drivers\Jobs;

use App\Domains\Drivers\Support\HosMonitoringConfig;
use App\Domains\Integrations\Enums\TenantIntegrationStatus;
use App\Domains\Integrations\Models\TenantIntegration;
use App\Domains\Tenancy\Models\TenantFeature;
use App\Domains\Tenancy\Support\TenantCanSend;
use App\Support\SystemLog;
use App\Support\TenantContext;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

/**
 * Scheduled every minute: fans out a {@see SyncHosClocksJob} for every active
 * Samsara integration whose tenant enabled the `hos_monitoring` feature and
 * can still be served ({@see TenantCanSend}).
 */
class PollHosClocksJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public function __construct()
    {
        $this->onQueue('telematics');
    }

    public function handle(): void
    {
        $counts = ['dispatched_count' => 0, 'feature_off_count' => 0, 'tenant_blocked_count' => 0, 'sync_disabled_count' => 0];

        // Fan-out de plataforma: recorre todos los tenants a propósito y mete
        // cada iteración en el contexto de SU tenant. Ver §2.1.
        TenantContext::withoutTenant(function () use (&$counts): void {
            $enabledTeams = TenantFeature::query()
                ->where('feature_key', HosMonitoringConfig::FEATURE_KEY)
                ->where('enabled', true)
                ->pluck('team_id')
                ->flip();

            TenantIntegration::query()
                ->where('status', TenantIntegrationStatus::Active)
                ->ofLiveTeam()
                ->with('provider')
                ->each(function (TenantIntegration $integration) use (&$counts, $enabledTeams): void {
                    if ($integration->provider?->code !== 'samsara') {
                        return;
                    }

                    if (! $enabledTeams->has($integration->team_id)) {
                        $counts['feature_off_count']++;

                        return;
                    }

                    TenantContext::for($integration->team_id, function () use ($integration, &$counts): void {
                        if (($integration->config_json['sync']['enabled'] ?? true) === false) {
                            $counts['sync_disabled_count']++;

                            return;
                        }

                        if (($blocked = TenantCanSend::blockedReason($integration->team_id)) !== null) {
                            SystemLog::skipped('hos.poll.skipped', reason: 'tenant_blocked', input: [
                                'team_id' => $integration->team_id,
                                'integration_id' => $integration->id,
                            ], calc: ['blocked_reason' => $blocked]);
                            $counts['tenant_blocked_count']++;

                            return;
                        }

                        SyncHosClocksJob::dispatch($integration);
                        $counts['dispatched_count']++;
                    });
                });
        });

        // Recorrido de plataforma: sólo conteos, nunca ids de un tenant.
        SystemLog::ok('hos.poll.dispatched', result: $counts);
    }
}
