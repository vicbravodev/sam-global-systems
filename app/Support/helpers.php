<?php

use App\Models\Team;

if (! function_exists('currentTeam')) { // @codeCoverageIgnore
    function currentTeam(): ?Team
    {
        return auth()->user()?->currentTeam;
    }
}
