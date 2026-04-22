<?php

namespace App\Domains\Integrations\Enums;

enum SyncType: string
{
    case Full = 'full';
    case Incremental = 'incremental';
    case Realtime = 'realtime';
}
