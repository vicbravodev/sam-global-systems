<?php

namespace App\Domains\Integrations\Enums;

enum IntegrationProviderType: string
{
    case Telematics = 'telematics';
    case Video = 'video';
    case Iot = 'iot';
    case Api = 'api';
}
