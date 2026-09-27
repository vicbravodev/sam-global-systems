<?php

namespace App\Domains\Normalization\Policies;

use App\Domains\Access\Actions\AuthorizeAction;
use App\Domains\Normalization\Models\EventMappingRule;
use App\Models\User;

/**
 * Las reglas de mapeo son un catálogo de PLATAFORMA: `event_mapping_rules`
 * no lleva team_id y MapExternalEventType las aplica a los eventos de todos
 * los tenants. Leerlas es inocuo (la página de reglas las muestra), pero
 * mutarlas reescribe la normalización de toda la plataforma — p.ej. remapear
 * el botón de pánico de Samsara — así que sólo el super-admin puede hacerlo.
 */
class EventMappingRulePolicy
{
    public function __construct(
        private AuthorizeAction $authorizeAction,
    ) {}

    public function viewAny(User $user): bool
    {
        $team = currentTeam();

        return $team && $this->authorizeAction->execute($user, 'decisions.view', $team);
    }

    public function create(User $user): bool
    {
        return $user->isSuperAdmin();
    }

    public function update(User $user, EventMappingRule $rule): bool
    {
        return $user->isSuperAdmin();
    }

    public function delete(User $user, EventMappingRule $rule): bool
    {
        return $user->isSuperAdmin();
    }
}
