<?php

namespace App\Domains\Analytics\Enums;

enum PeriodType: string
{
    case Hourly = 'hourly';
    case Daily = 'daily';
    case Weekly = 'weekly';
    case Monthly = 'monthly';
    case Custom = 'custom';
}
