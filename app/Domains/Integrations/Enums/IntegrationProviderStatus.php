<?php

namespace App\Domains\Integrations\Enums;

enum IntegrationProviderStatus: string
{
    case Active = 'active';
    case Inactive = 'inactive';
    case Deprecated = 'deprecated';
}
