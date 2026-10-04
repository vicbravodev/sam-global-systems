<?php

namespace App\Domains\Drivers\Enums;

/** What the reminder ladder of an HOS episode does this minute. */
enum HosLadderMove: string
{
    /** Paused: the driver complied (not working) or there is no reading. */
    case Hold = 'hold';
    /** Nothing due yet. */
    case Wait = 'wait';
    case Notify = 'notify';
    case Escalate = 'escalate';
    /** Nothing left to send for this episode. */
    case Done = 'done';
}
