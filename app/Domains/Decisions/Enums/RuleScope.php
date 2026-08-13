<?php

namespace App\Domains\Decisions\Enums;

use App\Contracts\HasLabel;

enum RuleScope: string implements HasLabel
{
    case Global = 'global';
    case Tenant = 'tenant';
    case EventType = 'event_type';
    case Category = 'category';
    case AssetType = 'asset_type';
    case OperationProfile = 'operation_profile';

    public function label(): string
    {
        return match ($this) {
            self::Global => 'Global',
            self::Tenant => 'Tenant',
            self::EventType => 'Tipo de evento',
            self::Category => 'Categoría',
            self::AssetType => 'Tipo de activo',
            self::OperationProfile => 'Perfil de operación',
        };
    }
}
