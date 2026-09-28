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
 * Scheduled orchestrator: fans out a {@see PollAssetTelemetryJob} for every
 * active integration due for a diagnostics refresh.
 *
 * Mirrors {@see PollAllAssetLocationsJob}, on the same one-minute default: an
 * ignition change or a fuel drop is operational signal, and unchanged readings
 * are dropped before they reach the database, so the faster cadence costs two
 * provider requests per minute rather than rows.
 */
class PollAllAssetTelemetryJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public const DEFAULT_INTERVAL_MINUTES = 1;

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
                if ($this->isDue($integration)) {
                    PollAssetTelemetryJob::dispatch($integration);
                }
            })));
    }

    private function isDue(TenantIntegration $integration): bool
    {
        $sync = $integration->config_json['sync'] ?? [];

        if (($sync['enabled'] ?? true) === false) {
            return false;
        }

        if (($sync['poll_telemetry'] ?? true) === false) {
            return false;
        }

        $interval = max(1, (int) ($sync['telemetry_interval_minutes'] ?? self::DEFAULT_INTERVAL_MINUTES));
        $lastPoll = $integration->last_telemetry_poll_at;

        return $lastPoll === null
            || $lastPoll->lte(now()->subSeconds($interval * 60 - PollAllAssetLocationsJob::DUE_GRACE_SECONDS));
    }
}
