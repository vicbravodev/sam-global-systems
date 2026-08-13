<?php

use App\Models\Team;
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

        return TenantContext::id() ?? auth()->user()?->currentTeam?->id;
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
