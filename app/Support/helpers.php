<?php

use App\Models\Team;

if (! function_exists('currentTeam')) {
    function currentTeam(): ?Team
    {
        return auth()->user()?->currentTeam;
    }
}
