<?php

namespace App\Domains\Drivers\Policies;

use App\Domains\Access\Actions\AuthorizeAction;
use App\Domains\Drivers\Support\HosMonitoringConfig;
use App\Models\User;

/**
 * Monitoreo HOS (EE. UU.): todo exige la feature `hos_monitoring`
 * encendida EXPLÍCITAMENTE para el team actual (la enciende el
 * super-admin) y, encima, el permiso existente de cada superficie:
 * panel = `drivers.view`, leer la configuración = `config.view`,
 * guardarla = `config.manage`. Registrada sobre `HosDriverState`.
 */
class HosMonitoringPolicy
{
    public function __construct(
        private readonly AuthorizeAction $authorizeAction,
    ) {}

    public function viewAny(User $user): bool
    {
        return $this->allowed($user, 'drivers.view');
    }

    public function viewConfig(User $user): bool
    {
        return $this->allowed($user, 'config.view');
    }

    public function updateConfig(User $user): bool
    {
        return $this->allowed($user, 'config.manage');
    }

    private function allowed(User $user, string $permission): bool
    {
        $team = currentTeam();

        return $team !== null
            && $this->authorizeAction->isFeatureEnabled($team, HosMonitoringConfig::FEATURE_KEY)
            && $this->authorizeAction->execute($user, $permission, $team);
    }
}
