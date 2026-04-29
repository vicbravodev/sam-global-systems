<?php

namespace App\Domains\Decisions\Enums;

enum RuleScope: string
{
    case Global = 'global';
    case Tenant = 'tenant';
    case EventType = 'event_type';
    case Category = 'category';
    case AssetType = 'asset_type';
    case OperationProfile = 'operation_profile';
}
