<?php

namespace App\Domains\Tenancy\Actions;

use App\Models\Team;
use RuntimeException;

/**
 * Soft-deletes a tenant (Team uses SoftDeletes). Personal teams are never
 * deletable — they back the "every user has a personal team" invariant.
 */
class DeleteTenant
{
    public function execute(Team $team): void
    {
        if ($team->is_personal) {
            throw new RuntimeException('Personal teams cannot be deleted.');
        }

        $team->delete();
    }
}
