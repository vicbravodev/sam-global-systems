<?php

namespace App\Domains\Assets\Jobs;

use App\Domains\Assets\Enums\TelematicsFeed;
use App\Domains\Assets\Models\TelematicsFeedCursor;
use App\Domains\Integrations\Enums\TenantIntegrationStatus;
use App\Domains\Integrations\Models\TenantIntegration;
use App\Support\TenantContext;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

/**
 * The telematics heartbeat, scheduled every five seconds: for every active
 * integration with a stats feed, queue one {@see FollowVehicleStatsFeedJob}
 * per feed that is due and not paused.
 *
 * It only decides and dispatches — a few milliseconds — so the tick runs
 * inline in the scheduler process (routes/console.php), not on a queue. The work, and every failure, happens per tenant
 * in its own job on the `telematics` queue, where one slow or broken tenant
 * holds at most one worker.
 *
 * An integration joins only after its first catalog sync: the feed's opening
 * page carries the last known state of every vehicle, and a vehicle without
 * an asset yet would lose that page for good once the cursor moves past it.
 */
class DispatchTelematicsFeedsJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    /**
     * The scheduler tick. A cycle stamps `last_polled_at` when it starts, which
     * is up to one tick after its dispatch (jitter + queue pickup), so the due
     * check allows one full tick of slack; otherwise a 5 s interval would
     * silently become 10. Overlap is prevented by the job's unique lock, not
     * by this check.
     */
    public const TICK_SECONDS = 5;

    /** Providers that expose a stats feed. */
    private const FEED_PROVIDERS = ['samsara'];

    public function __construct()
    {
        $this->onQueue('telematics');
    }

    public function handle(): void
    {
        // Fan-out de plataforma: recorre todos los tenants a propósito, y
        // mete cada iteración en el contexto de SU tenant para que lo que se
        // despache viaje ya scopeado. Ver §2.1.
        TenantContext::withoutTenant(function (): void {
            $integrations = TenantIntegration::query()
                ->where('status', TenantIntegrationStatus::Active)
                ->whereNotNull('last_sync_at')
                ->ofLiveTeam()
                ->whereHas('provider', fn ($query) => $query->whereIn('code', self::FEED_PROVIDERS))
                ->with('provider')
                ->get();

            if ($integrations->isEmpty()) {
                return;
            }

            // One query for every cursor, keyed "integration|feed".
            $cursors = TelematicsFeedCursor::query()
                ->whereIn('tenant_integration_id', $integrations->modelKeys())
                ->get()
                ->keyBy(fn (TelematicsFeedCursor $cursor) => $cursor->tenant_integration_id.'|'.$cursor->feed->value);

            foreach ($integrations as $integration) {
                TenantContext::for($integration->team_id, function () use ($integration, $cursors): void {
                    if (! $this->feedEnabled($integration)) {
                        return;
                    }

                    foreach (TelematicsFeed::cases() as $feed) {
                        $cursor = $cursors->get($integration->id.'|'.$feed->value);

                        if ($this->isDue($integration, $feed, $cursor)) {
                            FollowVehicleStatsFeedJob::dispatch($integration, $feed)
                                ->delay($this->jitter($integration, $feed));
                        }
                    }
                });
            }
        });
    }

    private function feedEnabled(TenantIntegration $integration): bool
    {
        $sync = $integration->config_json['sync'] ?? [];

        return ($sync['enabled'] ?? true) !== false
            && ($sync['feed_enabled'] ?? true) !== false;
    }

    private function isDue(TenantIntegration $integration, TelematicsFeed $feed, ?TelematicsFeedCursor $cursor): bool
    {
        if ($cursor === null || $cursor->last_polled_at === null) {
            return true;
        }

        if ($cursor->isPaused()) {
            return false;
        }

        $interval = $this->intervalSeconds($integration, $feed);

        return $cursor->last_polled_at->lte(now()->subSeconds($interval - self::TICK_SECONDS));
    }

    /**
     * The motion feed can be slowed per tenant; nothing goes below the
     * provider's 5-second floor.
     */
    private function intervalSeconds(TenantIntegration $integration, TelematicsFeed $feed): int
    {
        $override = $feed === TelematicsFeed::Motion
            ? ($integration->config_json['sync']['feed_interval_seconds'] ?? null)
            : null;

        return max(5, (int) ($override ?? $feed->intervalSeconds()));
    }

    /**
     * A stable per-tenant offset inside the tick, so tenants do not all hit
     * the provider and our workers in the same second. Each tenant keeps its
     * own slot, so its cadence stays regular.
     */
    private function jitter(TenantIntegration $integration, TelematicsFeed $feed): int
    {
        return crc32($integration->team_id.'|'.$feed->value) % self::TICK_SECONDS;
    }
}
