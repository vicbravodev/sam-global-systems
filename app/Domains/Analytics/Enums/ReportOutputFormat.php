<?php

namespace App\Domains\Analytics\Enums;

enum ReportOutputFormat: string
{
    case Dashboard = 'dashboard';
    case Pdf = 'pdf';
    case Csv = 'csv';
    case Xlsx = 'xlsx';
    case Json = 'json';
}
