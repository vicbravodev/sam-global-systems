<?php

namespace App\Domains\Integrations\Enums;

enum AuthType: string
{
    case ApiKey = 'api_key';
    case Oauth2 = 'oauth2';
    case BasicAuth = 'basic_auth';
    case Token = 'token';
}
