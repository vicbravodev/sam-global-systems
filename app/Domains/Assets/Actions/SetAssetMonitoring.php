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
use App\Domains\Tenancy\Support\TenantCanSend;
use App\Models\User;
use App\Support\SystemLog;
use App\Support\TenantContext;
use Illuminate\Support\Facades\DB;

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
        private RecordMonitoredAssetDay $recordAssetDay,
    ) {}

    /**
     * @return array{asset: Asset, changed: bool, over_cap: bool, monitored: int, cap: int|null}
     */
    public function execute(Asset $asset, AssetMonitoringState $state, ?User $actor = null, ?string $reason = null): array
    {
        return TenantContext::for((int) $asset->team_id, function () use ($asset, $state, $actor, $reason) {
            $teamId = (int) $asset->team_id;
            $limit = $this->resolveAssetLimit->explain($teamId);
            $cap = $limit['cap'];
            $this->logAssetLimit($teamId, $limit);
            $monitored = $this->monitoredCount($teamId);
            $blocked = TenantCanSend::blockedReason($teamId);
            $billable = $blocked === null;

            if ($blocked !== null && $state === AssetMonitoringState::Monitored && $asset->monitoring_state !== $state) {
                $this->logTenantBlocked($teamId, $blocked);
            }

            return $this->apply($asset, $state, $actor, $reason, $cap, $monitored, $billable);
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
            $limit = $this->resolveAssetLimit->explain($teamId);
            $cap = $limit['cap'];
            $this->logAssetLimit($teamId, $limit);
            $monitored = $this->monitoredCount($teamId);
            $blocked = TenantCanSend::blockedReason($teamId);
            $billable = $blocked === null;

            if ($blocked !== null && $state === AssetMonitoringState::Monitored) {
                $this->logTenantBlocked($teamId, $blocked);
            }

            $changed = 0;
            $last = null;

            foreach ($assets as $asset) {
                // Only this tenant's assets: callers already filter, this is
                // the last line (§2.1).
                if ((int) $asset->team_id !== $teamId) {
                    // Nunca el id del activo ajeno ni su tenant.
                    SystemLog::skipped('assets.monitoring.changed', reason: 'other_tenant', input: ['team_id' => $teamId], calc: ['team_matches' => false]);

                    continue;
                }

                $last = $this->apply($asset, $state, $actor, $reason, $cap, $monitored, $billable);
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
    private function apply(Asset $asset, AssetMonitoringState $state, ?User $actor, ?string $reason, ?int $cap, int &$monitored, bool $billable): array
    {
        $previous = $asset->monitoring_state;
        $monitoredBefore = $monitored;

        if ($previous === $state) {
            SystemLog::skipped('assets.monitoring.changed', reason: 'same_state', input: ['team_id' => (int) $asset->team_id, 'asset_id' => $asset->id], calc: ['state' => $state->value]);

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

        $assetDayOutcome = null;

        if ($state === AssetMonitoringState::Monitored) {
            $monitored++;

            // Encender ES usar: el tracto-día de hoy queda registrado en este
            // momento, no hasta la muestra nocturna (decisión 2026-09-28).
            $assetDayOutcome = $this->recordAssetDay->outcome($asset, tenantBillable: $billable);
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

        // Hecho persistido: solo si la transacción del llamador confirma. Nunca
        // `$reason` (texto libre), el email del actor ni el nombre del activo.
        $logInput = ['team_id' => (int) $asset->team_id, 'asset_id' => $asset->id, 'actor_user_id' => $actor?->id];
        $logCalc = [
            'previous_state' => $previous->value,
            'new_state' => $state->value,
            'assets_monitored_before' => $monitoredBefore,
            'assets_monitored_after' => $monitored,
            'cap' => $cap,
            'over_cap' => $overCap,
            'overage_assets' => $cap === null ? 0 : max(0, $monitored - $cap),
            'tenant_billable' => $billable,
            'asset_day_outcome' => $assetDayOutcome,
            'reason_present' => $reason !== null && $reason !== '',
        ];

        DB::afterCommit(fn () => SystemLog::ok(
            'assets.monitoring.changed',
            input: $logInput,
            calc: $logCalc,
            result: ['changed' => true, 'limit_event_dispatched' => $overCap],
        ));

        return [
            'asset' => $asset,
            'changed' => true,
            'over_cap' => $overCap,
            'monitored' => $monitored,
            'cap' => $cap,
        ];
    }

    /**
     * @param  array{cap: ?int, source: string, calc: array<string, mixed>}  $limit
     */
    private function logAssetLimit(int $teamId, array $limit): void
    {
        $input = ['team_id' => $teamId, 'stage' => 'monitoring_toggle'];
        $calc = ['source' => $limit['source'], ...$limit['calc']];
        $result = ['cap' => $limit['cap']];

        if (($limit['calc']['none_reason'] ?? null) === 'meter_missing') {
            SystemLog::degraded('billing.asset_limit.resolved', reason: 'meter_missing', input: $input, calc: $calc, result: $result);

            return;
        }

        SystemLog::ok('billing.asset_limit.resolved', input: $input, calc: $calc, result: $result);
    }

    private function logTenantBlocked(int $teamId, string $blocked): void
    {
        SystemLog::skipped('billing.tenant.blocked', reason: $blocked, input: ['team_id' => $teamId, 'stage' => 'monitoring_toggle']);
    }

    private function monitoredCount(int $teamId): int
    {
        return Asset::query()
            ->where('team_id', $teamId)
            ->monitored()
            ->count();
    }
}
