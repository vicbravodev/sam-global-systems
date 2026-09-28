<?php

namespace App\Domains\Assets\Actions;

use App\Domains\Assets\Enums\AssetMonitoringState;
use App\Domains\Assets\Events\AssetMonitoringChanged;
use App\Domains\Assets\Events\AssetMonitoringChangedBroadcast;
use App\Domains\Assets\Models\Asset;
use App\Domains\Audit\Actions\RecordAuditEntry;
use App\Domains\Audit\Enums\AuditActorType;
use App\Domains\Audit\Enums\AuditCategory;
use App\Domains\Tenancy\Actions\ResolveAssetLimit;
use App\Domains\Tenancy\Events\UsageLimitExceeded;
use App\Models\User;
use App\Support\TenantContext;

/**
 * Enciende o apaga la vigilancia de un activo. Es la ÚNICA puerta por la que
 * un activo entra al pipeline (sondeo, normalización, IA, alertas) y a la
 * factura (tracto-día).
 *
 * Tope suave (decisión 2026-09-28): encender más allá del cupo contratado
 * se permite; se avisa (`UsageLimitExceeded`), queda en auditoría y se
 * factura como extra por cada día encendido. Nunca se bloquea al cliente.
 */
class SetAssetMonitoring
{
    public function __construct(
        private ResolveAssetLimit $resolveAssetLimit,
        private RecordAuditEntry $audit,
    ) {}

    /**
     * @return array{asset: Asset, changed: bool, over_cap: bool, monitored: int, cap: int|null}
     */
    public function execute(Asset $asset, AssetMonitoringState $state, ?User $actor = null, ?string $reason = null): array
    {
        return TenantContext::for((int) $asset->team_id, function () use ($asset, $state, $actor, $reason) {
            $cap = $this->resolveAssetLimit->execute((int) $asset->team_id);
            $monitored = $this->monitoredCount((int) $asset->team_id);

            return $this->apply($asset, $state, $actor, $reason, $cap, $monitored);
        });
    }

    /**
     * Same as execute() for a batch of one tenant's assets (bulk toggle, up
     * to 500): the cap is resolved and the monitored count taken once, then
     * kept in memory, instead of 5–7 queries per asset for values that do
     * not change inside the batch. Audit, events and broadcasts stay per
     * asset.
     *
     * @param  iterable<Asset>  $assets
     * @return array{changed: int, over_cap: bool, monitored: int, cap: int|null}
     */
    public function executeMany(int $teamId, iterable $assets, AssetMonitoringState $state, ?User $actor = null, ?string $reason = null): array
    {
        return TenantContext::for($teamId, function () use ($teamId, $assets, $state, $actor, $reason) {
            $cap = $this->resolveAssetLimit->execute($teamId);
            $monitored = $this->monitoredCount($teamId);
            $changed = 0;
            $last = null;

            foreach ($assets as $asset) {
                // Only this tenant's assets: callers already filter, this is
                // the last line (§2.1).
                if ((int) $asset->team_id !== $teamId) {
                    continue;
                }

                $last = $this->apply($asset, $state, $actor, $reason, $cap, $monitored);
                $changed += $last['changed'] ? 1 : 0;
            }

            return [
                'changed' => $changed,
                // As the single toggle reports it: the state after the last one.
                'over_cap' => $last !== null && $last['over_cap'],
                'monitored' => $monitored,
                'cap' => $cap,
            ];
        });
    }

    /**
     * @param  int  $monitored  the tenant's monitored count before this change; updated in place
     * @return array{asset: Asset, changed: bool, over_cap: bool, monitored: int, cap: int|null}
     */
    private function apply(Asset $asset, AssetMonitoringState $state, ?User $actor, ?string $reason, ?int $cap, int &$monitored): array
    {
        $previous = $asset->monitoring_state;

        if ($previous === $state) {
            return [
                'asset' => $asset,
                'changed' => false,
                'over_cap' => false,
                'monitored' => $monitored,
                'cap' => $cap,
            ];
        }

        $asset->forceFill([
            'monitoring_state' => $state,
            'monitoring_changed_at' => now(),
        ])->save();

        if ($previous === AssetMonitoringState::Monitored) {
            $monitored--;
        }

        if ($state === AssetMonitoringState::Monitored) {
            $monitored++;
        }

        $overCap = $state === AssetMonitoringState::Monitored
            && $cap !== null
            && $monitored > $cap;

        if ($overCap) {
            UsageLimitExceeded::dispatch((int) $asset->team_id, ResolveAssetLimit::METER_CODE, $monitored, $cap);
        }

        $this->audit->execute(
            actorType: $actor ? AuditActorType::User : AuditActorType::System,
            actorId: $actor?->id,
            action: 'asset.monitoring_changed',
            category: AuditCategory::Billing,
            entityType: Asset::class,
            entityId: (int) $asset->id,
            summary: sprintf(
                'Unidad %s: %s → %s%s.',
                $asset->name,
                $previous->label(),
                $state->label(),
                $overCap ? " (por encima del tope de {$cap}, se cobra como extra)" : '',
            ),
            teamId: (int) $asset->team_id,
            metadata: [
                'previous_state' => $previous->value,
                'new_state' => $state->value,
                'monitored' => $monitored,
                'cap' => $cap,
                'over_cap' => $overCap,
                'reason' => $reason,
                'actor_email' => $actor?->email,
            ],
            signature: "asset.monitoring_changed:{$asset->id}:{$state->value}:".now()->valueOf(),
        );

        AssetMonitoringChanged::dispatch(
            (int) $asset->team_id,
            (int) $asset->id,
            $previous->value,
            $state->value,
            $actor?->id,
            $overCap,
        );

        broadcast(new AssetMonitoringChangedBroadcast(
            (int) $asset->team_id,
            (int) $asset->id,
            (string) $asset->name,
            $previous->value,
            $state->value,
        ));

        return [
            'asset' => $asset,
            'changed' => true,
            'over_cap' => $overCap,
            'monitored' => $monitored,
            'cap' => $cap,
        ];
    }

    private function monitoredCount(int $teamId): int
    {
        return Asset::query()
            ->where('team_id', $teamId)
            ->monitored()
            ->count();
    }
}
