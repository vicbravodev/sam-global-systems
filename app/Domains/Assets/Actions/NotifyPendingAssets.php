<?php

namespace App\Domains\Assets\Actions;

use App\Domains\Assets\Models\Asset;
use App\Domains\Assets\Notifications\AssetsPendingMonitoringNotification;
use App\Domains\Tenancy\Actions\ResolveAssetLimit;
use App\Enums\TeamRole;
use App\Models\Team;
use App\Support\TenantContext;
use Illuminate\Support\Facades\Notification;

/**
 * Avisa a owner y admins del tenant que el sync dejó unidades nuevas sin
 * vigilar, con el cupo que les queda. Una sola vez por corrida de sync.
 */
class NotifyPendingAssets
{
    public function __construct(
        private ResolveAssetLimit $resolveAssetLimit,
    ) {}

    public function execute(int $teamId, int $newlyPending): void
    {
        TenantContext::for($teamId, function () use ($teamId, $newlyPending) {
            $team = Team::query()->find($teamId);

            if ($team === null || $newlyPending <= 0) {
                return;
            }

            $recipients = $team->members()
                ->wherePivotIn('role', [TeamRole::Owner->value, TeamRole::Admin->value])
                ->get();

            if ($recipients->isEmpty()) {
                return;
            }

            $monitored = Asset::query()->where('team_id', $teamId)->monitored()->count();
            $pending = Asset::query()->where('team_id', $teamId)->pendingMonitoring()->count();

            Notification::send(
                $recipients,
                new AssetsPendingMonitoringNotification(
                    team: $team,
                    newlyPending: $newlyPending,
                    totalPending: $pending,
                    monitored: $monitored,
                    cap: $this->resolveAssetLimit->execute($teamId),
                ),
            );
        });
    }
}
