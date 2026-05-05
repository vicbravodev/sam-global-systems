<?php

namespace App\Domains\Context\Enums;

enum MediaRetrievalStatus: string
{
    case NotRequested = 'not_requested';
    case Requested = 'requested';
    case Processing = 'processing';
    case Ready = 'ready';
    case Failed = 'failed';
}
