<?php

namespace App\Domains\Analytics\Enums;

enum ReportType: string
{
    case Operational = 'operational';
    case Executive = 'executive';
    case Sla = 'sla';
    case AiPerformance = 'ai_performance';
    case IncidentAnalysis = 'incident_analysis';
    case AssetRisk = 'asset_risk';
    case Custom = 'custom';
}
