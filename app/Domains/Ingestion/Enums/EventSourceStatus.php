<?php

namespace App\Domains\Ingestion\Enums;

enum EventSourceStatus: string
{
    case Active = 'active';
    case Inactive = 'inactive';
}
