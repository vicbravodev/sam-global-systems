<?php

use App\Models\Team;
use App\Models\User;
use App\Support\CurrentTeamResolver;
use App\Support\TenantContext;

if (! function_exists('currentTeamId')) { // @codeCoverageIgnore
    /**
     * Id del tenant activo. Mira primero el TenantContext (que sí existe en
     * colas, listeners y comandos) y cae al usuario autenticado en HTTP.
     */
    function currentTeamId(): ?int
    {
        if (TenantContext::isSuppressed()) {
            return null;
        }

        if (($id = TenantContext::id()) !== null) {
            return $id;
        }

        $user = auth()->user();

        // current_team_id sólo vale si el usuario sigue siendo miembro de ese
        // team (o es super-admin). Ver CurrentTeamResolver.
        return $user instanceof User ? CurrentTeamResolver::idFor($user) : null;
    }
}

if (! function_exists('currentTeam')) { // @codeCoverageIgnore
    function currentTeam(): ?Team
    {
        $id = currentTeamId();

        if ($id === null) {
            return null;
        }

        return auth()->user()?->currentTeam?->id === $id
            ? auth()->user()->currentTeam
            : Team::find($id);
    }
}
