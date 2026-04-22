<?php

namespace App\Concerns;

use App\Models\Team;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

trait BelongsToTenant
{
    public static function bootBelongsToTenant(): void
    {
        static::addGlobalScope('tenant', function (Builder $builder) {
            if ($team = currentTeam()) {
                $builder->where($builder->getModel()->getTable().'.team_id', $team->id);
            }
        });

        static::creating(function ($model) {
            if (! $model->team_id && $team = currentTeam()) {
                $model->team_id = $team->id;
            }
        });
    }

    public function team(): BelongsTo
    {
        return $this->belongsTo(Team::class, 'team_id');
    }
}
