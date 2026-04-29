<?php

namespace App\Domains\Analytics\Enums;

enum MetricAggregationType: string
{
    case Sum = 'sum';
    case Avg = 'avg';
    case Max = 'max';
    case Min = 'min';
    case Count = 'count';
    case Rate = 'rate';
}
