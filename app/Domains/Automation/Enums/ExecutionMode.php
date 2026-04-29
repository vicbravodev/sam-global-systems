<?php

namespace App\Domains\Automation\Enums;

enum ExecutionMode: string
{
    case Sync = 'sync';
    case Async = 'async';
    case Deferred = 'deferred';
    case RequiresConfirmation = 'requires_confirmation';
}
