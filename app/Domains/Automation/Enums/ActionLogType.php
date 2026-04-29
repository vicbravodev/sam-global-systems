<?php

namespace App\Domains\Automation\Enums;

enum ActionLogType: string
{
    case Info = 'info';
    case Warning = 'warning';
    case Error = 'error';
    case Retry = 'retry';
    case ExternalResponse = 'external_response';
}
