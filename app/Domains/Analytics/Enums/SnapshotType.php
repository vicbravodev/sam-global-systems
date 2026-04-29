<?php

namespace App\Domains\Analytics\Enums;

enum SnapshotType: string
{
    case TenantOverview = 'tenant_overview';
    case OperationalSummary = 'operational_summary';
    case AiPerformance = 'ai_performance';
    case AssetRiskProfile = 'asset_risk_profile';
    case OperatorPerformance = 'operator_performance';
    case ZoneAnalysis = 'zone_analysis';
}
