<?php

namespace App\Concerns;

use App\Models\Team;
use App\Support\CurrentTeamResolver;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

trait BelongsToTenant
{
    public static function bootBelongsToTenant(): void
    {
        // currentTeamId() resuelve el tenant del TenantContext primero y del
        // usuario autenticado después, así que este scope también filtra en
        // colas, listeners y comandos — donde antes era un no-op. Ver §2.1.
        static::addGlobalScope('tenant', function (Builder $builder) {
            if ($teamId = currentTeamId()) {
                $builder->where($builder->getModel()->getTable().'.team_id', $teamId);
            } elseif (CurrentTeamResolver::shouldFailClosed()) {
                // Usuario autenticado sin team válido: fail closed, nunca un
                // query sin filtro de tenant.
                $builder->whereRaw('1 = 0');
            }
        });

        static::creating(function ($model) {
            if (! $model->team_id && $teamId = currentTeamId()) {
                $model->team_id = $teamId;
            }
        });
    }

    /**
     * @return BelongsTo<Team, $this>
     */
    public function team(): BelongsTo
    {
        return $this->belongsTo(Team::class, 'team_id');
    }
}
