<?php

namespace App\Http\Requests\Concerns;

use App\Models\Team;

/**
 * Id del team de la ruta (`{current_team}`), bindeado o aún como slug, para
 * scopear reglas de validación (`exists`, membresía) al tenant de la request.
 */
trait ResolvesCurrentTeam
{
    protected function currentTeamId(): ?int
    {
        $team = $this->route('current_team');

        if ($team instanceof Team) {
            return $team->id;
        }

        return is_string($team)
            ? Team::query()->where('slug', $team)->value('id')
            : null;
    }
}
