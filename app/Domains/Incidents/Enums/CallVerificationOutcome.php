<?php

namespace App\Domains\Incidents\Enums;

enum CallVerificationOutcome: string
{
    case ConfirmedReal = 'confirmed_real';
    case ConfirmedFalse = 'confirmed_false';
    case NoAnswer = 'no_answer';
}
