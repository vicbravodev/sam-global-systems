<?php

namespace App\Domains\AI\Enums;

enum ReevaluationStatus: string
{
    case Pending = 'pending';
    case Processing = 'processing';
    case Completed = 'completed';
    case Skipped = 'skipped';
}
